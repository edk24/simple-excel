<?php

declare(strict_types=1);

namespace yuxiaobo\library;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

use RuntimeException;

/**
 * 简单的excel导入导出工具库
 */
class SimpleExcel
{
    /**
     * CSV escape char。
     *
     * PHP 8.4 起，向 fgetcsv/fputcsv 传入非空 $escape 会触发 deprecation；
     * 空字符串表示遵循 RFC 4180（不使用反斜杠转义），在 PHP 7.4 - 8.5 行为一致。
     * 反斜杠转义本身属于 PHP 的私有扩展，不应再依赖。
     */
    const CSV_ESCAPE = '';

    /**
     * 文件类型：xlsx
     */
    const FILE_XLSX = 'xlsx';

    /**
     * 文件类型：xls
     */
    const FILE_XLS = 'xls';

    /**
     * 文件类型：csv
     */
    const FILE_CSV = 'csv';

    /**
     * Excel 单表最大行数
     */
    const EXCEL_MAX_ROWS = 1048576;

    /**
     * Excel 单表最大列数
     */
    const EXCEL_MAX_COLUMNS = 16384;

    /**
     * 单次导入允许的最大单元格数（防止稀疏大坐标文件导致 17 亿级循环/OOM）
     */
    const EXCEL_MAX_CELLS = 10000000;

