<?php

namespace yuxiaobo\library;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

use RuntimeException;

/**
 * 简单的excel导入导出工具库
 */
class SimpleExcel
{

    /**
     * 导入数据
     *
     * @param string $filePath 文件所在路径， 例如：/tmp/test.xlsx
     * @param string $fileType 文件类型，支持xls、xlsx、csv
     * @param array $fieldArr 关联数组，key为excel表头，value为数据库字段， 如： ['姓名' => 'name', '年龄' => 'age']
     * @return array
     * @throws RuntimeException
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    public static function import(string $filePath = '', string $fileType = 'xlsx', array $fieldArr = [])
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException('文件不存在');
        }

        // 实例化reader
        if (!in_array($fileType, ['xls', 'xlsx', 'csv'])) {
            throw new \RuntimeException('不支持的格式');
        }
        if ($fileType === 'xls') {
            $reader = new Xls();
        } else if ($fileType === 'xlsx') {
            $reader = new Xlsx();
        } else if ($fileType === 'csv') {
            $reader = new Csv();
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


    /**
     * 导出数据
     *
     * @param string $fileName 文件名，例如：/tmp/test.xlsx
     * @param string $fileType 文件类型，支持xls、xlsx、csv、php://output (直接浏览器输出下载)
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
        // 实例化Spreadsheet对象
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        // 设置表头
        $sheet = $spreadsheet->getActiveSheet();
        $columnIndex = 1;
        foreach ($headerColumn as $field => $displayName) {
            $cell = Coordinate::stringFromColumnIndex($columnIndex) . 1;
            $sheet->setCellValue($cell, $displayName);
            $sheet->getStyle($cell)->applyFromArray([
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
                $sheet->setCellValue($cell, $rowData[$field]);

                $sheet->getStyle($cell)->applyFromArray(
                    [
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
        if ($fileType === 'xls') {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xls($spreadsheet);
            $writer->save($fileName);
        } else if ($fileType === 'xlsx') {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($fileName);
        } else if ($fileType === 'csv') {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Csv($spreadsheet);
            $writer->save($fileName);
        } else if ($fileType === 'php://output') { // 直接输出下载
            ob_end_clean(); // 这一步非常关键，用来清除缓冲区防止导出的excel乱码
            header('pragma:public');
            header('Content-type:application/vnd.ms-excel;charset=utf-8;');
            header("Content-Disposition:attachment;filename=$fileName");
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
        }
    }
}