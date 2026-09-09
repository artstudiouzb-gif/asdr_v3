<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Иконки соцсетей из локального спрайта Tabler. Сеть — свободный текст из
 * конструктора; известные названия получают фирменный глиф (currentColor),
 * неизвестные — фолбэк на первую букву, как раньше.
 */
final class SocialIcons
{
    private const ICONS = [
        'telegram' => 'brand-telegram',
        'facebook' => 'brand-facebook',
        'instagram' => 'brand-instagram',
        'youtube' => 'brand-youtube',
        'x' => 'brand-x',
        'twitter' => 'brand-x',
        'linkedin' => 'brand-linkedin',
        'vk' => 'brand-vk',
        'odnoklassniki' => 'brand-ok-ru',
        'ok' => 'brand-ok-ru',
    ];

    /** Разметка кнопки: фирменный SVG или первая буква сети (фолбэк). */
    public static function glyph(string $network, int $size = 16): string
    {
        $key = strtolower(trim($network));
        if (isset(self::ICONS[$key])) {
            return Icon::render(self::ICONS[$key], $size, 'social-icon', 1.7);
        }

        return htmlspecialchars(mb_strtoupper(mb_substr($network, 0, 1)), ENT_QUOTES);
    }
}