    /**
     * 导入数据
     *
     * @param string $filePath 文件所在路径， 例如：/tmp/test.xlsx
     * @param string $fileType 文件类型，支持xls、xlsx、csv 参考 SimpleExcel::FILE_
     * @param array $fieldArr 关联数组，key为excel表头，value为数据库字段， 如： ['姓名' => 'name', '年龄' => 'age']
     * @param bool $ignoreEmptyRow 是否忽略空行, 默认忽略
     * @param array $options 可选的安全/性能参数：
     *                       - maxFileSize (int) 文件最大字节数，0 表示不限制，默认 0
     *                       - maxRows (int) 最大行数，0 表示不限制；xls/xlsx 默认 1048576，csv 默认 0
     *                       - maxColumns (int) 最大列数，0 表示不限制；xls/xlsx 默认 16384，csv 默认 0
     *                       - maxCells (int) 最大单元格数，0 表示不限制；xls/xlsx 默认 10000000，csv 默认 0
     *                       - readDataOnly (bool) 只读取数据，降低内存占用，默认 false
     *                       - assertSecureRuntime (bool) 运行时受 CVE-2026-109618/109619 影响时直接抛异常，默认 false
     *                       - csvEscape (string) CSV 转义字符，默认 ''（RFC 4180）；仅确需兼容历史反斜杠转义时设为 '\\'
     * @return array
     * @throws RuntimeException
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    public static function import(string $filePath = '', string $fileType = 'xlsx', array $fieldArr = [], $ignoreEmptyRow = true, array $options = [])
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('文件不存在');
        }

        // 实例化reader
        if (!in_array($fileType, [self::FILE_XLS, self::FILE_XLSX, self::FILE_CSV], true)) {
            throw new RuntimeException('不支持的格式');
        }

        $options = self::normalizeImportOptions($options, $fileType);

        // 已知受 CVE 影响的运行时可以选择 fail-closed
        if ($options['assertSecureRuntime']) {
            RuntimeSecurity::assertSecure();
        }

        self::assertFileSize($filePath, $options['maxFileSize']);

        if ($fileType === self::FILE_CSV) {
            return self::importFromCsv($filePath, $fieldArr, (bool) $ignoreEmptyRow, $options);
        }

        $spreadsheet = null;
        try {
            $reader = $fileType === self::FILE_XLS ? new Xls() : new Xlsx();
            if ($options['readDataOnly']) {
                $reader->setReadDataOnly(true);
            }

            $spreadsheet = null;
            try {
                $spreadsheet = $reader->load($filePath);
            } catch (\Throwable $e) {
                // 把解析器抛出的 Error/底层异常统一收敛为 RuntimeException，避免调用方漏捕获
                throw new RuntimeException('文件解析失败: ' . $e->getMessage(), 0, $e);
            }

            if (!$spreadsheet) {
                throw new RuntimeException('未知的数据格式');
            }

            $currentSheet = $spreadsheet->getSheet(0); // 读取文件中的第一个工作表
            $allRow = method_exists($currentSheet, 'getHighestDataRow')
                ? $currentSheet->getHighestDataRow()
                : $currentSheet->getHighestRow(); // 取得一共有多少行（优先只统计有数据的行）
            $allColumn = $currentSheet->getHighestDataColumn(); // 取得最大的列号
            $maxColumnNumber = Coordinate::columnIndexFromString($allColumn);

            self::assertLimit('行数', (int) $allRow, $options['maxRows']);
            self::assertLimit('列数', (int) $maxColumnNumber, $options['maxColumns']);
            self::assertLimit('单元格数', (int) $allRow * (int) $maxColumnNumber, $options['maxCells']);

            // 表头
            $fields = [];
            for ($currentColumn = 1; $currentColumn <= $maxColumnNumber; $currentColumn++) {
                $fields[] = self::normalizeCellValue(
                    $currentSheet->getCell(Coordinate::stringFromColumnIndex($currentColumn) . '1')->getValue()
                );
            }

            $insertArr = [];
            for ($currentRow = 2; $currentRow <= $allRow; $currentRow++) {
                $values = [];
                for ($currentColumn = 1; $currentColumn <= $maxColumnNumber; $currentColumn++) {
                    $value = self::normalizeCellValue(
                        $currentSheet->getCell(Coordinate::stringFromColumnIndex($currentColumn) . $currentRow)->getValue()
                    );
                    $values[] = $value === null ? '' : $value;
                }

                // 忽略空行（注意：0、"0"、false 都是有效数据，不能被当成空行）
                if ($ignoreEmptyRow && self::isEmptyRow($values)) {
                    continue;
                }

                $row = self::mapRow($fields, $values, $fieldArr);
                if ($row !== []) {
                    $insertArr[] = $row;
                }
            }

            return $insertArr;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // 把解析器抛出的 Error/底层异常统一收敛为 RuntimeException，避免调用方漏捕获
            throw new RuntimeException('文件解析失败: ' . $e->getMessage(), 0, $e);
        } finally {
            if ($spreadsheet !== null) {
                try {
                    $spreadsheet->disconnectWorksheets();
                } catch (\Throwable $e) {
                    // 释放失败不应覆盖原始异常
                }
            }
        }
    }


    private static function importFromCsv(string $filePath, array $fieldArr, bool $ignoreEmptyRow, array $options)
    {
        $fp = @fopen($filePath, 'rb');
        if ($fp === false) {
            throw new RuntimeException('CSV 文件无法读取');
        }

        try {
            $fields = fgetcsv($fp, 0, ',', '"', $options['csvEscape']);
            if (!is_array($fields) || $fields === [] || $fields === [null]) {
                throw new RuntimeException('CSV 文件为空或格式错误');
            }

            // 去除 Excel 导出常见的 UTF-8 BOM，否则第一个表头会匹配失败
            $fields[0] = self::stripBom((string) $fields[0]);
            $fieldCount = count($fields);
            self::assertLimit('列数', $fieldCount, $options['maxColumns']);

            $insertArr = [];
            $rowNumber = 1;

            while (($rawRow = fgetcsv($fp, 0, ',', '"', $options['csvEscape'])) !== false) {
                $rowNumber++;
                self::assertLimit('行数', $rowNumber, $options['maxRows']);
                self::assertLimit('单元格数', $rowNumber * max(1, $fieldCount), $options['maxCells']);

                if ($ignoreEmptyRow && self::isEmptyRow($rawRow)) {
                    continue;
                }

                $rowCount = count($rawRow);
                if ($rowCount < $fieldCount) {
                    $rawRow = array_pad($rawRow, $fieldCount, '');
                } elseif ($rowCount > $fieldCount) {
                    $rawRow = array_slice($rawRow, 0, $fieldCount);
                }

                $row = self::mapRow($fields, $rawRow, $fieldArr);
                if ($row !== []) {
                    $insertArr[] = $row;
                }
            }

            return $insertArr;
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }
    }


    /**
     * 导出数据
     *
     * @param string $fileName 文件名，例如：/tmp/test.xlsx 或 php://output (直接浏览器输出下载，前提先使用 setDownloadHeader 方法设置下载头)
     * @param string $fileType 文件类型，支持xls、xlsx、csv 参考 SimpleExcel::FILE_
     * @param array $headerColumn 表头信息，key为字段，value为excel表头， 如： ['name' => '姓名', 'age' => '年龄']
     * @param array $exportData 导出数据，二维数组，如：[['name' => '张三', 'age' => 18], ['name' => '李四', 'age' => 19]]
     * @param string $headerColor 表头字体颜色，如 #333333
     * @param string $headerBgColor 表头背景颜色，如 #99bcac
     * @param string $borderColor 边框颜色，如 #333333
     * @param array $options 可选参数：
     *                       - autoSize (bool) 是否自动列宽，默认 true
     *                       - csvEscape (string) CSV 转义字符，默认 ''（RFC 4180）
     * @return void
     * @throws RuntimeException
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    public static function export(string $fileName = 'dump.xlsx', string $fileType = 'xlsx', array $headerColumn = [], array $exportData = [], string $headerColor = '#333333', string $headerBgColor = '#99bcac', string $borderColor = '#333333', array $options = [])
    {
        $options = array_merge(['autoSize' => true, 'csvEscape' => self::CSV_ESCAPE], $options);
        $autoSize = (bool) $options['autoSize'];
        $csvEscape = self::normalizeCsvEscape($options['csvEscape']);
        $obLevel = ob_get_level();

        try {
            if ($fileType === self::FILE_CSV) {
                self::exportToCsv($fileName, $headerColumn, $exportData, $csvEscape);
                return;
            }

            // 实例化Spreadsheet对象
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

            try {
                $sheet = $spreadsheet->getActiveSheet();
                $columnCount = count($headerColumn);
                $lastColumn = Coordinate::stringFromColumnIndex(max(1, $columnCount));

                // 设置表头
                $columnIndex = 1;
                foreach ($headerColumn as $displayName) {
                    $cell = Coordinate::stringFromColumnIndex($columnIndex) . '1';
                    $sheet->setCellValueExplicit($cell, self::stringifyCellValue($displayName), DataType::TYPE_STRING);
                    $columnIndex++;
                }

                // 表头样式一次性应用到整个表头区域，避免逐单元格调用
                $sheet->getStyle('A1:' . $lastColumn . '1')->applyFromArray([
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => [
                            'rgb' => self::normalizeColor($headerBgColor),
                        ],
                    ],
                    'font' => [
                        'bold'  => true,
                        'color' => [
                            'rgb' => self::normalizeColor($headerColor),
                        ],
                    ],
                ]);

                // 设置数据
                $rowIndex = 2;
                foreach ($exportData as $rowData) {
                    $rowData = (array) $rowData;
                    $columnIndex = 1;
                    foreach ($headerColumn as $field => $displayName) {
                        $cell = Coordinate::stringFromColumnIndex($columnIndex) . $rowIndex;
                        // 设置单元格数据, 强制字符串类型，避免身份证/手机号出现科学计数法
                        $sheet->setCellValueExplicit(
                            $cell,
                            self::stringifyCellValue(isset($rowData[$field]) ? $rowData[$field] : ''),
                            DataType::TYPE_STRING
                        );
                        $columnIndex++;
                    }
                    $rowIndex++;
                }

                // 数据区域样式一次性应用
                $lastRow = $rowIndex - 1;
                if ($lastRow >= 2) {
                    $sheet->getStyle('A2:' . $lastColumn . $lastRow)->applyFromArray([
                        'alignment' => [
                            'horizontal' => Alignment::HORIZONTAL_CENTER,
                            'vertical' => Alignment::VERTICAL_CENTER,
                            'wrapText' => true,
                        ],
                        'borders' => [
                            'allBorders' => [
                                'borderStyle' => Border::BORDER_THIN,
                                'color' => [
                                    'rgb' => self::normalizeColor($borderColor),
                                ],
                            ],
                        ],
                    ]);
                }

                // 宽度自适应（只执行一次）
                if ($autoSize) {
                    for ($columnIndex = 1; $columnIndex <= $columnCount; $columnIndex++) {
                        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
                    }
                }

                // 设置文件类型
                if ($fileType === self::FILE_XLS) {
                    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xls($spreadsheet);
                } elseif ($fileType === self::FILE_XLSX) {
                    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
                } else {
                    throw new RuntimeException("不支持导出文件类型: $fileType");
                }

                // 全部单元格都是字符串，无需预计算公式
                if (method_exists($writer, 'setPreCalculateFormulas')) {
                    $writer->setPreCalculateFormulas(false);
                }

                $writer->save($fileName);
            } finally {
                try {
                    $spreadsheet->disconnectWorksheets();
                } catch (\Throwable $e) {
                    // 释放失败不应覆盖原始异常
                }
            }
        } catch (\Throwable $e) {
            // 下载流报错时输出 html，避免页面崩溃
            if ($fileName === 'php://output') {
                self::renderDownloadError($e, $obLevel);
                return;
            }
            throw $e;
        }
    }

    private static function exportToCsv(string $fileName, array $headerColumn, array $exportData, string $csvEscape)
    {
        $fp = @fopen($fileName, 'wb');
        if ($fp === false) {
            throw new RuntimeException('CSV 文件无法写入');
        }

        try {
            $header = [];
            foreach ($headerColumn as $displayName) {
                $header[] = self::stringifyCellValue($displayName);
            }
            fputcsv($fp, $header, ',', '"', $csvEscape);

            foreach ($exportData as $rowData) {
                $rowData = (array) $rowData;
                $orderedRow = [];
                foreach ($headerColumn as $fieldKey => $displayName) {
                    $orderedRow[] = self::stringifyCellValue(isset($rowData[$fieldKey]) ? $rowData[$fieldKey] : '');
                }
                fputcsv($fp, $orderedRow, ',', '"', $csvEscape);
            }
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }
    }

    /**
     * 设置下载头
     *
     * @param string $filename 文件名，例如：导出数据.xlsx
     * 先设置下载头， 然后再调用export方法，输出文件路径为 php://output
     */
    public static function setDownloadHeader(string $filename = '导出数据.xlsx')
    {
        if (ob_get_level() > 0 && ob_get_length() > 0) {
            @ob_end_clean(); // 清除缓冲区，防止导出的excel乱码；必须在输出之前调用
        }

        // 已经输出过内容时无法再设置响应头，静默返回避免产生 warning
        if (headers_sent()) {
            return;
        }

        header('pragma:public');
        header('X-Content-Type-Options: nosniff');

        $type = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($type === self::FILE_CSV) {
            header('Content-Type:text/csv;charset=utf-8;');
        } elseif ($type === self::FILE_XLSX) {
            header('Content-Type:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet;charset=utf-8;');
        } else {
            header('Content-Type:application/vnd.ms-excel;charset=utf-8;');
        }

        // 去掉路径分隔符、引号和控制字符，避免响应头注入/非法文件名
        $safeFilename = str_replace(['\\', '/', '"', "\r", "\n", "\0"], '', $filename);
        $safeFilename = preg_replace('/[\x01-\x1F\x7F]/', '', $safeFilename);
        if ($safeFilename === null || $safeFilename === '') {
            $safeFilename = 'download';
        }

        $encodedFilename = rawurlencode($safeFilename);
        header("Content-Disposition:attachment; filename=\"{$safeFilename}\"; filename*=UTF-8''{$encodedFilename}");
    }

