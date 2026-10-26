<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Известные критические уязвимости самого интерпретатора PHP.
 *
 * Код сайта их не закрывает: уязвим разбор запроса до первой строки нашего
 * кода, и починить его можно только версией PHP у хостинга. Поэтому проверка
 * существует ради одного — чтобы владелец узнал об этом из «Состояния
 * системы», а не из журнала взлома.
 *
 * Версию отвечает веб-SAPI: на shared-хостинге консольный `php` и PHP сайта —
 * часто разные сборки, и `release_check` из cron видит не ту, что отвечает
 * посетителям.
 */
final class PhpAdvisory
{
    /**
     * PHP2Shell (CVE-2026-109618, CVE-2026-109619): RCE без входа при разборе
     * multipart-запроса. Исправлено 21.10.2026; ветки 8.1 и старше не
     * исправляются вовсе. Ключ — ветка, значение — первая исправленная версия.
     */
    public const PHP2SHELL_CVES = ['CVE-2026-109618', 'CVE-2026-109619'];
    public const PHP2SHELL_FIXED = [
        '8.2' => '8.2.35',
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ];

    /**
     * Ветки новее перечисленных вышли после исправления и уязвимыми не
     * считаются; ветки старше — уязвимы без исправления.
     *
     * @return array{vulnerable:bool, fixed:?string, cves:list<string>}
     */
    public static function check(string $version = PHP_VERSION): array
    {
        // «8.4.27-1+ubuntu» сравнивается по числовой части: суффикс сборки
        // version_compare читает как предрелиз и счёл бы версию младше.
        if (preg_match('/^\d+\.\d+\.\d+/', $version, $m) === 1) {
            $version = $m[0];
        }
        $branch = implode('.', array_slice(explode('.', $version), 0, 2));
        $fixed = self::PHP2SHELL_FIXED[$branch] ?? null;

        if ($fixed !== null) {
            $vulnerable = version_compare($version, $fixed, '<');
        } else {
            $vulnerable = version_compare($branch, '8.2', '<');
        }

        return [
            'vulnerable' => $vulnerable,
            'fixed' => $vulnerable ? ($fixed ?? '8.4.27') : null,
            'cves' => $vulnerable ? self::PHP2SHELL_CVES : [],
        ];
    }
}
