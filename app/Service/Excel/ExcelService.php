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

namespace App\Service\Excel;

use App\Common\ApiCode;
use App\Exception\BusinessException;
use DateTimeInterface;
use Hyperf\Contract\Arrayable;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

/**
 * 通用 Excel 导入导出（openspout 流式读写，内存占用与数据量无关）。
 *
 * 导出：export(filename, headers, rows) 返回 xlsx 二进制，控制器用 raw response 下载。
 * 导入：parse(contents) 把 xlsx 解析为关联数组列表（首行为表头），供业务侧校验后落库。
 */
class ExcelService
{
    public const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * 导出为 xlsx。
     *
     * @param array<string, string> $headers 字段 => 表头文本（保持顺序）
     * @param iterable<array> $rows 关联数组或对象的可迭代序列（支持 Generator 流式产出）
     * @return string xlsx 文件二进制
     */
    public function export(string $filename, array $headers, iterable $rows): string
    {
        if ($headers === []) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '导出列不能为空');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $writer = new Writer();
        $writer->openToFile($tmp);
        $writer->addRow(Row::fromValues(array_values($headers)));

        $fields = array_keys($headers);
        foreach ($rows as $row) {
            // 注意：不能用 (array) 强转对象——Eloquent 模型强转出来是属性表（incrementing/exists/...），
            // 不是 attributes，会导致每行所有字段取空。Arrayable（Model）走 toArray。
            if ($row instanceof Arrayable) {
                $row = $row->toArray();
            } else {
                $row = (array) $row;
            }
            $writer->addRow(Row::fromValues(array_map(
                static fn (string $field) => self::stringValue($row[$field] ?? null),
                $fields,
            )));
        }

        $writer->close();
        $contents = (string) file_get_contents($tmp);
        unlink($tmp);

        return $contents;
    }

    /**
     * 解析上传的 xlsx：首行为表头，后续行转为关联数组。
     *
     * @param string $contents xlsx 二进制
     * @param int $maxRows 最大数据行数（防导入超大文件）
     * @return array<int, array<string, string>>
     */
    public function parse(string $contents, int $maxRows = 10000): array
    {
        if ($contents === '') {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '上传内容为空');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        file_put_contents($tmp, $contents);

        $reader = new Reader();
        try {
            $reader->open($tmp);
        } catch (Throwable $e) {
            unlink($tmp);
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '无法解析文件，请上传 .xlsx 格式');
        }

        $result = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $header = null;
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map(
                        static fn ($cell) => self::stringValue($cell->getValue()),
                        $row->getCells(),
                    );

                    if ($header === null) {
                        // 表头行：全空视为空表
                        if (implode('', $cells) === '') {
                            break;
                        }
                        $header = array_map(static fn (string $h) => trim($h), $cells);
                        continue;
                    }

                    // 跳过整行为空的数据行
                    if (implode('', $cells) === '') {
                        continue;
                    }

                    $assoc = [];
                    foreach ($header as $index => $name) {
                        if ($name !== '') {
                            $assoc[$name] = $cells[$index] ?? '';
                        }
                    }
                    $result[] = $assoc;

                    if (count($result) >= $maxRows) {
                        break 2;
                    }
                }
                break; // 只读第一个工作表
            }
        } finally {
            $reader->close();
            unlink($tmp);
        }

        return $result;
    }

    private static function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return implode(',', $value);
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }
}
