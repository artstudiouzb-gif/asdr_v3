<?php

declare(strict_types=1);

namespace App\Core\Hero;

use App\Core\Icon;
use App\Core\Media;
use App\Core\TitleMarkup;
use App\Core\UrlGuard;

/** Accessible controls shared by static video covers and carousels. */
final class HeroNavigation
{
    /**
     * Навигация: стрелки, индикатор, пауза автопрокрутки. Живёт отдельной
     * полосой и не накрывает контент — под неё в `.hero__inner` зарезервирован
     * нижний отступ.
     *
     * @param array<int, array<string, mixed>> $slides
     * @param array<string, mixed> $s
     */
    public static function render(array $slides, array $s, int $count): string
    {
        $motion = self::hasMotion($slides, $s);
        if ($count < 2 && !$motion) {
            return '';
        }

        $indicator = $count > 1 ? (string) $s['nav_indicator'] : 'none';
        $arrows = $count > 1 && (bool) $s['nav_arrows'];
        if (!$arrows && $indicator === 'none' && !$motion) {
            return '';
        }

        $prev = '<button type="button" class="hero__arrow hero__arrow--prev" data-hero-prev'
            . ' aria-label="' . htmlspecialchars(t('Предыдущий слайд'), ENT_QUOTES) . '">'
            . Icon::render('chevron-left', 22) . '</button>';
        $next = '<button type="button" class="hero__arrow hero__arrow--next" data-hero-next'
            . ' aria-label="' . htmlspecialchars(t('Следующий слайд'), ENT_QUOTES) . '">'
            . Icon::render('chevron-right', 22) . '</button>';

        // Внешний слой держит полосу на месте (ширина колонки сайта, отступ от
        // низа), внутренний — сама капсула, которая обжимает содержимое.
        // Двумя элементами, а не одним: капсуле нельзя задать и «во всю
        // ширину контейнера», и «по содержимому» одновременно.
        $html = '<div class="hero__nav"><div class="hero__nav-bar">';
        if ($motion) {
            // Остановить автопрокрутку должен уметь любой посетитель, а не
            // только тот, кто доведёт курсор до слайда (WCAG 2.2.2).
            $html .= '<button type="button" class="hero__playpause" data-hero-toggle data-paused="false"'
                . ' aria-label="' . htmlspecialchars(t('Остановить показ'), ENT_QUOTES) . '"'
                . ' data-label-play="' . htmlspecialchars(t('Продолжить показ'), ENT_QUOTES) . '"'
                . ' data-label-pause="' . htmlspecialchars(t('Остановить показ'), ENT_QUOTES) . '">'
                . '<span class="hero__playpause-icon hero__playpause-icon--pause" aria-hidden="true">'
                . Icon::render('player-pause', 18) . '</span>'
                . '<span class="hero__playpause-icon hero__playpause-icon--play" aria-hidden="true">'
                . Icon::render('player-play', 18) . '</span></button>';
        }
        if ($arrows) {
            $html .= $prev;
        }
        $html .= '<div class="hero__indicator">' . self::indicator($slides, $indicator, $count) . '</div>';
        if ($arrows) {
            $html .= $next;
        }
        $html .= '</div></div>'
            . '<span class="visually-hidden" data-hero-status aria-live="polite" aria-atomic="true"></span>';

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $slides
     */
    private static function indicator(array $slides, string $type, int $count): string
    {
        $total = str_pad((string) $count, 2, '0', STR_PAD_LEFT);

        $counter = '<span class="hero__counter" aria-hidden="true">'
            . '<span class="hero__counter-current" data-hero-current>01</span>'
            . '<span class="hero__counter-sep">/</span>'
            . '<span class="hero__counter-total">' . $total . '</span></span>';
        $progress = '<span class="hero__progress" aria-hidden="true">'
            . '<span class="hero__progress-bar" data-hero-progress></span></span>';

        return match ($type) {
            'dots' => self::dots($count),
            'counter' => $counter,
            'progress' => $progress,
            // Полоса идёт перед счётчиком: она показывает, сколько осталось до
            // следующего слайда, а счётчик — где мы сейчас. Слева направо это
            // читается как «время → позиция», а не наоборот.
            'counter_progress' => $progress . $counter,
            'thumbs' => self::thumbs($slides),
            default => '',
        };
    }

    private static function dots(int $count): string
    {
        $html = '<span class="hero__dots" role="group" aria-label="'
            . htmlspecialchars(t('Выбор слайда'), ENT_QUOTES) . '">';
        for ($i = 0; $i < $count; $i++) {
            $html .= '<button type="button" class="hero__dot' . ($i === 0 ? ' is-active' : '') . '"'
                . ' data-hero-goto="' . $i . '"'
                . ' aria-label="' . htmlspecialchars(t('Перейти к слайду'), ENT_QUOTES) . ' ' . ($i + 1) . '"'
                . ' aria-current="' . ($i === 0 ? 'true' : 'false') . '"></button>';
        }

        return $html . '</span>';
    }

    /** @param array<int, array<string, mixed>> $slides */
    private static function thumbs(array $slides): string
    {
        $html = '<span class="hero__thumbs" role="group" aria-label="'
            . htmlspecialchars(t('Выбор слайда'), ENT_QUOTES) . '">';
        foreach ($slides as $i => $slide) {
            $image = HeroSlideData::fallbackImage($slide['data']);
            $title = trim(TitleMarkup::plain((string) $slide['data']['title']));
            $label = $title !== '' ? $title : t('Перейти к слайду') . ' ' . ($i + 1);
            $html .= '<button type="button" class="hero__thumb' . ($i === 0 ? ' is-active' : '') . '"'
                . ' data-hero-goto="' . $i . '"'
                . ' aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '"'
                . ' aria-current="' . ($i === 0 ? 'true' : 'false') . '">'
                . ($image !== '' && UrlGuard::isSafeMedia($image)
                    ? Media::picture($image, '', 96, 54, '', true, '48px')
                    : '<span class="hero__thumb-num" aria-hidden="true">' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . '</span>')
                . '</button>';
        }

        return $html . '</span>';
    }

    /** @param array<int, array<string, mixed>> $slides
     *  @param array<string, mixed> $settings */
    public static function hasMotion(array $slides, array $settings): bool
    {
        if (count($slides) > 1 && $settings['autoplay']) {
            return true;
        }
        if ($settings['transition'] === 'kenburns') {
            return true;
        }
        foreach ($slides as $slide) {
            if (in_array($slide['data']['media_type'], ['video', 'youtube'], true)) {
                return true;
            }
        }

        return false;
    }
}
