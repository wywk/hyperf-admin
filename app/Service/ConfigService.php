<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Service;

use App\Common\ApiCode;
use App\Exception\BusinessException;
use App\Model\Config;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Redis\Redis;
use Throwable;

use function Hyperf\Config\config;

/**
 * 系统配置：数据库存值 + Redis 缓存，按 type 自动转成 PHP 类型。
 *
 * 设计取舍：
 * - 全量配置一次性缓存成一个 JSON，读多写少，改一次就整体失效，逻辑简单不容易出现脏读
 * - 只允许修改已存在的键（键由 seeder 定义），避免后台随手加一个没人消费的孤儿配置
 * - 缓存不可用时直接查库，配置读取不能因为 Redis 挂了而失败
 */
class ConfigService
{
    private const CACHE_KEY = 'sys:config:all';

    private const CACHE_TTL = 300;

    /** 分组元信息：顺序 + 中文名（前端 tab 用） */
    private const GROUPS = [
        'basic' => ['name' => '基础信息', 'sort' => 10],
        'security' => ['name' => '安全策略', 'sort' => 20],
        'audit' => ['name' => '审计与日志', 'sort' => 30],
    ];

    public function __construct(protected Redis $redis, protected StdoutLoggerInterface $logger)
    {
    }

    /**
     * 全部配置（已按类型转换），key => value。
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        try {
            $cached = $this->redis->get($this->cacheKey());
            if (is_string($cached)) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (Throwable) {
            // 缓存只是加速手段
        }

        $values = [];
        foreach (Config::query()->get() as $row) {
            /* @var Config $row */
            $values[(string) $row->key] = $this->castValue((string) $row->value, (string) $row->type);
        }

        try {
            $this->redis->setex($this->cacheKey(), self::CACHE_TTL, (string) json_encode($values, JSON_UNESCAPED_UNICODE));
        } catch (Throwable) {
        }

        return $values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->get($key, $default);
        if (is_bool($value)) {
            return $value;
        }

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * 按分组返回配置项（含展示元信息），供配置页渲染动态表单。
     *
     * @return array<int, array{group: string, name: string, items: array<int, array<string, mixed>>}>
     */
    public function grouped(): array
    {
        $rows = Config::query()->orderBy('sort')->orderBy('id')->get();

        $buckets = [];
        foreach ($rows as $row) {
            /* @var Config $row */
            $buckets[(string) $row->group][] = [
                'key' => (string) $row->key,
                'value' => $this->castValue((string) $row->value, (string) $row->type),
                'type' => (string) $row->type,
                'name' => (string) $row->name,
                'description' => (string) $row->description,
                'sort' => (int) $row->sort,
                'is_system' => (int) $row->is_system,
            ];
        }

        $groups = [];
        foreach (self::GROUPS as $code => $meta) {
            if (! isset($buckets[$code])) {
                continue;
            }
            $groups[] = ['group' => $code, 'name' => $meta['name'], 'items' => $buckets[$code]];
            unset($buckets[$code]);
        }

        // seeder 之外新增的分组也要能显示出来
        foreach ($buckets as $code => $items) {
            $groups[] = ['group' => $code, 'name' => $code, 'items' => $items];
        }

        return $groups;
    }

    /**
     * 批量保存：只接受已存在的 key，值按各自的 type 落库。
     *
     * @param array<string, mixed> $items key => value
     * @return array<string, mixed> 保存后的全量配置
     */
    public function setMany(array $items): array
    {
        if ($items === []) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '没有需要保存的配置');
        }

        $rows = Config::query()->whereIn('key', array_keys($items))->get()->keyBy('key');

        $unknown = array_values(array_diff(array_keys($items), $rows->keys()->map(static fn ($key) => (string) $key)->all()));
        if ($unknown !== []) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '存在未知的配置项：' . implode(', ', $unknown));
        }

        foreach ($items as $key => $value) {
            /** @var Config $row */
            $row = $rows->get($key);
            $row->value = $this->normalizeValue($value, (string) $row->type);
            $row->save();
        }

        $this->flushCache();

        return $this->all();
    }

    public function flushCache(): void
    {
        try {
            $this->redis->del($this->cacheKey());
        } catch (Throwable $e) {
            $this->logger->warning('系统配置缓存清理失败：' . $e->getMessage());
        }
    }

    /**
     * 缓存键按数据库隔离：多个环境（开发/测试）共用同一 Redis 时，
     * 各自缓存自己库的配置，避免互相覆盖（测试环境尤其会踩）。
     */
    private function cacheKey(): string
    {
        return self::CACHE_KEY . ':' . (string) config('databases.default.database', 'default');
    }

    /**
     * DB 字符串 → PHP 类型。
     */
    private function castValue(string $value, string $type): mixed
    {
        return match ($type) {
            Config::TYPE_BOOLEAN => in_array($value, ['1', 'true', 'on', 'yes'], true),
            Config::TYPE_NUMBER => is_numeric($value) ? (int) $value : 0,
            Config::TYPE_JSON => json_decode($value, true) ?? [],
            default => $value,
        };
    }

    /**
     * 入参 → DB 字符串（统一存储格式，避免 true/'true'/1 混在一起）。
     */
    private function normalizeValue(mixed $value, string $type): string
    {
        return match ($type) {
            Config::TYPE_BOOLEAN => $this->toBool($value) ? '1' : '0',
            Config::TYPE_NUMBER => (string) $this->toInt($value),
            Config::TYPE_JSON => (string) json_encode(is_array($value) ? $value : [], JSON_UNESCAPED_UNICODE),
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    private function toInt(mixed $value): int
    {
        if (! is_numeric($value)) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '配置值必须是数字');
        }

        return (int) $value;
    }
}