    /**
     * 下载模式下的错误页输出。
     */
    private static function renderDownloadError(\Throwable $e, int $obLevel = 0): void
    {
        // 只清理导出过程中新开的缓冲区，避免影响调用方/PHPUnit 的缓冲区
        while (ob_get_level() > $obLevel) {
            if (!@ob_end_clean()) {
                break;
            }
        }
        if (ob_get_level() > 0) {
            @ob_clean();
        }

        if (!headers_sent()) {
            if (function_exists('http_response_code')) {
                http_response_code(500);
            }
            header('Content-Type:text/html; charset=UTF-8');
            header('Content-Disposition: inline');
            header('X-Content-Type-Options: nosniff');
        }

        self::renderExportErrorHtml($e);
    }

    private static function renderExportErrorHtml(\Throwable $e): void
    {
        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>导出失败</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; background: #f5f7fa;">
    <div style="max-width: 720px; margin: 48px auto; padding: 24px; background: #fff; border-radius: 8px; border: 1px solid #e5e7eb;">
        <h2 style="margin: 0 0 12px; color: #111827;">导出失败</h2>
        <p style="margin: 0 0 8px; color: #374151;">文件下载已中止，请稍后重试或联系管理员。</p>
        <p style="margin: 0; color: #b91c1c;">错误信息：{$message}</p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * 合并并校正导入参数。
     */
    private static function normalizeImportOptions(array $options, string $fileType): array
    {
        $defaults = [
            'maxFileSize' => 0,
            'maxRows' => 0,
            'maxColumns' => 0,
            'maxCells' => 0,
            'readDataOnly' => false,
            'assertSecureRuntime' => false,
            'csvEscape' => self::CSV_ESCAPE,
        ];

        // xls/xlsx 默认按 Excel 自身的硬限制兜底；csv 保持不限制行/列，避免破坏既有用法
        if ($fileType !== self::FILE_CSV) {
            $defaults['maxRows'] = self::EXCEL_MAX_ROWS;
            $defaults['maxColumns'] = self::EXCEL_MAX_COLUMNS;
            $defaults['maxCells'] = self::EXCEL_MAX_CELLS;
        }

        $options = array_merge($defaults, $options);

        foreach (['maxFileSize', 'maxRows', 'maxColumns', 'maxCells'] as $key) {
            $options[$key] = max(0, (int) $options[$key]);
        }

        $options['readDataOnly'] = (bool) $options['readDataOnly'];
        $options['assertSecureRuntime'] = (bool) $options['assertSecureRuntime'];
        $options['csvEscape'] = self::normalizeCsvEscape($options['csvEscape']);

        return $options;
    }

    /**
     * CSV 转义字符只允许空字符串或单个字符。
     */
    private static function normalizeCsvEscape($escape): string
    {
        $escape = (string) $escape;
        if ($escape !== '' && strlen($escape) !== 1) {
            throw new RuntimeException('csvEscape 必须为空字符串或单个字符');
        }

        return $escape;
    }

    /**
     * 文件大小限制。
     */
    private static function assertFileSize(string $filePath, int $maxFileSize): void
    {
        if ($maxFileSize <= 0) {
            return;
        }

        $size = @filesize($filePath);
        if ($size !== false && $size > $maxFileSize) {
            throw new RuntimeException(sprintf(
                '文件大小超过限制: %d 字节 > %d 字节（可通过 import 第 5 个参数调整）',
                $size,
                $maxFileSize
            ));
        }
    }

    /**
     * 行/列/单元格数量限制。
     */
    private static function assertLimit(string $name, int $actual, int $limit): void
    {
        if ($limit > 0 && $actual > $limit) {
            throw new RuntimeException(sprintf(
                '%s超过限制: %d > %d（可通过 import 第 5 个参数调整）',
                $name,
                $actual,
                $limit
            ));
        }
    }

    /**
     * 将表头和数据行映射为 fieldArr 定义的字段。
     */
    private static function mapRow(array $fields, array $values, array $fieldArr): array
    {
        $row = [];
        $count = min(count($fields), count($values));

        for ($i = 0; $i < $count; $i++) {
            $key = $fields[$i];

            if ($key === null || $key === '') {
                continue;
            }
            if (!isset($fieldArr[$key])) {
                continue;
            }

            $target = $fieldArr[$key];
            if ($target === null || $target === '') {
                continue;
            }

            $row[$target] = $values[$i];
        }

        return $row;
    }

    /**
     * 判断一行是否为空（0 / "0" / false 都视为有效数据）。
     */
    private static function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * 兼容富文本单元格，避免后续出现 "Object of class ... could not be converted to string"。
     */
    private static function normalizeCellValue($value)
    {
        if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            return $value->getPlainText();
        }

        return $value;
    }

    /**
     * 导出时统一转成字符串，避免数组/对象导致写入失败。
     */
    private static function stringifyCellValue($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }

    /**
     * 去掉 UTF-8 BOM。
     */
    private static function stripBom(string $value): string
    {
        if (strncmp($value, "\xEF\xBB\xBF", 3) === 0) {
            return substr($value, 3);
        }

        return $value;
    }

    /**
     * 颜色值统一去掉 # 前缀。
     */
    private static function normalizeColor(string $color): string
    {
        return str_replace('#', '', $color);
    }
}
