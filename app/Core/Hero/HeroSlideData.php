<?php

declare(strict_types=1);

namespace App\Core\Hero;

use App\Core\BlockData\BlockDataInput;
use App\Core\BlockVisibility;
use App\Core\Icon;
use App\Core\MediaPosition;
use App\Core\Video;

/**
 * Поля одного слайда обложки.
 *
 * Правило пустого значения: у настроек оформления пустая строка означает
 * «как у обложки», а не «выключено». Так редактор задаёт затемнение или свою
 * раскладку там, где они нужны (светлое фото, вертикальный кадр), не трогая
 * остальные слайды. Явное «нет» у затемнения — значение `none`.
 */
final class HeroSlideData
{
    public const MEDIA_TYPES = ['none', 'image', 'video', 'youtube'];

    /** Что делать с видео на телефоне. */
    public const MOBILE_MEDIA = ['desktop', 'mobile_video', 'image'];

    public const CTA_STYLES = ['primary', 'secondary', 'ghost', 'link'];

    /** Как отображать свою SVG/PNG-картинку внутри CTA. */

    /** Привязка фоновой надписи к краю; точное место доводится смещением. */
    public const WATERMARK_X = ['left', 'center', 'right'];

    public const WATERMARK_Y = ['top', 'middle', 'bottom'];



