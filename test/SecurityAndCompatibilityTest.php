<?php

namespace simpleExcel\test;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use yuxiaobo\library\RuntimeSecurity;
use yuxiaobo\library\SimpleExcel;

class SecurityAndCompatibilityTest extends TestCase
{
    /**
     * @test
     */
    public function testRuntimeSecurityDetectsVulnerableAndPatchedVersions()
    {
        $cases = [
            '7.4.33' => true,
            '8.0.30' => true,
            '8.1.33' => true,
            '8.2.34' => true,
            '8.2.35' => false,
            '8.3.35' => true,
            '8.3.36' => false,
            '8.4.26' => true,
            '8.4.27' => false,
            '8.5.11' => true,
            '8.5.12' => false,
            '8.6.0' => false,
            '9.0.0' => false,
        ];

        foreach ($cases as $version => $affected) {
            $this->assertSame($affected, RuntimeSecurity::isAffected($version), 'PHP ' . $version);
        }
    }

    /**
     * @test
     */
    public function testRuntimeSecurityRecommendsPatchedVersionForBranch()
    {
        $this->assertSame('8.2.35', RuntimeSecurity::recommendedVersion('8.2.1'));
        $this->assertSame('8.3.36', RuntimeSecurity::recommendedVersion('8.3.33'));
        $this->assertSame('8.4.27', RuntimeSecurity::recommendedVersion('8.4.0'));
        $this->assertSame('8.5.12', RuntimeSecurity::recommendedVersion('8.5.11'));
        $this->assertSame('8.3.36', RuntimeSecurity::recommendedVersion('8.3.36'));
        $this->assertSame('8.6.0', RuntimeSecurity::recommendedVersion('8.6.0'));
        $this->assertSame('8.3.36', RuntimeSecurity::recommendedVersion('7.4.33'));
    }

    /**
     * @test
     */
    public function testRuntimeSecurityAssertSecureThrowsForVulnerableVersion()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CVE-2026-109619');

