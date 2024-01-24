<?php

namespace simpleExcel\test;

use PHPUnit\Framework\TestCase;
use Yuxiaobo\Library\SimpleExcel;

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
            '性别'      => 'gender'
        ));

        $this->assertIsArray($arr);
    }


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
            ['name' => '张三', 'idcard' => '`522131199703213342', 'mobile'=>'18311548011'],
            ['name' => '李四', 'idcard' => '`522131199703213342', 'mobile' => '18311548011'],
            ['name' => '赵五', 'idcard' => '`522131199703213342', 'mobile' => '18311548011'],
        ], '#ff0000', '#00ff00', '#333333');

        $this->assertTrue(file_exists($filename));
    }
    
}