    /**
     * Значения по умолчанию — они же список допустимых ключей.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            // --- Текст ---
            'eyebrow' => '',
            'title' => '',
            'subtitle' => '',

            // --- Фоновая надпись (крупное слово за контентом) ---
            // Декорация, но декорация из текста: переводится наравне с
            // заголовком и прячется от диктора, иначе он прочитает её
            // посреди заголовка. Размер задан в vw — она должна расти вместе
            // с экраном, а не со шкалой шрифтов (шкала для читаемого текста).
            'watermark' => '',
            // Размер — проценты ширины экрана (vw). Числом, а не набором
            // «средняя/крупная»: нужный кегль зависит от длины слова, и
            // угадать его пресетом заранее нельзя.
            'watermark_size' => 22,
            // Привязка к краю кадра. Точная доводка смещениями убрана вместе
            // с контуром, толщиной, своим цветом и выбором семейства: слово
            // рисуется цветом текста обложки, поэтому остаётся видимым на
            // любой схеме, а не только на той, под которую его подобрали.
            'watermark_x' => 'center',
            'watermark_y' => 'middle',
            'watermark_opacity' => 12,

            // --- Фон ---
            'media_type' => 'none',
            'image' => '',
            'image_mobile' => '',
            'image_position' => 'center-center',
            'image_position_mobile' => 'center-center',
            'image_fit' => 'cover',
            'video_url' => '',
            'video_mobile_url' => '',
            'youtube_url' => '',
            'youtube_id' => '',
            // Постер обязателен для любого видео: он же кадр до старта, он же
            // замена, когда автовоспроизведение заблокировано браузером.
            'poster' => '',
            'mobile_media' => 'image',

            // --- Ссылка со всего слайда ---
            'link_url' => '',
            'link_new_tab' => false,

            // --- Цвет (пусто = как у обложки) ---
            'content_scheme' => '',

            // --- Затемнение и подложка (пусто = как у обложки) ---
            'overlay' => '',
            'overlay_color' => '',
            'overlay_opacity' => -1,
            'overlay_direction' => '',

            // --- Раскладка (пусто = как у обложки) ---
            // Отступ сверху у части текста. Пусто — «как у обложки»;
            // 0 — значимое значение «прижать вплотную», поэтому пустая
            // строка и ноль здесь разное.

            // --- Кнопки ---
            'cta_enabled' => false,
            'cta_text' => '',
            'cta_url' => '',
            'cta_style' => 'primary',
            'cta_icon' => '',
            // Своя картинка кнопки (SVG/PNG). Задана — побеждает иконку из
            // набора: выбирают её осознанно, ради фирменного знака, которого
            // в Tabler нет.
            'cta_new_tab' => false,
            'cta2_enabled' => false,
            'cta2_text' => '',
            'cta2_url' => '',
            'cta2_style' => 'ghost',
            'cta2_icon' => '',
            'cta2_new_tab' => false,

            // --- Картинка поверх фона (эмблема, логотип программы) ---
            'art_image' => '',
            'art_alt' => '',
            'art_position' => 'above',
            'art_size' => 'medium',
            'art_width' => 320,

            // --- Показ ---
            // Своя длительность показа, секунды. 0 — «как у обложки»: у
            // текстового слайда и у слайда с плотной инфографикой разное время
            // чтения, и одним интервалом на всю карусель это не выражается.
            'duration' => 0,

            // --- Свой CSS-класс ---
            // Аварийный выход для оформления, которого нет в полях: класс
            // вешается на слайд, а стили пишутся в «Свой CSS» страницы
            // (только супер-админ). Класс на слайде, а не на заголовке, —
            // из него достаётся и заголовок (`.мой-класс .hero__title`),
            // и всё остальное, а поле одно.
            'css_class' => '',

            // --- Окно показа слайда ---
            '_visible_from' => '',
            '_visible_to' => '',
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function withDefaults(array $raw): array
    {
        $defaults = self::defaults();

        return self::coerce(array_merge($defaults, array_intersect_key($raw, $defaults)));
    }

    /**
     * Поля слайда из формы редактора.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input, string $locale = 'ru'): array
    {
        $youtubeUrl = BlockDataInput::trimmed($input, 'youtube_url');
        $youtubeId = Video::youtubeId($youtubeUrl);
        $video = BlockDataInput::safeMedia($input['video_url'] ?? '');
        $image = BlockDataInput::safeMedia($input['image'] ?? '');

        // Заполненное медиа — явное намерение редактора, даже если список
        // «Фон» остался в положении «без фона».
        $mediaType = BlockDataInput::enum($input, 'media_type', self::MEDIA_TYPES, 'none');
        if ($mediaType === 'none') {
            $mediaType = $youtubeId !== null ? 'youtube' : ($video !== '' ? 'video' : ($image !== '' ? 'image' : 'none'));
        }

        return self::coerce([
            'eyebrow' => BlockDataInput::plain($input, 'eyebrow', $locale),
            'title' => BlockDataInput::plain($input, 'title', $locale),
            'subtitle' => BlockDataInput::plain($input, 'subtitle', $locale),

            'watermark' => BlockDataInput::plain($input, 'watermark', $locale),
            'watermark_size' => self::ranged($input['watermark_size'] ?? null, 2, 60, 22),
            'watermark_x' => BlockDataInput::enum($input, 'watermark_x', self::WATERMARK_X, 'center'),
            'watermark_y' => BlockDataInput::enum($input, 'watermark_y', self::WATERMARK_Y, 'middle'),
            'watermark_opacity' => self::percent($input['watermark_opacity'] ?? null, 12),

            'media_type' => $mediaType,
            'image' => $image,
            'image_mobile' => BlockDataInput::safeMedia($input['image_mobile'] ?? ''),
            'image_position' => MediaPosition::normalize($input['image_position'] ?? null),
            'image_position_mobile' => MediaPosition::normalize($input['image_position_mobile'] ?? null),
            'image_fit' => BlockDataInput::enum($input, 'image_fit', ['cover', 'contain'], 'cover'),
            'video_url' => $video,
            'video_mobile_url' => BlockDataInput::safeMedia($input['video_mobile_url'] ?? ''),
            'youtube_url' => $youtubeUrl,
            // Идентификатор ролика вычисляем при сохранении: разбирать ссылку
            // на каждом рендере страницы незачем, а ссылку редактор вводит в
            // любом из привычных видов (watch?v=…, youtu.be/…, /embed/…).
            'youtube_id' => $youtubeId ?? '',
            'poster' => BlockDataInput::safeMedia($input['poster'] ?? ''),
            'mobile_media' => BlockDataInput::enum($input, 'mobile_media', self::MOBILE_MEDIA, 'image'),

            'link_url' => BlockDataInput::safeLink($input['link_url'] ?? ''),
            'link_new_tab' => !empty($input['link_new_tab']),

            'content_scheme' => BlockDataInput::enum($input, 'content_scheme', HeroSettings::CONTENT_SCHEMES, ''),

            'overlay' => BlockDataInput::enum($input, 'overlay', HeroSettings::OVERLAYS, ''),
            'overlay_color' => BlockDataInput::optionalColor($input, 'overlay_color'),
            // -1 — «непрозрачность как у обложки»; 0 это осмысленное значение
            // (затемнение выключено ползунком), поэтому пустоту им не заменяем.
            'overlay_opacity' => self::optionalPercent($input['overlay_opacity'] ?? null),
            'overlay_direction' => BlockDataInput::enum($input, 'overlay_direction', HeroSettings::OVERLAY_DIRECTIONS, ''),


            'cta_enabled' => !empty($input['cta_enabled']),
            'cta_text' => BlockDataInput::plain($input, 'cta_text', $locale),
            'cta_url' => BlockDataInput::safeLink($input['cta_url'] ?? ''),
            'cta_style' => BlockDataInput::enum($input, 'cta_style', self::CTA_STYLES, 'primary'),
            'cta_icon' => Icon::cleanName($input['cta_icon'] ?? ''),
            'cta_new_tab' => !empty($input['cta_new_tab']),
            'cta2_enabled' => !empty($input['cta2_enabled']),
            'cta2_text' => BlockDataInput::plain($input, 'cta2_text', $locale),
            'cta2_url' => BlockDataInput::safeLink($input['cta2_url'] ?? ''),
            'cta2_style' => BlockDataInput::enum($input, 'cta2_style', self::CTA_STYLES, 'ghost'),
            'cta2_icon' => Icon::cleanName($input['cta2_icon'] ?? ''),
            'cta2_new_tab' => !empty($input['cta2_new_tab']),

            'art_image' => BlockDataInput::safeMedia($input['art_image'] ?? ''),
            'art_alt' => BlockDataInput::trimmed($input, 'art_alt'),
            'art_position' => BlockDataInput::enum($input, 'art_position', ['above', 'left', 'right'], 'above'),
            'art_size' => BlockDataInput::enum($input, 'art_size', ['small', 'medium', 'large', 'custom'], 'medium'),
            'art_width' => self::ranged($input['art_width'] ?? null, 40, 1200, 320),

            'duration' => self::duration($input['duration'] ?? null),

            'css_class' => self::cssClass($input['css_class'] ?? ''),

            '_visible_from' => BlockVisibility::normalize($input['_visible_from'] ?? ''),
            '_visible_to' => BlockVisibility::normalize($input['_visible_to'] ?? ''),
        ]);
    }

    /**
     * Есть ли у слайда фон, который реально можно показать. Видео без постера
     * тоже считается: постер лишь желателен, но его отсутствие не повод
     * прятать слайд.
     *
     * @param array<string, mixed> $data
     */
    public static function hasMedia(array $data): bool
    {
        return match ((string) ($data['media_type'] ?? 'none')) {
            'image' => trim((string) ($data['image'] ?? '')) !== '',
            'video' => trim((string) ($data['video_url'] ?? '')) !== '',
            'youtube' => trim((string) ($data['youtube_id'] ?? '')) !== '',
            default => false,
        };
    }

