---
name: simple-excel-install-usage
description: Install and integrate yuxiaobo/simple-excel in PHP projects. Use when users ask to add Excel import/export support, generate import/export code for xls/xlsx/csv, output browser downloads via php://output, or troubleshoot runtime issues like memory limits, timeout, header/output conflicts, and Excel date/number formatting.
---

# Simple Excel Install Usage

## Overview

Implement `yuxiaobo/simple-excel` end-to-end: dependency install, import/export implementation, browser download flow, and basic troubleshooting.
Keep output production-ready by mapping fields explicitly and using the library's existing API only.

## Workflow

1. Confirm prerequisites.
2. Install dependency with Composer.
3. Implement import or export flow based on user task.
4. Add browser download flow when target is HTTP response.
5. Validate with a minimal runnable example and common failure checks.

## Prerequisites

- PHP `>= 7.3`
- Composer available in environment
- Project uses autoload from Composer (`vendor/autoload.php`)

## Install

Run:

```bash
composer require yuxiaobo/simple-excel
```

Use class:

```php
use yuxiaobo\library\SimpleExcel;
```

## API Usage

### Import (`xls/xlsx/csv`)

```php
$rows = SimpleExcel::import(
    '/tmp/users.xlsx',
    'xlsx',
    [
        '姓名' => 'name',
        '年龄' => 'age',
        '性别' => 'gender',
    ],
    true // ignoreEmptyRow
);
```

Rules:
- Always pass a field map (`Excel header => target key`) to avoid implicit column coupling.
- Use `fileType` as one of: `xls`, `xlsx`, `csv`.
- For Excel time serial values, convert with `\PhpOffice\PhpSpreadsheet\Shared\Date`.

### Export to file

```php
SimpleExcel::export(
    '/tmp/export.xlsx',
    'xlsx',
    [
        'name' => '姓名',
        'mobile' => '手机号',
    ],
    [
        ['name' => '张三', 'mobile' => '18300000000'],
        ['name' => '李四', 'mobile' => '18300000001'],
    ],
    '#ffffff',
    '#3573dd',
    '#333333'
);
```

### Export to browser download

```php
SimpleExcel::setDownloadHeader('导出数据.xlsx');
SimpleExcel::export(
    'php://output',
    'xlsx',
    ['name' => '姓名'],
    [['name' => '张三']]
);
```

Behavior note:
- If export fails when `fileName` is `php://output`, the library renders a friendly HTML error page instead of throwing to break the page.
- If exporting to a normal file path, exceptions are still thrown and should be handled by caller code.

## Troubleshooting Checklist

1. `Allowed memory size exhausted`
- Increase memory (`ini_set('memory_limit', '256M')` or higher).
- Prefer CSV for very large datasets.

2. Request timeout
- Increase execution time (`ini_set('max_execution_time', 600)`).
- Use async jobs/queues for heavy export tasks.

3. Long number shown as scientific notation
- Keep default export behavior (cells are written as string type).

4. Output/header conflicts in browser download
- Call `SimpleExcel::setDownloadHeader(...)` before `export('php://output', ...)`.
- Ensure no debug output/echo before sending headers.

## Response Template

When user asks for installation + usage, return:
1. Exact install command
2. Minimal import/export code snippet matching their format (`xls/xlsx/csv`)
3. Download-mode snippet if requested for web
4. One short "common pitfalls" section tied to their scenario
