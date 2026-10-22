# Security Policy

## Supported Versions

| Package version | PHP | Status |
| --- | --- | --- |
| `dev-main` | 7.4 – 8.5 | Supported |
| `0.1.x` | 7.3 – 8.4 | Security fixes only |

Recommended runtime for untrusted file uploads:

- PHP **8.2.35+**, **8.3.36+**, **8.4.27+**, or **8.5.12+**
- PhpSpreadsheet **1.30.3+** (1.30.x), **2.1.15** (2.1.x), **2.4.4+**, or **3.x / 4.x / 5.x** (Composer resolves this automatically)

## CVE-2026-109618 / CVE-2026-109619

These are **PHP interpreter vulnerabilities**, not vulnerabilities in this package or PhpSpreadsheet. Updating this package does not fix them; the only complete remediation is upgrading PHP and restarting all workers.

| CVE | Impact | Affected | Fixed |
| --- | --- | --- | --- |
| `CVE-2026-109618` | OOM handling leaves request memory in an invalid state; memory corruption / use-after-free leading to unauthenticated RCE | PHP `< 8.2.35`, `< 8.3.36`, `< 8.4.27`, `< 8.5.12` | 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12 |
| `CVE-2026-109619` | Request-owned global state survives after request memory is freed; a later request on the same worker can consume stale state, leading to unauthenticated RCE | PHP `< 8.2.35`, `< 8.3.36`, `< 8.4.27`, `< 8.5.12` | 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12 |

Official advisories:

- <https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w> (`CVE-2026-109618`)
- <https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp> (`CVE-2026-109619`)

### Mitigation

1. **Upgrade PHP** to a patched release and restart PHP-FPM / Apache workers. This is the only complete fix.
2. If an immediate upgrade is impossible:
   - Restrict `upload_max_filesize`, `post_max_size`, and decoded request size at the gateway/frontend.
   - On PHP 8.5, set `fatal_error_backtraces=0`.
   - Set `session.upload_progress.enabled=0` if upload progress is not needed.
   - Consider `pm.max_requests=1` (at a performance cost) to shorten worker lifetime.
   - Do **not** rely on `memory_limit` alone.
3. Reduce attacker-controlled OOM/fatal-error paths in application code, including Excel import/export routes.

## What This Package Does

- `RuntimeSecurity::status()` detects affected PHP versions and returns the recommended patched release plus official mitigations.
- `RuntimeSecurity::assertSecure()` provides an opt-in fail-closed check.
- `SimpleExcel::import()` supports file-size, row, column, and cell limits, plus `readDataOnly` to reduce memory usage.
- Parser failures are converted to `RuntimeException` and worksheets are disconnected in `finally`.
- CSV parsing/writing uses RFC 4180 (`escape=''`) so PHP 8.4+ does not emit a deprecation warning.
- Download headers and the download error page avoid emitting warnings after output has started.

These measures reduce exposure but **cannot replace a PHP runtime upgrade**.

## Reporting a Vulnerability

Please report suspected vulnerabilities privately to the maintainer:

- Email: `yuxiaobo64@gmail.com`
- Or open a GitHub security advisory on the repository.

Please include the package version, PHP version, PhpSpreadsheet version, a minimal reproduction, and the expected impact. Do not open a public issue before a fix is available.
