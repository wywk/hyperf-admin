<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/your-name/hyperf-admin
 * @license  MIT
 */
namespace App\Model;

/**
 * 表单提交数据（data 存提交时的 JSON，不做结构约束）。
 *
 * @property int $id
 * @property int $form_id
 * @property array $data
 * @property string $client_ip
 * @property \Carbon\Carbon $created_at
 */
class FormSubmission extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'form_submissions';

    protected array $fillable = [
        'form_id',
        'data',
        'client_ip',
    ];

    protected array $casts = [
        'id' => 'integer',
        'form_id' => 'integer',
        'data' => 'array',
        'created_at' => 'datetime',
    ];
}
