<?php

declare(strict_types=1);

namespace App\Core\Design;

use App\Core\DesignSettings;
use App\Models\Setting;

/**
 * Конфигурации «Дизайна», сохранённые администратором, и автокопии перед
 * применением готового набора.
 *
 * Вынесено из DesignSettings (там остался фасад с теми же именами).
 */
final class UserPresets
{
    private const USER_PRESETS_KEY = 'design_user_presets';
    private const USER_PRESETS_MAX = 10;

    /** @return array<string,array{label:string,values:array<string,string>,colors?:array<int,string>,appearance?:array<string,string>}> */
    public static function userPresets(): array
    {
        $json = Setting::get(self::USER_PRESETS_KEY, '');
        $data = $json !== '' ? json_decode($json, true) : null;

        return is_array($data) ? $data : [];
    }

    /**
     * Сохраняет ТЕКУЩИЕ настройки дизайна как именованную конфигурацию.
     * Вместе с опциями снапшотится ручная тройка цвет/акцент/шрифт — чтобы
     * пресет с палитрой «Свои цвета» восстанавливался в точности.
     * Возвращает slug или null (пустое имя / превышен лимит).
     */
    public static function saveUserPreset(string $name): ?string
    {
        $name = mb_substr(trim($name), 0, 40);
        if ($name === '') {
            return null;
        }

        $presets = self::userPresets();
        // Сохраняем буквы любого языка: прежняя ASCII-регулярка превращала
        // почти все русские/узбекские названия в один ключ "preset".
        $baseSlug = preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower($name)) ?: 'preset';
        $baseSlug = trim($baseSlug, '-') ?: 'preset';
        $slug = $baseSlug;
        if (isset($presets[$slug]) && (string) ($presets[$slug]['label'] ?? '') !== $name) {
            $suffix = 2;
            do {
                $slug = $baseSlug . '-' . $suffix++;
            } while (isset($presets[$slug]));
        }
        if (!isset($presets[$slug]) && count($presets) >= self::USER_PRESETS_MAX) {
            return null;
        }

        $custom = DesignSettings::customAppearance();
        $semantic = DesignSettings::semanticColors();
        $spacings = DesignSettings::semanticSpacings();
        $presets[$slug] = [
            'label' => $name,
            'values' => DesignSettings::current(),
            'colors' => [
                $custom['color_primary'],
                $custom['color_accent'],
                $custom['font_family'],
            ],
            'appearance' => [
                'color_primary' => $custom['color_primary'],
                'color_accent' => $custom['color_accent'],
                'font_family' => $custom['font_family'],
                'font_face_name' => Setting::get('font_face_name', ''),
                'font_url' => Setting::get('font_url', ''),
                'default_theme' => Setting::get('default_theme', 'light'),
                'font_google_heading' => Setting::get('design_font_google_heading', ''),
                'font_google_body' => Setting::get('design_font_google_body', ''),
                'font_script' => Setting::get('design_font_script', ''),
                'font_size_custom' => Setting::get('design_font_size_custom', ''),
                'radius_custom' => Setting::get('design_radius_custom', ''),
                'newsdetail_padding_top' => Setting::get('design_newsdetail_padding_top', ''),
                'newsdetail_padding_bottom' => Setting::get('design_newsdetail_padding_bottom', ''),
                'line_height_custom' => Setting::get('design_line_height_custom', ''),
                'heading_line_height_custom' => Setting::get('design_heading_line_height_custom', ''),
                'meta_letter_spacing_custom' => Setting::get('design_meta_letter_spacing_custom', ''),
                'typo_scale' => Typography::typoScale(),
            ] + array_combine(
                array_keys(Typography::TYPO_SIZES),
                array_map(static fn (string $k): string => (string) Setting::get('design_' . $k, ''), array_keys(Typography::TYPO_SIZES))
            ) + [
                'bg_primary' => $semantic['bg_primary'],
                'bg_surface' => $semantic['bg_surface'],
                'text_main' => $semantic['text_main'],
                'text_muted' => $semantic['text_muted'],
                'border_color' => $semantic['border_color'],
                'space_small' => $spacings['space_small'],
                'space_premium' => $spacings['space_premium'],
                'space_max' => $spacings['space_max'],
            ],
        ];
        Setting::set(self::USER_PRESETS_KEY, self::encodePresets($presets));
        Setting::set('design_preset', 'user:' . $slug);

