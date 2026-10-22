<?php

declare(strict_types=1);

namespace yuxiaobo\library;

use RuntimeException;

/**
 * PHP 运行时安全基线检查。
 *
 * CVE-2026-109618 / CVE-2026-109619 是 PHP 解释器本身的漏洞，不是本扩展包的漏洞：
 * - CVE-2026-109618：OOM 处理路径中的内存破坏，可导致未认证 RCE；
 * - CVE-2026-109619：请求结束后全局状态未正确重置，后续请求可消费 stale 状态，可导致未认证 RCE。
 *
 * 唯一完整修复方式是升级 PHP 运行时并重启所有 worker。本类用于在应用启动或导入入口
 * 主动检测版本，避免在已知受影响的运行时上继续处理不可信上传文件。
 *
 * @see https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w
 * @see https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp
 */
final class RuntimeSecurity
{
    /**
     * OOM 导致内存破坏（CVE-2026-109618）
     */
    public const CVE_OOM = 'CVE-2026-109618';

    /**
     * 跨请求 stale global state 导致 use-after-free（CVE-2026-109619）
     */
    public const CVE_UAF = 'CVE-2026-109619';

    /**
     * 各分支的官方修复版本，键为 PHP 次版本号。
     *
     * @var array<int, string>
     */
    private const PATCHED_RELEASES = [
        802 => '8.2.35',
        803 => '8.3.36',
        804 => '8.4.27',
        805 => '8.5.12',
    ];

    /**
     * 当前最新修复版本
     */
    private const LATEST_PATCHED = '8.5.12';

    /**
     * 本库支持的最低 PHP 版本
     */
    private const MIN_SUPPORTED_VERSION_ID = 70400;

    /**
     * 返回当前（或指定）PHP 版本的安全状态。
     *
     * @param string|null $phpVersion 传入版本号仅用于测试/巡检；默认检测当前运行时
     * @return array{
     *     version: string,
     *     version_id: int,
     *     affected: bool,
     *     supported: bool,
     *     recommended_version: string,
     *     latest_patched_version: string,
     *     cves: array<int, string>,
     *     message: string,
     *     mitigations: array<int, string>
     * }
     */
    public static function status(?string $phpVersion = null): array
    {
        $version = $phpVersion ?? PHP_VERSION;
        $affected = self::isAffected($version);
        $recommended = self::recommendedVersion($version);

        if ($affected) {
            $message = sprintf(
                '当前 PHP %s 受 %s / %s 影响，攻击者可利用 OOM 或跨请求 stale 状态触发内存破坏并执行任意代码（RCE）。'
                . '请升级到 PHP >= %s（当前最新修复版本：%s），升级后必须重启所有 PHP-FPM / Apache worker。',
                $version,
                self::CVE_OOM,
                self::CVE_UAF,
                $recommended,
                self::LATEST_PATCHED
            );
        } else {
            $message = sprintf('当前 PHP %s 已包含 %s / %s 的官方修复。', $version, self::CVE_OOM, self::CVE_UAF);
        }

        return [
            'version' => $version,
            'version_id' => self::versionId($version),
            'affected' => $affected,
            'supported' => self::isSupported($version),
            'recommended_version' => $recommended,
            'latest_patched_version' => self::LATEST_PATCHED,
            'cves' => [self::CVE_OOM, self::CVE_UAF],
            'message' => $message,
            'mitigations' => self::mitigations(),
        ];
    }

    /**
     * 当前（或指定）PHP 版本是否受这两个 CVE 影响。
     */
    public static function isAffected(?string $phpVersion = null): bool
    {
        $id = self::versionId($phpVersion ?? PHP_VERSION);
        if ($id === 0) {
            // 无法识别的版本按受影响处理，避免误报安全
            return true;
        }

        $major = intdiv($id, 10000);
        if ($major > 8) {
            return false;
        }
        if ($major < 8) {
            return true;
        }

        $branch = intdiv($id, 100);
        if (isset(self::PATCHED_RELEASES[$branch])) {
            return $id < self::versionId(self::PATCHED_RELEASES[$branch]);
        }

        // 8.0 / 8.1 已停止常规支持，没有官方二进制修复版本；8.6+ 视为已包含修复
        return $branch <= 801;
    }

    /**
     * 当前（或指定）PHP 版本是否在官方支持范围内。
     */
    public static function isSupported(?string $phpVersion = null): bool
    {
        return self::versionId($phpVersion ?? PHP_VERSION) >= self::MIN_SUPPORTED_VERSION_ID;
    }

    /**
     * 当前分支应升级到的最低修复版本。
     */
    public static function recommendedVersion(?string $phpVersion = null): string
    {
        $version = $phpVersion ?? PHP_VERSION;
        $id = self::versionId($version);
        $major = intdiv($id, 10000);
        $branch = intdiv($id, 100);

        if ($major === 8 && isset(self::PATCHED_RELEASES[$branch])) {
            $patched = self::PATCHED_RELEASES[$branch];

            return $id >= self::versionId($patched) ? $version : $patched;
        }

        // 8.6+ / 9.x 已包含修复
        if ($major > 8 || ($major === 8 && $branch > 805)) {
            return $version;
        }

        // 7.x / 8.0 / 8.1 没有官方修复版本，给出最低安全 LTS 目标
        return self::PATCHED_RELEASES[803];
    }

    /**
     * 受影响的运行时直接抛出异常，适合在导入不可信文件前 fail-closed。
     *
     * @throws RuntimeException
     */
    public static function assertSecure(?string $phpVersion = null): void
    {
        if (!self::isAffected($phpVersion)) {
            return;
        }

        $status = self::status($phpVersion);
        throw new RuntimeException(
            $status['message']
            . ' 注意：本库的输入限制、异常收敛等加固只能降低触发概率，不能替代 PHP 运行时升级。'
        );
    }

    /**
     * 受影响时返回告警文案，安全时返回 null。
     */
    public static function warning(?string $phpVersion = null): ?string
    {
        return self::isAffected($phpVersion) ? self::status($phpVersion)['message'] : null;
    }

    /**
     * 官方建议的临时缓解措施（均不能替代升级）。
     *
     * @return array<int, string>
     */
    public static function mitigations(): array
    {
        return [
            '升级到官方修复版本：8.2.35 / 8.3.36 / 8.4.27 / 8.5.12 或更高，并重启所有 PHP-FPM / Apache worker。',
            '无法立即升级时，限制上传体积（upload_max_filesize、post_max_size、网关/前端限制），避免攻击者可控的 OOM。',
            '避免在导入/导出等不可信输入路径产生未捕获的 fatal error、warning 或 deprecation；本库已收敛解析异常并限制输入规模。',
            'PHP 8.5 可设置 fatal_error_backtraces=0；不需要上传进度时设置 session.upload_progress.enabled=0。',
            '可设置 pm.max_requests=1 缩短 worker 生命周期（牺牲性能），降低跨请求 stale 状态被复用的概率。',
            '不要仅依赖 memory_limit 缓解；仅安装官方安全补丁才是完整修复。',
        ];
    }

    /**
     * 将版本号转换为 PHP_VERSION_ID 风格的整数。
     */
    private static function versionId(string $version): int
    {
        if ($version === PHP_VERSION) {
            return PHP_VERSION_ID;
        }

        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $matches) === 1) {
            return ((int) $matches[1] * 10000) + ((int) $matches[2] * 100) + (int) $matches[3];
        }

        return 0;
    }
}