        RuntimeSecurity::assertSecure('8.3.35');
    }

    /**
     * @test
     */
    public function testRuntimeSecurityAssertSecureAcceptsPatchedVersion()
    {
        RuntimeSecurity::assertSecure('8.3.36');

        $this->assertNull(RuntimeSecurity::warning('8.3.36'));
        $this->assertTrue(RuntimeSecurity::isSupported('8.3.36'));
        $this->assertFalse(RuntimeSecurity::isAffected('8.3.36'));
    }

    /**
     * @test
     */
    public function testRuntimeSecurityStatusContainsCveAndMitigationGuidance()
    {
        $status = RuntimeSecurity::status('8.3.33');

        $this->assertTrue($status['affected']);
        $this->assertSame('8.3.36', $status['recommended_version']);
        $this->assertContains('CVE-2026-109618', $status['cves']);
        $this->assertContains('CVE-2026-109619', $status['cves']);
        $this->assertNotEmpty($status['mitigations']);
        $this->assertStringContainsString('升级', $status['message']);
    }

    /**
     * @test
     */
    public function testImportShouldEnforceMaxRows()
    {
        $filename = $this->createCsv("姓名,年龄\n张三,18\n李四,19\n");

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('行数超过限制');
            SimpleExcel::import($filename, 'csv', ['姓名' => 'name'], true, ['maxRows' => 2]);
        } finally {
            @unlink($filename);
        }
    }

    /**
     * @test
     */
    public function testImportShouldEnforceMaxColumns()
    {
        $filename = $this->createCsv("姓名,年龄\n张三,18\n");

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('列数超过限制');
            SimpleExcel::import($filename, 'csv', ['姓名' => 'name'], true, ['maxColumns' => 1]);
        } finally {
            @unlink($filename);
        }
    }

    /**
     * @test
     */
    public function testImportShouldEnforceMaxFileSize()
    {
        $filename = $this->createCsv("姓名,年龄\n张三,18\n");

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('文件大小超过限制');
            SimpleExcel::import($filename, 'csv', ['姓名' => 'name'], true, ['maxFileSize' => 1]);
        } finally {
            @unlink($filename);
        }
    }

    /**
     * @test
     */
    public function testImportShouldEnforceMaxCells()
    {
        $filename = $this->createCsv("姓名,年龄\n张三,18\n");

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('单元格数超过限制');
            SimpleExcel::import($filename, 'csv', ['姓名' => 'name'], true, ['maxCells' => 3]);
        } finally {
            @unlink($filename);
        }
    }

    /**
     * @test
     */
    public function testImportShouldTreatZeroAsRealData()
    {
        $filename = $this->createCsv("姓名,年龄,备注\n张三,0,0\n李四,18,\n");

        try {
            $rows = SimpleExcel::import($filename, 'csv', [
                '姓名' => 'name',
                '年龄' => 'age',
                '备注' => 'remark',
            ]);

            $this->assertSame('0', $rows[0]['age']);
            $this->assertSame('0', $rows[0]['remark']);
            $this->assertSame('18', $rows[1]['age']);
        } finally {
            @unlink($filename);
        }
    }

    /**
     * @test
     */
    public function testCsvImportShouldStripUtf8Bom()
    {
        $filename = $this->createCsv("\xEF\xBB\xBF姓名,年龄\n张三,18\n");

        try {
            $rows = SimpleExcel::import($filename, 'csv', ['姓名' => 'name', '年龄' => 'age']);

            $this->assertSame([['name' => '张三', 'age' => '18']], $rows);
        } finally {
            @unlink($filename);
        }
    }

    /**
     * @test
     */
    public function testCsvExportAndImportRoundTripWithQuotesAndNewlines()
    {
        $filename = tempnam(sys_get_temp_dir(), 'simple_excel_csv_');
        if ($filename === false) {
            $this->fail('无法创建临时文件');
        }

        $rows = [
            ['name' => '张三, "测试"', 'remark' => "第一行\n第二行"],
        ];

        try {
            SimpleExcel::export($filename, 'csv', ['name' => '姓名', 'remark' => '备注'], $rows);

            $imported = SimpleExcel::import($filename, 'csv', ['姓名' => 'name', '备注' => 'remark']);

            $this->assertSame('张三, "测试"', $imported[0]['name']);
            $this->assertSame("第一行\n第二行", $imported[0]['remark']);
        } finally {
            @unlink($filename);
        }
    }

    /**
     * @test
     */
    public function testExportShouldWriteStringCellTypeAndBeReadable()
    {
        $filename = tempnam(sys_get_temp_dir(), 'simple_excel_xlsx_');
        if ($filename === false) {
            $this->fail('无法创建临时文件');
        }

        try {
            SimpleExcel::export($filename, 'xlsx', [
                'name' => '姓名',
                'idcard' => '身份证',
            ], [
                ['name' => '张三', 'idcard' => '522131199703213342'],
            ]);

            $this->assertFileExists($filename);

            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $spreadsheet = $reader->load($filename);
            $sheet = $spreadsheet->getSheet(0);

            $this->assertSame('姓名', $sheet->getCell('A1')->getValue());
            $this->assertSame('522131199703213342', $sheet->getCell('B2')->getValue());
            $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $sheet->getCell('B2')->getDataType());

            $spreadsheet->disconnectWorksheets();
        } finally {
            @unlink($filename);
        }
    }

    /**
     * @test
     */
    public function testExportShouldNotFailOnArrayValues()
    {
        $filename = tempnam(sys_get_temp_dir(), 'simple_excel_xlsx_');
        if ($filename === false) {
            $this->fail('无法创建临时文件');
        }

        try {
            SimpleExcel::export($filename, 'xlsx', ['name' => '姓名'], [
                ['name' => ['not', 'a', 'string']],
            ]);

            $this->assertFileExists($filename);
        } finally {
            @unlink($filename);
        }
    }

    /**
     * @test
     */
    public function testMalformedXlsxIsWrappedInRuntimeException()
    {
        $filename = tempnam(sys_get_temp_dir(), 'simple_excel_bad_');
        if ($filename === false) {
            $this->fail('无法创建临时文件');
        }

        file_put_contents($filename, 'this is not an xlsx file');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('文件解析失败');
            SimpleExcel::import($filename, 'xlsx', ['姓名' => 'name']);
        } finally {
            @unlink($filename);
        }
    }

    /**
     * @test
     */
    public function testSparseHugeXlsxIsRejectedByDefault()
    {
        $filename = tempnam(sys_get_temp_dir(), 'simple_excel_sparse_');
        if ($filename === false) {
            $this->fail('无法创建临时文件');
        }

        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setCellValue('A1', '姓名');
            $sheet->setCellValueExplicit(
                'XFD1048576',
                'x',
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($filename);
            $spreadsheet->disconnectWorksheets();

            try {
                SimpleExcel::import($filename, 'xlsx', ['姓名' => 'name']);
                $this->fail('稀疏大坐标文件应被默认限制拒绝');
            } catch (RuntimeException $e) {
                $message = $e->getMessage();
                $this->assertTrue(
                    strpos($message, '行数超过限制') !== false || strpos($message, '单元格数超过限制') !== false,
                    '实际异常信息: ' . $message
                );
            }
        } finally {
            @unlink($filename);
        }
    }

    /**
     * @test
     */
    public function testImportCanFailClosedOnVulnerableRuntime()
    {
        $filename = $this->createCsv("姓名\n张三\n");

        try {
            if (!RuntimeSecurity::isAffected()) {
                $this->markTestSkipped('当前 PHP 已包含 CVE 修复，无法验证 fail-closed 分支');
            }

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('CVE-2026-109618');
            SimpleExcel::import($filename, 'csv', ['姓名' => 'name'], true, ['assertSecureRuntime' => true]);
        } finally {
            @unlink($filename);
        }
    }

    private function createCsv(string $contents): string
    {
        $filename = tempnam(sys_get_temp_dir(), 'simple_excel_csv_');
        if ($filename === false) {
            $this->fail('无法创建临时文件');
        }

        file_put_contents($filename, $contents);

        return $filename;
    }
}