        return $slug;
    }

    public static function deleteUserPreset(string $slug): bool
    {
        $presets = self::userPresets();
        if (!isset($presets[$slug])) {
            return false;
        }
        unset($presets[$slug]);
        Setting::set(self::USER_PRESETS_KEY, self::encodePresets($presets));
        if (Setting::get('design_preset', '') === 'user:' . $slug) {
            Setting::set('design_preset', '');
        }

        return true;
    }

    public static function applyUserPreset(string $slug): bool
    {
        $presets = self::userPresets();
        if (!isset($presets[$slug])) {
            return false;
        }
        $preset = $presets[$slug];
        $values = (array) ($preset['values'] ?? []);
        $colors = (array) ($preset['colors'] ?? []);
        $appearance = (array) ($preset['appearance'] ?? []);
        // Сначала восстанавливаем ручные значения, затем применяем через
        // обычный save. Так они сохраняются и после переключения пресетов.
        if (($values['palette'] ?? '') === 'custom' && count($colors) === 3) {
            if ($colors[0] !== '') { Setting::set('design_custom_color_primary', (string) $colors[0]); }
            if ($colors[1] !== '') { Setting::set('design_custom_color_accent', (string) $colors[1]); }
        }
        if (($values['font_style'] ?? '') === 'custom' && ($colors[2] ?? '') !== '') {
            Setting::set('design_custom_font_family', (string) $colors[2]);
        }
        // Новые пресеты хранят весь единый блок оформления; colors остаётся
        // fallback для конфигураций, созданных до унификации.
        $appearanceInput = array_intersect_key($appearance, array_flip(array_merge([
            'color_primary', 'color_accent', 'font_family', 'font_face_name',
            'font_url', 'default_theme', 'font_google_heading', 'font_google_body',
            'font_script',
            'font_size_custom', 'radius_custom', 'line_height_custom',
            'newsdetail_padding_top', 'newsdetail_padding_bottom',
            'heading_line_height_custom', 'meta_letter_spacing_custom', 'typo_scale',
            'bg_primary', 'bg_surface', 'text_main', 'text_muted', 'border_color',
            'space_small', 'space_premium', 'space_max',
        ], array_keys(Typography::TYPO_SIZES))));
        // Старые пользовательские конфигурации не знали об этих полях: при
        // их применении сбрасываем текущие переопределения, а не наследуем их.
        $appearanceInput = array_merge([
            'font_google_heading' => '',
            'font_google_body' => '',
            'font_script' => '',
            'font_size_custom' => '',
            'radius_custom' => '',
            'newsdetail_padding_top' => '',
            'newsdetail_padding_bottom' => '',
            'line_height_custom' => '',
            'heading_line_height_custom' => '',
            'meta_letter_spacing_custom' => '',
            // Конфигурации, сохранённые до появления шкалы, восстанавливают
            // поведение «как в теме», а не наследуют текущую шкалу.
            'typo_scale' => 'theme',
            // Старые конфигурации не содержали семантические интервалы.
            'space_small' => 'clamp(14px, 2.5vw, 24px)',
            'space_premium' => 'clamp(28px, 4vw, 56px)',
            'space_max' => 'clamp(40px, 5vw, 76px)',
        ], array_fill_keys(array_keys(Typography::TYPO_SIZES), ''), $appearanceInput);
        DesignSettings::save(array_merge($values, $appearanceInput));

        Setting::set('design_preset', 'user:' . $slug);

        return true;
    }

    /** Префикс автокопий дизайна — по нему они узнаются и чистятся. */
    public const DESIGN_BACKUP_PREFIX = 'Автокопия дизайна';

    /** Сколько автокопий держим: они делят лимит с обычными конфигурациями. */
    private const DESIGN_BACKUP_KEEP = 2;

    /**
     * Снимок текущих настроек перед применением конфигурации: применение
     * переписывает всё разом, а отмены у раздела «Дизайн» нет.
     *
     * @return string|null название копии либо null, если сохранить не вышло
     */
    public static function autoBackupPreset(): ?string
    {
        // Старые автокопии убираем заранее — иначе упрёмся в лимит наборов.
        $presets = self::userPresets();
        $auto = array_filter(
            $presets,
            static fn (array $p): bool => str_starts_with((string) ($p['label'] ?? ''), self::DESIGN_BACKUP_PREFIX)
        );
        if (count($auto) >= self::DESIGN_BACKUP_KEEP) {
            foreach (array_slice(array_keys($auto), 0, count($auto) - self::DESIGN_BACKUP_KEEP + 1) as $slug) {
                unset($presets[$slug]);
            }
            Setting::set(self::USER_PRESETS_KEY, self::encodePresets($presets));
        }

        $name = self::DESIGN_BACKUP_PREFIX . ': ' . date('d.m.Y H:i');

        return self::saveUserPreset($name) !== null ? $name : null;
    }


    /**
     * Пресеты строкой для настройки.
     *
     * `json_encode()` объявлена как `string|false` и при негодных данных (битая
     * кодировка в названии пресета) отдаёт `false`. В файле со `strict_types`
     * это значение уходило в `Setting::set()` типизированным параметром, то
     * есть сохранение раздела «Дизайн» падало с `TypeError`, ничего не говоря о
     * причине. `JSON_THROW_ON_ERROR` называет её вслух и не даёт записать в
     * настройку мусор вместо пресетов.
     *
     * @param array<mixed> $presets
     */
    private static function encodePresets(array $presets): string
    {
        return json_encode($presets, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
