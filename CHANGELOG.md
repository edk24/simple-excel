# 更新记录

## dev-main

### Security

- `SECURITY` 新增 `RuntimeSecurity`：检测 PHP 是否受 `CVE-2026-109618`（OOM 内存破坏）和 `CVE-2026-109619`（跨请求 stale 全局状态 UAF）影响，给出对应分支修复版本与官方缓解措施；支持 `status()` / `isAffected()` / `assertSecure()`
- `SECURITY` `SimpleExcel::import()` 新增第 5 个参数 `$options`：支持 `maxFileSize` / `maxRows` / `maxColumns` / `maxCells` / `readDataOnly` / `assertSecureRuntime`；`xls/xlsx` 默认按 Excel 硬限制（1048576 行、16384 列、10000000 单元格）兜底
- `SECURITY` 解析器异常统一收敛为 `RuntimeException`，并在 `finally` 中 `disconnectWorksheets()`，减少 OOM / 未捕获 fatal error 路径
- `SECURITY` 下载响应头、输出缓冲区、错误页不再产生 warning；`setDownloadHeader()` 增加响应头注入防护和 `X-Content-Type-Options: nosniff`
- `SECURITY` CSV 统一使用 RFC 4180（`CSV_ESCAPE = ''`），不再触发 PHP 8.4+ 对非空 `$escape` 的 deprecation（0.1.4 的“兼容”写法实际仍会触发 deprecation）；确需兼容历史反斜杠转义时可通过 `csvEscape` 选项临时恢复

### Compatibility

- `COMPAT` 支持 PHP 7.4 – 8.5；`composer.json` 要求 `php: ^7.4 || ^8.0`
- `COMPAT` PhpSpreadsheet 约束升级为 `^1.30.3 || >=2.1.15 <2.2.0 || >=2.4.4 <3.0 || ^3.0 || ^4.0 || ^5.0`，排除含已知漏洞的旧分支
- `COMPAT` 已在 PhpSpreadsheet `1.30.7` 和 `5.10.0` 上通过全部测试
- `CI` 矩阵扩展至 PHP 7.4 / 8.0 / 8.1 / 8.2 / 8.3 / 8.4 / 8.5；新增 `composer audit`、语法检查和最低依赖任务
- `TEST` 新增 `test/SecurityAndCompatibilityTest.php` 与不依赖 PHPUnit 的 `test/smoke.php`（用于 PHP 8.4/8.5）

### Fixed / Performance

- `FIX` 空行判断不再把 `0`、`"0"`、`false` 当成空行（原 `array_filter` 会误删）
- `FIX` CSV 导入自动去除 UTF-8 BOM，避免第一个表头匹配失败
- `PERF` 导出表头/数据样式改为按区域一次性应用，避免逐单元格 `getStyle()`；移除重复的 `setAutoSize()`
- `PERF` 导出时关闭无意义的公式预计算；导入支持 `readDataOnly`
- `FIX` 导出数组/对象/null 值时统一转字符串，不再触发写入错误

## 0.1.4

- `FIX` 导出到 `php://output` 失败时不再抛异常导致页面崩溃，改为降级输出友好 HTML 错误页面
- `FIX` 修复 CSV 导出列错位问题，数据按表头字段顺序写入
- `FIX` 增强 CSV 导入健壮性：空文件/读取失败报明确异常，列数不一致时自动补齐或截断
- `FIX` 兼容 PHP 8.4+ 对 `fgetcsv/fputcsv` 的 `escape` 参数要求
- `TEST` 新增导出失败降级与 CSV 回归测试；性能测试归类为 `performance` 并默认排除
- `DOC` 更新 README：同步依赖版本说明并补充下载失败降级行为说明

## 0.1.3

- `FEAT` 增加自动列宽 @J2zl
- `FEAT` 允许多行文本自动换行 且 水平垂直居中 @J2zl
- `FEAT` 导出 excel 单元格数据全部为字符串格式, 避免出现科学计算法 @J2zl

## 0.1.2

- `FIX` 修复常量名称错误导致的无法导出问题

## 0.1.1

- `FIX` 修复ob_end_clean造成的异常抛出

## 0.1.0

- `FEAT` 增加 excel 导入遇到空行(有格式但没有数据) 的参数配置, 默认跳过空行

## 0.0.9

- `FEAT` 增加 excel 导入遇到空行(有格式但没有数据) 的参数配置, 默认跳过空行

## 0.0.8

- `FIX` 明确最低 php 版本要求为 7.3

## 0.0.7

- `FIX` 导出数据中包含 `null` 报错；
- `FIX` 导出下载时遇报错，更改响应头为 `text/html` 便于查看问题；
