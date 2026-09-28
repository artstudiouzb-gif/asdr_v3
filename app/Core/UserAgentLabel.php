<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Короткое название устройства по строке User-Agent: «Chrome, Windows»,
 * «Safari, iPhone».
 *
 * Нужно в двух местах — список сессий в профиле и уведомление о входе в
 * Telegram, — и разбор жил функцией внутри вьюхи профиля. Вторая копия
 * разъехалась бы с первой: одно и то же устройство называлось бы в профиле
 * и в сообщении по-разному, а по этому названию владелец и сверяет «это был я».
 *
 * Это подпись, а не опознание: User-Agent присылает сам клиент и подделывается
 * одной строкой, поэтому решения по нему не принимаются.
 */
final class UserAgentLabel
{
    /**
     * Порядок важен: Edge, Opera и Яндекс.Браузер пишут в строке и «Chrome»,
     * а Chrome — «Safari», поэтому частные случаи идут раньше общих.
     */
    private const BROWSERS = [
        'YaBrowser' => 'Яндекс.Браузер',
        'SamsungBrowser' => 'Samsung Internet',
        'OPR/' => 'Opera',
        'Edg' => 'Edge',
        'FxiOS' => 'Firefox',
        'Firefox' => 'Firefox',
        'CriOS' => 'Chrome',
        'Chrome' => 'Chrome',
        'Safari' => 'Safari',
    ];

    /** iPhone и iPad раньше Mac: в их строке тоже бывает «Mac OS X». */
    private const SYSTEMS = [
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Android' => 'Android',
        'Windows' => 'Windows',
        'CrOS' => 'ChromeOS',
        'Mac' => 'macOS',
        'Linux' => 'Linux',
    ];

    public static function describe(?string $userAgent): string
    {
        $ua = trim((string) $userAgent);
        if ($ua === '') {
            return 'Не указано';
        }

        $browser = self::first(self::BROWSERS, $ua);
        $system = self::first(self::SYSTEMS, $ua);
        if ($browser === '' && $system === '') {
            // Скрипт или незнакомый клиент: показываем начало строки как есть —
            // «curl/8.5» владельцу скажет больше, чем «Неизвестное устройство».
            return mb_strimwidth($ua, 0, 60, '…');
        }

        return implode(', ', array_filter([$browser !== '' ? $browser : 'Браузер', $system]));
    }

    /** @param array<string, string> $map */
    private static function first(array $map, string $ua): string
    {
        foreach ($map as $needle => $label) {
            if (stripos($ua, $needle) !== false) {
                return $label;
            }
        }

        return '';
    }
}
