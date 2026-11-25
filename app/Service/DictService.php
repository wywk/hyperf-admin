<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */
namespace App\Service;

use App\Common\ApiCode;
use App\Exception\BusinessException;
use App\Model\DictItem;
use App\Model\DictType;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;

/**
 * 数据字典：类型/字典项管理 + 按编码读取（Redis 缓存，写操作自动失效）。
 *
 * 业务侧通过 items(code) 取下拉数据源；管理侧走 CRUD。
 */
class DictService
{
    public const CACHE_PREFIX = 'dict:code:';

    #[Inject]
    protected Redis $redis;

    // ---------- 管理侧 ----------

    /**
     * 字典类型列表（含字典项数量），支持按名称/编码模糊搜索。
     */
    public function types(string $keyword = ''): array
    {
        $query = DictType::query();
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")->orWhere('code', 'like', "%{$keyword}%");
            });
        }

        return $query->orderBy('sort')->orderBy('id')
            ->get()
            ->map(fn (DictType $type) => $this->formatType($type))
            ->all();
    }

    public function createType(array $data): DictType
    {
        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '' || ! preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '编码必须以小写字母开头，仅含小写字母/数字/下划线');
        }
        if (DictType::query()->where('code', $code)->exists()) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, "字典编码 {$code} 已存在");
        }

        $type = new DictType();
        $type->name = trim((string) ($data['name'] ?? ''));
        $type->code = $code;
        $type->description = (string) ($data['description'] ?? '');
        $type->sort = (int) ($data['sort'] ?? 0);
        $type->status = (int) ($data['status'] ?? 1);
        $type->save();

        return $type;
    }

    public function updateType(int $id, array $data): DictType
    {
        $type = $this->findTypeOrFail($id);

        if (isset($data['name'])) {
            $type->name = trim((string) $data['name']);
        }
        if (isset($data['description'])) {
            $type->description = (string) $data['description'];
        }
        if (isset($data['sort'])) {
            $type->sort = (int) $data['sort'];
        }
        if (isset($data['status'])) {
            $type->status = (int) $data['status'];
        }
        $type->save();

        // 停用类型后前端不应再拿到它的字典项
        if ($type->status === 0) {
            $this->invalidate($type->code);
        }

        return $type;
    }

    /**
     * 删除类型及其全部字典项，并清缓存。
     */
    public function deleteType(int $id): void
    {
        $type = $this->findTypeOrFail($id);
        DictItem::query()->where('type_id', $id)->delete();
        $this->invalidate($type->code);
        $type->delete();
    }

    /**
     * 指定类型下的全部字典项（管理视角：含停用项）。
     */
    public function itemsOf(int $typeId): array
    {
        return DictItem::query()->where('type_id', $typeId)
            ->orderBy('sort')->orderBy('id')->get()
            ->map(fn (DictItem $item) => $this->formatItem($item))
            ->all();
    }

    public function createItem(int $typeId, array $data): DictItem
    {
        $type = $this->findTypeOrFail($typeId);
        $value = trim((string) ($data['value'] ?? ''));
        if ($value === '') {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '字典项值不能为空');
        }
        if (DictItem::query()->where('type_id', $typeId)->where('value', $value)->exists()) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, "该类型下已存在值为 {$value} 的字典项");
        }

        $item = new DictItem();
        $item->type_id = $typeId;
        $item->label = trim((string) ($data['label'] ?? ''));
        $item->value = $value;
        $item->sort = (int) ($data['sort'] ?? 0);
        $item->status = (int) ($data['status'] ?? 1);
        $item->remark = (string) ($data['remark'] ?? '');
        $item->save();

        $this->invalidate($type->code);

        return $item;
    }

    public function updateItem(int $id, array $data): DictItem
    {
        /** @var null|DictItem $item */
        $item = DictItem::query()->find($id);
        if ($item === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '字典项不存在');
        }

        foreach (['label', 'remark'] as $field) {
            if (isset($data[$field])) {
                $item->{$field} = trim((string) $data[$field]);
            }
        }
        if (isset($data['sort'])) {
            $item->sort = (int) $data['sort'];
        }
        if (isset($data['status'])) {
            $item->status = (int) $data['status'];
        }

        // 允许改值，但不得与同类型下其他项冲突
        if (isset($data['value'])) {
            $value = trim((string) $data['value']);
            if ($value === '') {
                throw new BusinessException(ApiCode::VALIDATION_FAILED, '字典项值不能为空');
            }
            if ($value !== $item->value && DictItem::query()->where('type_id', $item->type_id)->where('value', $value)->exists()) {
                throw new BusinessException(ApiCode::VALIDATION_FAILED, "该类型下已存在值为 {$value} 的字典项");
            }
            $item->value = $value;
        }

        $item->save();
        $this->invalidateByTypeId($item->type_id);

        return $item;
    }

    public function deleteItem(int $id): void
    {
        /** @var null|DictItem $item */
        $item = DictItem::query()->find($id);
        if ($item === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '字典项不存在');
        }
        $typeId = (int) $item->type_id;
        $item->delete();
        $this->invalidateByTypeId($typeId);
    }

    // ---------- 业务侧 ----------

    /**
     * 按编码取字典项（仅启用类型 + 启用项），Redis 缓存。
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function items(string $code): array
    {
        $key = self::CACHE_PREFIX . $code;
        $cached = $this->redis->get($key);
        if ($cached !== false && $cached !== null) {
            return json_decode((string) $cached, true);
        }

        /** @var null|DictType $type */
        $type = DictType::query()->where('code', $code)->where('status', 1)->first();
        if ($type === null) {
            return [];
        }

        $items = DictItem::query()->where('type_id', $type->id)->where('status', 1)
            ->orderBy('sort')->orderBy('id')->get()
            ->map(fn (DictItem $item) => ['label' => $item->label, 'value' => $item->value])
            ->all();

        $this->redis->set($key, json_encode($items, JSON_UNESCAPED_UNICODE));

        return $items;
    }

    /**
     * 一次取多个字典编码，常用于页面初始化批量拉取下拉数据。
     *
     * @param array<int, string> $codes
     * @return array<string, array<int, array{label: string, value: string}>>
     */
    public function itemsMany(array $codes): array
    {
        $result = [];
        foreach ($codes as $code) {
            $result[$code] = $this->items((string) $code);
        }

        return $result;
    }

    // ---------- 内部 ----------

    private function findTypeOrFail(int $id): DictType
    {
        /** @var null|DictType $type */
        $type = DictType::query()->find($id);
        if ($type === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '字典类型不存在');
        }

        return $type;
    }

    private function invalidateByTypeId(int $typeId): void
    {
        /** @var null|DictType $type */
        $type = DictType::query()->find($typeId);
        if ($type !== null) {
            $this->invalidate($type->code);
        }
    }

    private function invalidate(string $code): void
    {
        $this->redis->del(self::CACHE_PREFIX . $code);
    }

    public function formatType(DictType $type): array
    {
        return [
            'id' => (int) $type->id,
            'name' => (string) $type->name,
            'code' => (string) $type->code,
            'description' => (string) $type->description,
            'sort' => (int) $type->sort,
            'status' => (int) $type->status,
            'item_count' => (int) DictItem::query()->where('type_id', $type->id)->count(),
            'created_at' => $type->created_at?->toDateTimeString(),
        ];
    }

    public function formatItem(DictItem $item): array
    {
        return [
            'id' => (int) $item->id,
            'type_id' => (int) $item->type_id,
            'label' => (string) $item->label,
            'value' => (string) $item->value,
            'sort' => (int) $item->sort,
            'status' => (int) $item->status,
            'remark' => (string) $item->remark,
        ];
    }
}
