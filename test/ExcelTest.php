<?php

namespace simpleExcel\test;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use yuxiaobo\library\SimpleExcel;

class ExcelTest extends TestCase
{

    /**
     * 导入测试
     * 
     * @test
     */
    public function testImport()
    {
        $arr = SimpleExcel::import(dirname(__DIR__) . '/test/test.xlsx', 'xlsx', array(
            '姓名'      => 'name',
            '年龄'      => 'age',
            '性别'      => 'gender',
            '时间'      => 'time'
        ));

        $this->assertIsArray($arr);
    }


    /**
     * 导出测试
     * 
     * @test
     */
    public function testExport()
    {
        $filename = dirname(__DIR__) . '/export.xlsx';
        if (file_exists($filename)) {
            @unlink($filename);
        }

        SimpleExcel::export($filename, 'xlsx', [
            'name'      => '姓名',
            'idcard'    => '身份证',
            'mobile'    => '手机号'
        ], [
            ['name' => '张三', 'idcard' => '522131199703213342', 'mobile' => '18311548011'],
            ['name' => '李四', 'idcard' => '522131199703213342', 'mobile' => '18311548011'],
            ['name' => '赵五', 'idcard' => '522131199703213342', 'mobile' => '18311548011'],
            ['name' => '王二麻子', 'idcard' => "5221311997\n03213342", 'mobile' => '1831154801118311548011183115480111831154801118311548011'],
        ], '#ffffff', '#3573dd', '#333333');

        $this->assertTrue(file_exists($filename));
    }


    /**
     * 导出10000行100列的CSV文件
     *
     * @group performance
     */
    public function test100000row_and_100column_csv_writer()
    {
        // 内存限制
        ini_set('memory_limit', '2048M');
        $filename = dirname(__DIR__) . '/100000row_and_100column.csv';
        if (file_exists($filename)) {
            @unlink($filename);
        }

        $header = [];
        for ($i = 1; $i <= 100; $i++) {
            $header['column' . $i] = '列' . $i;
        }

        $data = [];
        for ($i = 1; $i <= 100000; $i++) {
            $row = [];
            for ($j = 1; $j <= 100; $j++) {
                $row['column' . $j] = '第' . $i . '行第' . $j . '列';
            }
            $data[] = $row;
        }

        $t = microtime(true);
        SimpleExcel::export($filename, 'csv', $header, $data);
        $t = microtime(true) - $t;
        fwrite(STDERR, sprintf('CSV => 100000行100列导出: 耗时:%2.f' . PHP_EOL, $t));

        $this->assertTrue(file_exists($filename));
    }

    /**
     * 导入10000行100列的CSV文件
     *
     * @group performance
     */
    public function test100000row_and_100column_csv_reader()
    {
        // 内存限制
        ini_set('memory_limit', '3048M');
        $filename = dirname(__DIR__) . '/100000row_and_100column.csv';
        if (!file_exists($filename)) {
            $this->assertTrue(false);
        }

        $header = [];
        for ($i = 1; $i <= 100; $i++) {
            $header['列' . $i] = 'column' . $i;
        }

        $t = microtime(true);
        $data = SimpleExcel::import($filename, 'csv', $header);
        $t = microtime(true) - $t;
        fwrite(STDERR, sprintf('CSV => 100000行100列导入: 耗时:%2.f' . PHP_EOL, $t));

        $this->assertIsArray($data);
    }

    /**
     * @test
     */
    public function testCsvExportShouldRespectHeaderOrder()
    {
        $filename = tempnam(sys_get_temp_dir(), 'simple_excel_csv_');
        if ($filename === false) {
            $this->fail('无法创建临时文件');
        }

        $header = [
            'name' => '姓名',
            'idcard' => '身份证',
            'mobile' => '手机号',
        ];

        $rows = [
            [
                'mobile' => '18300000000',
                'name' => '张三',
                'idcard' => '522131199703213342',
            ],
        ];

        try {
            SimpleExcel::export($filename, 'csv', $header, $rows);

            $fp = fopen($filename, 'r');
            if ($fp === false) {
                $this->fail('无法读取临时文件');
            }

            $csvHeader = fgetcsv($fp, 0, ',', '"', '\\');
            $csvRow = fgetcsv($fp, 0, ',', '"', '\\');
            fclose($fp);

            $this->assertSame(['姓名', '身份证', '手机号'], $csvHeader);
            $this->assertSame(['张三', '522131199703213342', '18300000000'], $csvRow);
        } finally {
            @unlink($filename);
        }
    }

    /**
     * @test
     */
    public function testCsvImportShouldFailWhenFileIsEmpty()
    {
        $filename = tempnam(sys_get_temp_dir(), 'simple_excel_csv_');
        if ($filename === false) {
            $this->fail('无法创建临时文件');
        }

        file_put_contents($filename, '');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('CSV 文件为空或格式错误');
            SimpleExcel::import($filename, 'csv', ['姓名' => 'name']);
        } finally {
            @unlink($filename);
        }
    }

    /**
     * @test
     */
    public function testCsvImportShouldPadShortRows()
    {
        $filename = tempnam(sys_get_temp_dir(), 'simple_excel_csv_');
        if ($filename === false) {
            $this->fail('无法创建临时文件');
        }

        $csv = <<<CSV
姓名,年龄,性别
张三,18
李四,19,男
CSV;

        file_put_contents($filename, $csv);

        try {
            $data = SimpleExcel::import($filename, 'csv', [
                '姓名' => 'name',
                '年龄' => 'age',
                '性别' => 'gender',
            ]);

            $this->assertSame([
                ['name' => '张三', 'age' => '18', 'gender' => ''],
                ['name' => '李四', 'age' => '19', 'gender' => '男'],
            ], $data);
        } finally {
            @unlink($filename);
        }
    }

    /**
     * @test
     * @runInSeparateProcess
     */
    public function testExportToPhpOutputShouldRenderHtmlWhenExceptionOccurred()
    {
        ob_start();
        SimpleExcel::setDownloadHeader('导出数据.xlsx');
        SimpleExcel::export('php://output', 'invalid_type', [
            'name' => '姓名',
        ], [
            ['name' => '张三'],
        ]);
        $output = (string)ob_get_clean();

        $this->assertStringContainsString('<!DOCTYPE html>', $output);
        $this->assertStringContainsString('导出失败', $output);
        $this->assertStringContainsString('不支持导出文件类型', $output);
    }

    /**
     * @test
     */
    public function testExportToFileShouldStillThrowWhenExceptionOccurred()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('不支持导出文件类型');

        SimpleExcel::export(dirname(__DIR__) . '/export.invalid.xlsx', 'invalid_type', [
            'name' => '姓名',
        ], [
            ['name' => '张三'],
        ]);
    }
}
