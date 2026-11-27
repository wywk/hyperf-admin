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

namespace App\Model\Concerns;

use Hyperf\Context\Context;
use Throwable;

/**
 * 字段级审计：挂上本 trait 的模型在写操作时把「改前 → 改后」快照
 * 累积进协程 Context，由 OperationLogMiddleware 落库时并入日志的 diff 列。
 *
 * 事件监听在 ModelAttrDiffListener（Hyperf 模型事件经事件分发器广播，
 * 不支持 Laravel 式 static::updating() 闭包注册）。
 *
 * 设计约束：
 * - 只在请求上下文（中间件已开启标记）时采集，队列/命令行不积累
 * - 敏感字段值脱敏为固定掩码
 * - 单请求最多记录 50 个字段变更，超出的记入截断标记，避免日志膨胀
 */
trait LogsAttributeChanges
{
    /** 值需要脱敏的字段名（大小写不敏感） */
    protected array $diffMaskedFields = ['password', 'password_confirmation', 'totp_secret', 'secret', 'token'];

    /** 值超长时截断到该长度 */
    protected int $diffMaxValueLength = 200;

    /**
     * 由 ModelAttrDiffListener 在 updating/created/deleted 事件时调用。
     */
    public function collectAttrDiff(string $event): void
    {
        try {
            // 只在请求上下文采集：中间件在处理写请求前设置开启标记
            if (! Context::has(AttrDiffKeys::OPEN_KEY)) {
                return;
            }

            $attributes = match ($event) {
                'updating' => $this->getDirty(),
                'created' => $this->getAttributes(),
                'deleted' => $this->getOriginal(),
                default => [],
            };

            if ($attributes === []) {
                return;
            }

            $table = $this->getTable();
            $id = (int) ($this->getOriginal('id') ?? $this->getKey() ?? 0);
            $entries = Context::get(AttrDiffKeys::CONTEXT_KEY) ?? [];
            $masked = array_map('strtolower', $this->diffMaskedFields);
            $recorded = count($entries);

            foreach ($attributes as $field => $value) {
                // 时间戳与主键没有审计意义
                if (in_array($field, ['id', 'created_at', 'updated_at'], true)) {
                    continue;
                }

                if ($recorded >= AttrDiffKeys::MAX_FIELDS) {
                    $entries[] = [
                        'table' => $table,
                        'id' => $id,
                        'field' => '__truncated__',
                        'before' => null,
                        'after' => '（超出单请求记录上限，已截断）',
                    ];
                    break;
                }

                $formatted = $this->diffFormat($value, $field, $masked);

                $entries[] = match ($event) {
                    'created' => [ // 创建：仅记录写入值
                        'table' => $table,
                        'id' => $id,
                        'field' => $field,
                        'before' => null,
                        'after' => $formatted,
                    ],
                    'updating' => [ // 更新：改前 → 改后
                        'table' => $table,
                        'id' => $id,
                        'field' => $field,
                        'before' => $this->diffFormat($this->getOriginal($field), $field, $masked),
                        'after' => $formatted,
                    ],
                    default => [ // 删除：仅记录删除前的值
                        'table' => $table,
                        'id' => $id,
                        'field' => $field,
                        'before' => $formatted,
                        'after' => null,
                    ],
                };
                ++$recorded;
            }

            if ($entries !== []) {
                Context::set(AttrDiffKeys::CONTEXT_KEY, $entries);
            }
        } catch (Throwable) {
            // 审计是旁路能力，任何异常不影响业务写入
        }
    }

    /**
     * 值格式化（脱敏/截断）。
     *
     * @param array<string> $masked
     */
    private function diffFormat(mixed $value, string $field, array $masked): ?string
    {
        if (in_array(strtolower($field), $masked, true)) {
            return '******';
        }

        return $this->diffValue($value);
    }

    /**
     * 值序列化：标量转字符串、数组 JSON 化、超长截断、null 归一。
     */
    private function diffValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (! is_scalar($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        $text = (string) $value;
        if (mb_strlen($text) > $this->diffMaxValueLength) {
            $text = mb_substr($text, 0, $this->diffMaxValueLength) . '…';
        }

        return $text;
    }
}
