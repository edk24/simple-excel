<?php

declare(strict_types=1);

/**
 * 不依赖 PHPUnit 的兼容性冒烟测试。
 *
 * 用于在 PHP 8.4 / 8.5 等较新运行时上验证本库与 PhpSpreadsheet 的基本导入导出链路，
 * 避免 PHPUnit 9.x 自身对新版 PHP 的支持问题影响 CI。
 *
 * 运行：php test/smoke.php
 */

require __DIR__ . '/../vendor/autoload.php';

use yuxiaobo\library\RuntimeSecurity;
use yuxiaobo\library\SimpleExcel;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[OK] ' . $message . PHP_EOL;
        return;
    }

    $failures++;
    fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
};

$psVersion = 'unknown';
if (class_exists('Composer\\InstalledVersions')) {
    $psVersion = \Composer\InstalledVersions::getPrettyVersion('phpoffice/phpspreadsheet') ?? 'unknown';
}

echo 'PHP ' . PHP_VERSION . ' / PhpSpreadsheet ' . $psVersion . PHP_EOL;
echo 'RuntimeSecurity: ' . (RuntimeSecurity::isAffected() ? 'AFFECTED' : 'patched')
    . ' (recommended ' . RuntimeSecurity::recommendedVersion() . ')' . PHP_EOL;

$dir = sys_get_temp_dir() . '/simple_excel_smoke_' . getmypid() . '_' . bin2hex(random_bytes(4));
if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, 'Cannot create temp directory: ' . $dir . PHP_EOL);
    exit(1);
}

try {
    // 1. xlsx 导入
    $rows = SimpleExcel::import(dirname(__DIR__) . '/test/test.xlsx', 'xlsx', [
        '姓名' => 'name',
        '年龄' => 'age',
        '性别' => 'gender',
        '时间' => 'time',
    ]);
    $check(is_array($rows) && $rows !== [], 'xlsx import returns rows');

    // 2. xlsx 导出 + 回读
    $xlsx = $dir . '/export.xlsx';
    SimpleExcel::export($xlsx, 'xlsx', [
        'name' => '姓名',
        'idcard' => '身份证',
        'mobile' => '手机号',
    ], [
        ['name' => '张三', 'idcard' => '522131199703213342', 'mobile' => '18300000000'],
        ['name' => "李四\n换行", 'idcard' => '522131199703213343', 'mobile' => '18300000001'],
    ]);
    $check(is_file($xlsx) && filesize($xlsx) > 0, 'xlsx export writes file');

    $imported = SimpleExcel::import($xlsx, 'xlsx', [
        '姓名' => 'name',
        '身份证' => 'idcard',
        '手机号' => 'mobile',
    ], true, ['readDataOnly' => true]);
    $check(isset($imported[0]['name']) && $imported[0]['name'] === '张三', 'xlsx export/import round trip');

    // 3. xls 导出 + 回读（如果当前 PhpSpreadsheet 支持）
    $xls = $dir . '/export.xls';
    SimpleExcel::export($xls, 'xls', ['name' => '姓名'], [['name' => '王五']]);
    $check(is_file($xls) && filesize($xls) > 0, 'xls export writes file');
    $xlsRows = SimpleExcel::import($xls, 'xls', ['姓名' => 'name']);
    $check(isset($xlsRows[0]['name']) && $xlsRows[0]['name'] === '王五', 'xls export/import round trip');

    // 4. CSV 导出 + 回读（引号、逗号、换行）
    $csv = $dir . '/export.csv';
    SimpleExcel::export($csv, 'csv', [
        'name' => '姓名',
        'remark' => '备注',
    ], [
        ['name' => '张三, "测试"', 'remark' => "第一行\n第二行"],
    ]);
    $csvRows = SimpleExcel::import($csv, 'csv', ['姓名' => 'name', '备注' => 'remark']);
    $check($csvRows[0]['name'] === '张三, "测试"', 'csv round trip keeps quotes/comma');
    $check($csvRows[0]['remark'] === "第一行\n第二行", 'csv round trip keeps newline');

    // 5. UTF-8 BOM + 数字 0 不被当成空行
    $bom = $dir . '/bom.csv';
    file_put_contents($bom, "\xEF\xBB\xBF姓名,年龄\n张三,0\n");
    $bomRows = SimpleExcel::import($bom, 'csv', ['姓名' => 'name', '年龄' => 'age']);
    $check($bomRows[0]['name'] === '张三', 'csv import strips UTF-8 BOM');
    $check($bomRows[0]['age'] === '0', 'csv import keeps zero value');

    // 6. 资源限制
    $limitHit = false;
    try {
        SimpleExcel::import($bom, 'csv', ['姓名' => 'name'], true, ['maxRows' => 1]);
    } catch (\RuntimeException $e) {
        $limitHit = strpos($e->getMessage(), '行数超过限制') !== false;
    }
    $check($limitHit, 'csv import enforces maxRows');

    // 7. CVE 版本检测
    $check(RuntimeSecurity::isAffected('8.3.35') === true, 'CVE detection: 8.3.35 affected');
    $check(RuntimeSecurity::isAffected('8.3.36') === false, 'CVE detection: 8.3.36 patched');
    $check(RuntimeSecurity::isAffected('8.4.27') === false, 'CVE detection: 8.4.27 patched');
    $check(RuntimeSecurity::isAffected('8.5.12') === false, 'CVE detection: 8.5.12 patched');
} finally {
    $files = glob($dir . '/*');
    if (is_array($files)) {
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
    @rmdir($dir);
}

if ($failures > 0) {
    fwrite(STDERR, 'SMOKE FAILED: ' . $failures . ' check(s)' . PHP_EOL);
    exit(1);
}

echo 'SMOKE OK' . PHP_EOL;