    /**
     * Картинка-замена: она же постер до старта видео, она же то, что видит
     * посетитель, когда автовоспроизведение запрещено, видео отключено на
     * телефоне или включено «меньше движения». Пустой обложки быть не должно.
     *
     * @param array<string, mixed> $data
     */
    public static function fallbackImage(array $data): string
    {
        foreach (['poster', 'image', 'image_mobile'] as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Пустой слайд (редактор создал и не заполнил) на сайт не выпускаем:
     * иначе карусель показывает чёрный кадр и считает его за слайд.
     *
     * @param array<string, mixed> $data
     */
    public static function isEmpty(array $data): bool
    {
        return trim((string) ($data['title'] ?? '')) === ''
            && trim((string) ($data['subtitle'] ?? '')) === ''
            && trim((string) ($data['eyebrow'] ?? '')) === ''
            && trim((string) ($data['art_image'] ?? '')) === ''
            && !self::hasMedia($data);
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private static function coerce(array $d): array
    {
        $enum = static function (mixed $value, array $allowed, string $default): string {
            $value = is_scalar($value) ? (string) $value : '';

            return in_array($value, $allowed, true) ? $value : $default;
        };
        $str = static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '';
        $hex = static function (mixed $v): string {
            $v = is_scalar($v) ? trim((string) $v) : '';

            return preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1 ? strtolower($v) : '';
        };

        $d['eyebrow'] = $str($d['eyebrow'] ?? '');
        $d['title'] = $str($d['title'] ?? '');
        $d['subtitle'] = $str($d['subtitle'] ?? '');

        $d['media_type'] = $enum($d['media_type'] ?? '', self::MEDIA_TYPES, 'none');
        foreach (['image', 'image_mobile', 'video_url', 'video_mobile_url', 'poster', 'art_image'] as $media) {
            $d[$media] = BlockDataInput::safeMedia($d[$media] ?? '');
        }
        $d['image_position'] = MediaPosition::normalize($d['image_position'] ?? null);
        $d['image_position_mobile'] = MediaPosition::normalize($d['image_position_mobile'] ?? null);
        $d['image_fit'] = $enum($d['image_fit'] ?? '', ['cover', 'contain'], 'cover');
        $d['youtube_url'] = $str($d['youtube_url'] ?? '');
        // Идентификатор пересчитываем от ссылки: JSON мог приехать из старой
        // версии или быть поправлен руками, и рассинхрон дал бы чужой ролик.
        $d['youtube_id'] = Video::youtubeId((string) $d['youtube_url']) ?? '';
        $d['mobile_media'] = $enum($d['mobile_media'] ?? '', self::MOBILE_MEDIA, 'image');

        $d['link_url'] = BlockDataInput::safeLink($d['link_url'] ?? '');
        $d['link_new_tab'] = !empty($d['link_new_tab']);

        $d['content_scheme'] = $enum($d['content_scheme'] ?? '', HeroSettings::CONTENT_SCHEMES, '');

        $d['overlay'] = $enum($d['overlay'] ?? '', HeroSettings::OVERLAYS, '');
        $d['overlay_color'] = $hex($d['overlay_color'] ?? '');
        $d['watermark_size'] = self::ranged($d['watermark_size'] ?? null, 2, 60, 22);
        $d['watermark_x'] = $enum($d['watermark_x'] ?? '', self::WATERMARK_X, 'center');
        $d['watermark_y'] = $enum($d['watermark_y'] ?? '', self::WATERMARK_Y, 'middle');
        $d['watermark_opacity'] = self::percent($d['watermark_opacity'] ?? null, 12);
        $d['css_class'] = self::cssClass($d['css_class'] ?? '');

        $d['overlay_opacity'] = self::optionalPercent($d['overlay_opacity'] ?? null);
        $d['overlay_direction'] = $enum($d['overlay_direction'] ?? '', HeroSettings::OVERLAY_DIRECTIONS, '');

        foreach (['cta', 'cta2'] as $cta) {
            $d[$cta . '_enabled'] = !empty($d[$cta . '_enabled']);
            $d[$cta . '_text'] = $str($d[$cta . '_text'] ?? '');
            $d[$cta . '_url'] = BlockDataInput::safeLink($d[$cta . '_url'] ?? '');
            $d[$cta . '_style'] = $enum($d[$cta . '_style'] ?? '', self::CTA_STYLES, $cta === 'cta' ? 'primary' : 'ghost');
            $d[$cta . '_icon'] = Icon::cleanName($d[$cta . '_icon'] ?? '');
            $d[$cta . '_new_tab'] = !empty($d[$cta . '_new_tab']);
        }

        $d['art_alt'] = $str($d['art_alt'] ?? '');
        $d['art_position'] = $enum($d['art_position'] ?? '', ['above', 'left', 'right'], 'above');
        $d['art_size'] = $enum($d['art_size'] ?? '', ['small', 'medium', 'large', 'custom'], 'medium');
        $d['art_width'] = self::ranged($d['art_width'] ?? null, 40, 1200, 320);
        $d['duration'] = self::duration($d['duration'] ?? null);

        $d['_visible_from'] = BlockVisibility::normalize($d['_visible_from'] ?? '');
        $d['_visible_to'] = BlockVisibility::normalize($d['_visible_to'] ?? '');

        return $d;
    }

    /**
     * Длительность показа слайда в секундах.
     *
     * 0 — «как у обложки». Ненулевое значение поднимаем до 2 секунд: меньше
     * успевает только моргнуть, а такой слайд читается как сбой, а не как
     * настройка.
     */
    private static function duration(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }
        $seconds = (int) $value;

        return $seconds <= 0 ? 0 : max(2, min(120, $seconds));
    }

    /** -1 = «как у обложки», 0…100 — своё значение. */
    /**
     * Свой CSS-класс: имена через пробел, без всего, что могло бы закрыть
     * атрибут. Это не код — стили пишутся в «Свой CSS» страницы, — но в
     * атрибут значение попадает, поэтому набор символов ограничен жёстко.
     */
    private static function cssClass(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        $names = [];
        foreach (preg_split('/\\s+/', trim($value)) ?: [] as $name) {
            // Класс начинается с буквы: `123` и `--x` браузер за селектор не
            // считает, а редактор потом ищет, почему стиль не применился.
            if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $name) === 1) {
                $names[] = $name;
            }
            if (count($names) >= 5) {
                break;
            }
        }

        return implode(' ', array_unique($names));
    }

    /** Целое в границах; пусто — умолчание, а не край диапазона. */
    private static function ranged(mixed $value, int $min, int $max, int $default): int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    /** Проценты с умолчанием: пусто — умолчание, а не ноль. */
    private static function percent(mixed $value, int $default): int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return max(0, min(100, (int) $value));
    }

    private static function optionalPercent(mixed $value): int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return -1;
        }
        $percent = (int) $value;

        return $percent < 0 ? -1 : min(100, $percent);
    }
}
