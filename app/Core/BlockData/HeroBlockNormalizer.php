<?php

declare(strict_types=1);

namespace App\Core\BlockData;

use App\Core\MediaPosition;
use App\Core\Video;

/**
 * Преобразует поля формы Hero в стабильную JSON-структуру блока.
 *
 * Класс не читает $_POST: явный вход делает правила проверяемыми отдельно от
 * HTTP-контроллера и позволяет постепенно переносить сюда остальные блоки.
 */
final class HeroBlockNormalizer
{
    /** Больше десяти слайдов посетитель всё равно не досмотрит. */
    public const MAX_SLIDES = 10;

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input, string $locale = 'ru'): array
    {
        $bgType = (string) ($input['bg_type'] ?? 'none');
        $bgType = in_array($bgType, ['none', 'image', 'video', 'youtube'], true) ? $bgType : 'none';
        $youtubeUrl = trim((string) ($input['youtube_url'] ?? ''));
        $videoUrl = BlockDataInput::safeMedia($input['video_url'] ?? '');
        $image = BlockDataInput::safeMedia($input['image'] ?? '');

        // Заполненное медиа — явное намерение редактора, даже если список
        // «Фон секции» остался в положении «Без фона».
        if ($bgType === 'none' && Video::youtubeId($youtubeUrl) !== null) {
            $bgType = 'youtube';
        } elseif ($bgType === 'none' && $videoUrl !== '') {
            $bgType = 'video';
        } elseif ($bgType === 'none' && $image !== '') {
            $bgType = 'image';
        }

        $heightMode = (string) ($input['hero_height'] ?? 'regular');
        $heightMode = in_array($heightMode, ['regular', 'full', 'custom'], true) ? $heightMode : 'regular';
        $heightUnit = (string) ($input['hero_height_unit'] ?? 'px');
        $heightUnit = in_array($heightUnit, ['px', 'vh', 'dvh', 'rem'], true) ? $heightUnit : 'px';
        $heightValue = is_numeric($input['hero_height_value'] ?? null) ? (float) $input['hero_height_value'] : 720.0;
        $heightLimits = $heightUnit === 'px' ? [160.0, 2000.0]
            : ($heightUnit === 'rem' ? [10.0, 120.0] : [20.0, 150.0]);
        $heightValue = max($heightLimits[0], min($heightLimits[1], $heightValue));

        // Телефон: своя высота (пусто = как на десктопе) и свой кадр. Широкий
        // кадр 21:9 на узком экране режется до полоски, а общей высотой это
        // лечится только ценой десктопа.
        $mobileHeightMode = BlockDataInput::enum($input, 'hero_height_mobile', ['regular', 'full', 'custom'], '');
        $mobileHeight = '';
        if ($mobileHeightMode === 'custom') {
            $mobileUnit = BlockDataInput::enum($input, 'hero_height_mobile_unit', ['px', 'vh', 'dvh', 'rem'], 'px');
            $mobileValue = is_numeric($input['hero_height_mobile_value'] ?? null)
                ? (float) $input['hero_height_mobile_value']
                : 420.0;
            $mobileLimits = $mobileUnit === 'px' ? [160.0, 1200.0]
                : ($mobileUnit === 'rem' ? [10.0, 80.0] : [20.0, 150.0]);
            $mobileValue = max($mobileLimits[0], min($mobileLimits[1], $mobileValue));
            $mobileHeight = self::number($mobileValue) . $mobileUnit;
        }

        $rawOverlayDirection = (string) ($input['overlay_direction'] ?? 'auto');
        $overlayMode = (string) ($input['overlay_mode'] ?? 'gradient');
        $overlayMode = in_array($overlayMode, ['solid', 'gradient'], true) ? $overlayMode : 'gradient';
        $overlayDirections = ['auto', 'to_right', 'to_left', 'to_bottom', 'to_top', 'to_bottom_right', 'to_bottom_left', 'to_top_right', 'to_top_left'];
        $overlayDirection = in_array($rawOverlayDirection, $overlayDirections, true) ? $rawOverlayDirection : 'auto';

        $textWidth = '';
        if (is_numeric($input['text_width_value'] ?? null)) {
            $textWidthUnit = (string) ($input['text_width_unit'] ?? 'px');
            $textWidthUnit = in_array($textWidthUnit, ['px', '%', 'vw'], true) ? $textWidthUnit : 'px';
            $textWidthLimits = $textWidthUnit === 'px' ? [200.0, 2000.0] : [10.0, 100.0];
            $textWidthValue = max($textWidthLimits[0], min($textWidthLimits[1], (float) $input['text_width_value']));
            $textWidth = self::number($textWidthValue) . $textWidthUnit;
        }

        $textPosition = (string) ($input['text_position'] ?? 'left');

        // Выбранная обложка (тип контента «Обложки»). Остальные поля всё равно
        // сохраняем: редактор может вернуть блок к собственным настройкам, и
        // терять при этом ранее набранный текст незачем.
        $heroId = max(0, (int) ($input['hero_id'] ?? 0));

        return [
            'hero_id' => $heroId,
            'title' => BlockDataInput::plain($input, 'title_field', $locale),
            'width' => ($input['hero_width'] ?? 'full') === 'standard' ? 'standard' : 'full',
            'height' => $heightMode,
            'custom_height' => self::number($heightValue) . $heightUnit,
            'eyebrow' => BlockDataInput::plain($input, 'eyebrow', $locale),
            'subtitle' => BlockDataInput::plain($input, 'subtitle', $locale),
            'bg_type' => $bgType,
            'image' => $image,
            // Кадр для телефона: пусто — показываем общий.
            'image_mobile' => BlockDataInput::safeMedia($input['image_mobile'] ?? ''),
            // Фоновое видео на мобильном интернете весит дороже, чем стоит:
            // по умолчанию телефону достаётся постер.
            'video_mobile' => BlockDataInput::enum($input, 'video_mobile', ['poster', 'play'], 'poster'),
            'height_mobile' => $mobileHeightMode,
            'custom_height_mobile' => $mobileHeight,
            'image_position' => MediaPosition::normalize($input['image_position'] ?? null),
            'image_position_mobile' => MediaPosition::normalize($input['image_position_mobile'] ?? null),
            'video_url' => $videoUrl,
            'youtube_url' => $youtubeUrl,
            'overlay_enabled' => !empty($input['overlay_enabled']),
            'overlay_mode' => $overlayMode,
            'overlay_direction' => $overlayDirection,
            'overlay_color' => self::hexOrDefault($input['overlay_color'] ?? '', '#0b1a30'),
            'overlay_opacity' => self::percentage($input['overlay_opacity'] ?? null, 35),
            'text_position' => in_array($textPosition, ['left', 'center', 'right'], true) ? $textPosition : 'left',
            // Вертикаль: у высокой обложки текст часто прижимают к низу, чтобы
            // не закрывать лицо на фото или небо в кадре.
            'text_align_y' => BlockDataInput::enum($input, 'text_align_y', ['top', 'center', 'bottom'], 'center'),
            // Картинка поверх фона: эмблема, логотип программы, иллюстрация.
            // Кладётся рядом с текстом, а не под него, поэтому у неё своя
            // позиция и размер.
            'art_image' => BlockDataInput::safeMedia($input['art_image'] ?? ''),
            // Пустое описание = картинка декоративная. Для логотипа программы
            // текст обязателен: без него он пропадает и для скринридера, и
            // для поиска.
            'art_alt' => BlockDataInput::plain($input, 'art_alt', $locale),
            'art_position' => BlockDataInput::enum($input, 'art_position', ['above', 'left', 'right'], 'above'),
            'art_size' => BlockDataInput::enum($input, 'art_size', ['small', 'medium', 'large'], 'medium'),
            'text_width' => $textWidth,
            'text_color' => BlockDataInput::optionalColor($input, 'text_color'),
            'button_color' => BlockDataInput::optionalColor($input, 'button_color'),
            'bg_color' => BlockDataInput::optionalColor($input, 'bg_color'),
            'panel_enabled' => !empty($input['panel_enabled']),
            'panel_color' => self::hexOrDefault($input['panel_color'] ?? '', '#0b1a30'),
            'panel_opacity' => self::percentage($input['panel_opacity'] ?? null, 40),
            'button_text' => trim((string) ($input['button_text'] ?? '')),
            'button_url' => BlockDataInput::safeLink($input['button_url'] ?? ''),
            'button_icon' => \App\Core\Icon::cleanName($input['button_icon'] ?? ''),
            'button_icon_image' => self::iconImage($input['button_icon_image'] ?? ''),
            'button2_text' => trim((string) ($input['button2_text'] ?? '')),
            'button2_url' => BlockDataInput::safeLink($input['button2_url'] ?? ''),
            'button2_icon' => \App\Core\Icon::cleanName($input['button2_icon'] ?? ''),
            'button2_icon_image' => self::iconImage($input['button2_icon_image'] ?? ''),
            'video_button_text' => trim((string) ($input['video_button_text'] ?? '')),
            'video_button_url' => BlockDataInput::safeLink($input['video_button_url'] ?? ''),
            'slides' => self::slides($input['slides'] ?? null),
            'autoplay' => self::autoplay($input['autoplay'] ?? null),
        ];
    }

    /**
     * Слайды обложки. Оформление (высота, затемнение, панель) остаётся общим
     * для всей обложки — у слайда своё только содержимое: текст, фон (фото,
     * mp4 или YouTube), кнопки, ссылка и окно показа.
     *
     * @return list<array<string, mixed>>
     */
    private static function slides(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $slides = [];
        foreach ($raw as $slide) {
            if (!is_array($slide)) {
                continue;
            }

            $video = BlockDataInput::safeMedia($slide['video_url'] ?? '');
            $youtube = trim((string) ($slide['youtube_url'] ?? ''));
            // Ссылку храним как ввёл редактор, но фоном она становится только
            // когда из неё читается идентификатор ролика.
            $youtubeOk = Video::youtubeId($youtube) !== null;

            $normalized = [
                'eyebrow' => trim((string) ($slide['eyebrow'] ?? '')),
                'title' => trim((string) ($slide['title'] ?? '')),
                'subtitle' => trim((string) ($slide['subtitle'] ?? '')),
                'media_type' => $youtubeOk ? 'youtube' : ($video !== '' ? 'video' : 'image'),
                'image' => BlockDataInput::safeMedia($slide['image'] ?? ''),
                'video_url' => $video,
                'youtube_url' => $youtube,
                'image_position' => MediaPosition::normalize($slide['image_position'] ?? null),
                'link_url' => BlockDataInput::safeLink($slide['link_url'] ?? ''),
                'button_text' => trim((string) ($slide['button_text'] ?? '')),
                'button_url' => BlockDataInput::safeLink($slide['button_url'] ?? ''),
                'button_icon' => \App\Core\Icon::cleanName($slide['button_icon'] ?? ''),
                'button2_text' => trim((string) ($slide['button2_text'] ?? '')),
                'button2_url' => BlockDataInput::safeLink($slide['button2_url'] ?? ''),
                'button2_icon' => \App\Core\Icon::cleanName($slide['button2_icon'] ?? ''),
                'text_position' => in_array($slide['text_position'] ?? '', ['left', 'center', 'right'], true)
                    ? (string) $slide['text_position']
                    : '',
                // Оформление слайда: пустое значение = «как у обложки». Так
                // редактор задаёт затемнение и подложку там, где они нужны
                // (светлое фото), не трогая остальные слайды.
                'overlay' => BlockDataInput::enum($slide, 'overlay', ['on', 'off'], ''),
                'overlay_mode' => BlockDataInput::enum($slide, 'overlay_mode', ['gradient', 'solid'], ''),
                'panel' => BlockDataInput::enum($slide, 'panel', ['on', 'off'], ''),
                'art_image' => BlockDataInput::safeMedia($slide['art_image'] ?? ''),
                'art_alt' => trim((string) ($slide['art_alt'] ?? '')),
                'art_position' => BlockDataInput::enum($slide, 'art_position', ['above', 'left', 'right'], ''),
                'art_size' => BlockDataInput::enum($slide, 'art_size', ['small', 'medium', 'large'], ''),
                '_visible_from' => \App\Core\BlockVisibility::normalize($slide['_visible_from'] ?? ''),
                '_visible_to' => \App\Core\BlockVisibility::normalize($slide['_visible_to'] ?? ''),
            ];

            // Пустая строка репитера (редактор добавил и не заполнил) в данные
            // не попадает: иначе слайдер показывал бы чёрный кадр.
            if ($normalized['title'] === '' && $normalized['subtitle'] === ''
                && $normalized['image'] === '' && $video === '' && !$youtubeOk) {
                continue;
            }

            $slides[] = $normalized;
            if (count($slides) >= self::MAX_SLIDES) {
                break;
            }
        }

        return $slides;
    }

    /** Пауза автопрокрутки в секундах; 0 — переключать только вручную. */
    private static function autoplay(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }
        $seconds = (int) $value;

        return $seconds <= 0 ? 0 : max(3, min(30, $seconds));
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    private static function percentage(mixed $value, int $default): int
    {
        return is_numeric($value) ? max(0, min(100, (int) $value)) : $default;
    }

    private static function hexOrDefault(mixed $value, string $default): string
    {
        $value = trim((string) $value);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $default;
    }

    /** Своя иконка кнопки: принимаем только безопасный медиа-адрес. */
    private static function iconImage(mixed $value): string
    {
        $url = trim((string) $value);

        return $url !== '' && \App\Core\UrlGuard::isSafeMedia($url) ? $url : '';
    }
}
