<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Метка новости («Важно», «Анонс») — необязательная пометка на карточке.
 *
 * Рубрику метка больше не изображает: для этого есть категория. Осталась
 * задача выделить одну новость среди соседних, поэтому у метки есть цвет
 * фона. Цвет текста не хранится и не настраивается — он считается из фона по
 * контрасту: иначе редактор мог бы выбрать белый по жёлтому и получить
 * нечитаемую плашку, а проверить это на всех языках и темах некому.
 *
 * Цвет приходит CSS-переменными в атрибуте style, а не scoped-CSS блока.
 * Так сделано намеренно: цвет принадлежит записи, а не блоку, и одна и та же
 * новость выводится и в блоке страницы, и в ленте, и на детальной — правило,
 * привязанное к блоку, до двух других мест просто не дошло бы.
 */
final class NewsBadge
{
    /** Готовые пометки: подсказка редактору, а не закрытый список. */
    public const PRESETS = ['Важно', 'Новое', 'Анонс', 'Обновлено'];

    /**
     * Цвет по умолчанию — не «серый на всякий случай», а оттенок акцента
     * темы: метка без выбранного цвета должна выглядеть частью сайта.
     * Пустая строка означает «взять значение из CSS», где и живёт оттенок.
     */
    public static function normalizeColor(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return '';
        }
        if (preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/', $value, $m) !== 1) {
            return '';
        }

        $hex = $m[1];
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return '#' . $hex;
    }

    /** Контрастный цвет текста для выбранного фона. */
    public static function textColor(string $background): string
    {
        return AccentContrast::onFill($background);
    }

    /**
     * Атрибут style с переменными фона и текста. Пустая строка, если цвет не
     * выбран: тогда работают значения по умолчанию из темы.
     */
    public static function styleAttr(mixed $color): string
    {
        $bg = self::normalizeColor($color);
        if ($bg === '') {
            return '';
        }

        return sprintf(
            ' style="--news-badge-bg:%s;--news-badge-fg:%s"',
            htmlspecialchars($bg, ENT_QUOTES),
            htmlspecialchars(self::textColor($bg), ENT_QUOTES)
        );
    }

    /**
     * Готовая разметка метки. Пустой текст — пустая строка: новость без метки
     * не должна оставлять на карточке пустую плашку.
     */
    public static function render(mixed $text, mixed $color = null, bool $onMedia = false): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }

        return sprintf(
            '<span class="news-badge%s"%s>%s</span>',
            $onMedia ? ' news-badge--on-media' : '',
            self::styleAttr($color),
            htmlspecialchars($text, ENT_QUOTES)
        );
    }

    /**
     * Метка углом на обложке карточки. Отдельный вид, а не модификатор
     * render(): здесь метка позиционируется поверх картинки, и без своего
     * цвета ей нужен непрозрачный фон — на светлом снимке полупрозрачная
     * плашка становится нечитаемой.
     */
    public static function renderOverlay(mixed $text, mixed $color = null): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }

        return sprintf(
            '<span class="news-badge news-badge--overlay"%s>%s</span>',
            self::styleAttr($color),
            htmlspecialchars($text, ENT_QUOTES)
        );
    }
}
