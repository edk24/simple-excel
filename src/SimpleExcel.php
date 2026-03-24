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
     * CSV escape char
     */
    const CSV_ESCAPE = '\\';

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
     * 导入数据
     *
     * @param string $filePath 文件所在路径， 例如：/tmp/test.xlsx
     * @param string $fileType 文件类型，支持xls、xlsx、csv 参考 SimpleExcel::FILE_
     * @param array $fieldArr 关联数组，key为excel表头，value为数据库字段， 如： ['姓名' => 'name', '年龄' => 'age']
     * @param bool $ignoreEmptyRow 是否忽略空行, 默认忽略
     * @return array
     * @throws RuntimeException
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    public static function import(string $filePath = '', string $fileType = 'xlsx', array $fieldArr = [], $ignoreEmptyRow = true)
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('文件不存在');
        }

        // 实例化reader
        if (!in_array($fileType, [self::FILE_XLS, self::FILE_XLSX, self::FILE_CSV], true)) {
            throw new RuntimeException('不支持的格式');
        }
        if ($fileType === self::FILE_XLS) {
            $reader = new Xls();
        } elseif ($fileType === self::FILE_XLSX) {
            $reader = new Xlsx();
        } elseif ($fileType === self::FILE_CSV) {
            return self::importFromCsv($filePath, $fieldArr, $ignoreEmptyRow);
        }

        //加载文件
        $insertArr = [];
        if (!$PHPExcel = $reader->load($filePath)) {
            throw new RuntimeException('未知的数据格式');
        }

        $currentSheet = $PHPExcel->getSheet(0); // 读取文件中的第一个工作表
        $allColumn = $currentSheet->getHighestDataColumn(); // 取得最大的列号
        $allRow = $currentSheet->getHighestRow(); // 取得一共有多少行
        $maxColumnNumber = Coordinate::columnIndexFromString($allColumn);
        $fields = [];
        for ($currentRow = 1; $currentRow <= 1; $currentRow++) {
            for ($currentColumn = 1; $currentColumn <= $maxColumnNumber; $currentColumn++) {
                $val = $currentSheet->getCell(Coordinate::stringFromColumnIndex($currentColumn) . $currentRow)->getValue();
                $fields[] = $val;
            }
        }

        for ($currentRow = 2; $currentRow <= $allRow; $currentRow++) {
            $values = [];
            for ($currentColumn = 1; $currentColumn <= $maxColumnNumber; $currentColumn++) {
                $val = $currentSheet->getCell(Coordinate::stringFromColumnIndex($currentColumn) . $currentRow)->getValue();
                $values[] = is_null($val) ? '' : $val;
            }
            // 忽略空行
            if ($ignoreEmptyRow && empty(array_filter($values))) {
                continue;
            }
            // 生成数据
            $row = [];
            $temp = array_combine($fields, $values);

            if (is_array($temp)) {
                foreach ($temp as $k => $v) {
                    if (isset($fieldArr[$k]) && $k !== '') {
                        $row[$fieldArr[$k]] = $v;
                    }
                }
            }


            if ($row) {
                array_push($insertArr, $row);
            }
        }

        return $insertArr;
    }


    private static function importFromCsv(string $filePath, array $fieldArr = [], $ignoreEmptyRow = true)
    {
        $insertArr = [];
        $fp = fopen($filePath, 'r');
        if ($fp === false) {
            throw new RuntimeException('CSV 文件无法读取');
        }

        $fields = fgetcsv($fp, 0, ',', '"', self::CSV_ESCAPE);
        if (!is_array($fields) || $fields === []) {
            fclose($fp);
            throw new RuntimeException('CSV 文件为空或格式错误');
        }

        $fieldCount = count($fields);

        while (($rawRow = fgetcsv($fp, 0, ',', '"', self::CSV_ESCAPE)) !== false) {
            if ($ignoreEmptyRow && empty(array_filter($rawRow))) {
                continue;
            }

            $rowCount = count($rawRow);
            if ($rowCount < $fieldCount) {
                $rawRow = array_pad($rawRow, $fieldCount, '');
            } elseif ($rowCount > $fieldCount) {
                $rawRow = array_slice($rawRow, 0, $fieldCount);
            }

            $temp = array_combine($fields, $rawRow);
            $row = [];
            if (is_array($temp)) {
                foreach ($temp as $k => $v) {
                    if (isset($fieldArr[$k]) && $k !== '') {
                        $row[$fieldArr[$k]] = $v;
                    }
                }
            }

            if ($row) {
                array_push($insertArr, $row);
            }
        }

        fclose($fp);
        return $insertArr;
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
     * @return void
     * @throws RuntimeException
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    public static function export(string $fileName = 'dump.xlsx', string $fileType = 'xlsx', array $headerColumn = [], array $exportData = [], string $headerColor = '#333333', string $headerBgColor = '#99bcac', string $borderColor = '#333333')
    {
        try {
            if ($fileType === self::FILE_CSV) {
                self::exportToCsv($fileName, $headerColumn, $exportData);
                return;
            }

            // 实例化Spreadsheet对象
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

            // 设置表头
            $sheet = $spreadsheet->getActiveSheet();
            $columnIndex = 1;
            foreach ($headerColumn as $field => $displayName) {
                $column = Coordinate::stringFromColumnIndex($columnIndex);
                $cell = $column . 1;
                $sheet->setCellValue($cell, $displayName ?? '');
                // 自动列宽
                $sheet->getColumnDimension($column)->setAutoSize(true);
                $sheet->getStyle($cell)->applyFromArray([
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => [
                            'rgb' => str_replace('#', '', $headerBgColor),
                        ],
                    ],
                    'font' => [
                        'bold'  => true,
                        'color' => [
                            'rgb' => str_replace('#', '', $headerColor),
                        ],
                    ],
                ]);
                $columnIndex++;
            }

            // 设置数据
            $rowIndex = 2;
            foreach ($exportData as $rowData) {
                $columnIndex = 1;
                foreach ($headerColumn as $field => $displayName) {
                    $cell = Coordinate::stringFromColumnIndex($columnIndex) . $rowIndex;
                    // 设置单元格数据, 强制字符串类型
                    $sheet->setCellValueExplicit($cell, $rowData[$field] ?? '', DataType::TYPE_STRING);
                    $sheet->getStyle($cell)->applyFromArray(
                        [
                            'alignment' => [
                                'horizontal' => Alignment::HORIZONTAL_CENTER,
                                'vertical' => Alignment::VERTICAL_CENTER,
                                'wrapText' => true,
                            ],
                            'borders' => [
                                'allBorders' => [
                                    'borderStyle' => Border::BORDER_THIN,
                                    'color' => [
                                        'rgb' => str_replace('#', '', $borderColor),
                                    ],
                                ],
                            ],
                        ]
                    );
                    $columnIndex++;
                }
                $rowIndex++;
            }


            // 宽度自适应
            $columnIndex = 1;
            foreach ($headerColumn as $field => $displayName) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
                $columnIndex++;
            }

            // 设置文件类型
            if ($fileType === self::FILE_XLS) {
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xls($spreadsheet);
            } elseif ($fileType === self::FILE_XLSX) {
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            } else {
                throw new RuntimeException("不支持导出文件类型: $fileType");
            }
            $writer->save($fileName);
        } catch (\Throwable $e) {
            // 下载流报错时输出 html，避免页面崩溃
            if ($fileName === 'php://output') {
                if (ob_get_level() > 0) {
                    @ob_clean();
                }

                if (function_exists('http_response_code')) {
                    http_response_code(500);
                }
                header('Content-Type:text/html; charset=UTF-8');
                header('Content-Disposition: inline');
                self::renderExportErrorHtml($e);
                return;
            }
            throw $e;
        }
    }

    private static function exportToCsv(string $fileName, array $headerColumn, array $exportData)
    {
        $fp = fopen($fileName, 'w');
        if ($fp === false) {
            throw new RuntimeException('CSV 文件无法写入');
        }

        fputcsv($fp, array_values($headerColumn), ',', '"', self::CSV_ESCAPE);
        $fieldKeys = array_keys($headerColumn);

        foreach ($exportData as $rowData) {
            $orderedRow = [];
            foreach ($fieldKeys as $fieldKey) {
                $orderedRow[] = $rowData[$fieldKey] ?? '';
            }
            fputcsv($fp, $orderedRow, ',', '"', self::CSV_ESCAPE);
        }

        fclose($fp);
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
     * 设置下载头
     *
     * @param string $filename 文件名，例如：导出数据.xlsx
     * 先设置下载头， 然后再调用export方法，输出文件路径为 php://output
     */
    public static function setDownloadHeader(string $filename = '导出数据.xlsx')
    {
        if (ob_get_length()) ob_end_clean(); // 这一步非常关键，用来清除缓冲区防止导出的excel乱码
        header('pragma:public');
        $type = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($type === self::FILE_CSV) {
            header('Content-Type:text/csv;charset=utf-8;');
        } elseif ($type === self::FILE_XLSX) {
            header('Content-Type:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet;charset=utf-8;');
        } else {
            header('Content-Type:application/vnd.ms-excel;charset=utf-8;');
        }

        $safeFilename = str_replace(['"', "\r", "\n"], '', $filename);
        $encodedFilename = rawurlencode($safeFilename);
        header("Content-Disposition:attachment; filename=\"{$safeFilename}\"; filename*=UTF-8''{$encodedFilename}");
    }
}
