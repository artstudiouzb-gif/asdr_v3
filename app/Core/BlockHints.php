<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Предупреждения редактору о полях, которые заполнены, но не сработают.
 *
 * Блоки местами молча игнорируют ввод: кнопка без ссылки не выводится, ручные
 * карточки не показываются при автоматическом источнике, слайд без фотографии
 * отбрасывается при сохранении. Раньше об этом можно было узнать, только
 * открыв сайт и не найдя своего текста.
 */
final class BlockHints
{
    /**
     * @param array<string,mixed> $data данные блока после нормализации
     * @return list<string> сообщения для показа редактору
     */
    public static function forBlock(string $type, array $data): array
    {
        $hints = [];
        $filled = static fn (string $key): bool => trim((string) ($data[$key] ?? '')) !== '';

        // Кнопка выводится только когда есть и подпись, и ссылка.
        foreach ([
            ['button_text', 'button_url', 'Кнопка'],
            ['button2_text', 'button2_url', 'Вторая кнопка'],
            ['all_text', 'all_url', 'Ссылка «Все…»'],
            ['cta_button_text', 'cta_button_url', 'Кнопка в блоке призыва'],
            ['video_button_text', 'video_button_url', 'Кнопка видео'],
        ] as [$textKey, $urlKey, $label]) {
            if (!$filled($textKey) || $filled($urlKey)) {
                continue;
            }
            // У «Подписки» это подпись кнопки отправки формы, ссылка ей не нужна.
            if ($textKey === 'button_text' && $type === 'subscribe') {
                continue;
            }
            // Блоки-обёртки сами подставляют ссылку на раздел при пустом поле.
            if ($textKey === 'all_text' && self::fillsSectionLink($type, $data)) {
                continue;
            }
            $hints[] = $label . ': подпись задана, но не указана ссылка — на сайте она не появится.';
        }

        // Врезка опознаёт источник по ссылке: незнакомый адрес не выводится
        // вовсе, и без подсказки редактор видел бы пустое место без причины.
        if ($type === 'embed' && $filled('url') && \App\Core\EmbedSource::parse((string) $data['url']) === null) {
            $hints[] = 'Ссылка не распознана — на сайте врезки не будет.'
                . ' Поддерживаются ролик YouTube, пост Telegram (t.me/канал/номер) и форма Google.';
        }

        // Автоматический источник подменяет ручной список целиком.
        if (in_array($type, ['cards_grid', 'media_gallery'], true)) {
            $source = (string) ($data['source'] ?? 'manual');
            $items = is_array($data['items'] ?? null) ? $data['items'] : [];
            if ($source !== 'manual' && $items !== []) {
                $hints[] = 'Выбран автоматический источник, поэтому добавленные вручную элементы ('
                    . count($items) . ') не показываются. Выберите источник «Вручную» или очистите список.';
            }
        }

        // Слайд без фотографии отбрасывается при сохранении.
        if ($type === 'slider') {
            $slides = is_array($data['slides'] ?? null) ? $data['slides'] : [];
            $empty = 0;
            foreach ($slides as $slide) {
                if (trim((string) (is_array($slide) ? ($slide['image'] ?? '') : '')) === '') {
                    $empty++;
                }
            }
            if ($empty > 0) {
                $hints[] = 'Слайдов без изображения: ' . $empty . '. Такие слайды не сохраняются — добавьте фото.';
            }
        }

        // Фон-фотография грузится сразу, без ленивой загрузки: тяжёлый файл
        // тормозит первую отрисовку, а редактор об этом не узнает.
        $backgroundHint = self::heavyBackgroundHint($data);
        if ($backgroundHint !== null) {
            $hints[] = $backgroundHint;
        }

        // Блок формы без выбранной формы виден только как заглушка.
        if ($type === 'form' && (int) ($data['form_id'] ?? 0) === 0) {
            $hints[] = 'Форма не выбрана — на сайте вместо неё будет предупреждение. Выберите форму в поле блока.';
        }

        // Связка «Команда» ↔ «Оргструктура»: ссылка со схемы работает только
        // тогда, когда блок команды создаёт якоря секторов.
        if ($type === 'team_list') {
            $hints = array_merge($hints, self::teamListHints($data));
        }
        if ($type === 'org_structure' && self::linksToTeamAnchors($data) && !self::hasGroupedTeamBlock()) {
            $hints[] = 'В схеме есть ссылки на состав сектора (#team-…), но ни в одном блоке «Команда» '
                . 'не включена группировка по секторам — такие ссылки никуда не приведут.';
        }

        return $hints;
    }

    /**
     * Вес фотографии-фона. Порог — 400 КБ: столько «стоит» приличный кадр
     * после сжатия, дальше начинается заметная задержка первой отрисовки.
     *
     * @param array<string,mixed> $data
     */
    private static function heavyBackgroundHint(array $data): ?string
    {
        if (($data['_bg_mode'] ?? '') !== 'image') {
            return null;
        }
        $url = trim((string) ($data['_bg_image'] ?? ''));
        if ($url === '') {
            return null;
        }

        $prefix = rtrim((string) Config::get('paths.public_uploads_url', '/uploads/public'), '/');
        $base = rtrim((string) Config::get('paths.public_uploads', ''), '/');
        if ($base === '' || !str_starts_with($url, $prefix . '/')) {
            return null;
        }

        $path = $base . substr(preg_replace('/[?#].*$/', '', $url) ?? $url, strlen($prefix));
        if (!is_file($path)) {
            return null;
        }

        $size = (int) filesize($path);
        if ($size <= 400 * 1024) {
            return null;
        }

        return 'Фон секции весит ' . round($size / 1048576, 1) . ' МБ. Фон не грузится лениво: '
            . 'страница будет ждать этот файл. Уменьшите кадр или возьмите более сжатый.';
    }

    /**
     * Предупреждения блока «Команда»: фильтр по несуществующему сектору даёт
     * пустой блок, а выключенная группировка ломает ссылки со схемы.
     *
     * @param array<string,mixed> $data
     * @return list<string>
     */
    private static function teamListHints(array $data): array
    {
        $selected = trim((string) ($data['department'] ?? ''));
        $grouped = !empty($data['group_by_department']);
        if ($selected === '' && $grouped) {
            return [];
        }

        try {
            $departments = \App\Models\TeamMember::departments();
        } catch (\Throwable $e) {
            Logger::swallowed('BlockHints: не удалось прочитать секторы команды', $e);
            return [];
        }

        $hints = [];
        $slugs = array_column($departments, 'slug');
        if ($selected !== '' && !in_array($selected, $slugs, true)) {
            $hints[] = 'Выбран сектор, которого нет ни у одного опубликованного сотрудника — блок будет пустым. '
                . 'Проверьте поле «Сектор» в карточках сотрудников.';
        }
        if (!$grouped && $departments !== []) {
            $hints[] = 'Группировка по секторам выключена: якоря вида #team-… не создаются, '
                . 'поэтому ссылки со схемы оргструктуры на состав сектора работать не будут.';
        }

        return $hints;
    }

    /** Есть ли в данных схемы ссылки на якоря секторов. */
    private static function linksToTeamAnchors(array $data): bool
    {
        $haystack = json_encode($data, JSON_UNESCAPED_UNICODE);

        return is_string($haystack) && str_contains($haystack, '#team-');
    }

    /** Есть ли на сайте блок «Команда» с включённой группировкой по секторам. */
    private static function hasGroupedTeamBlock(): bool
    {
        try {
            $stmt = Database::pdo()->query(
                // Значение приходит и как JSON true, и как 1. JSON_UNQUOTE вместо
                // CAST(... AS JSON): CAST в такой форме не понимает MariaDB.
                "SELECT COUNT(*) FROM blocks
                 WHERE type = 'team_list'
                   AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.group_by_department')) IN ('true', '1')"
            );

            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            Logger::swallowed('BlockHints: не удалось проверить блоки команды', $e);

            // Молча пропускаем подсказку: ложное предупреждение хуже его отсутствия.
            return true;
        }
    }

    /**
     * Подставляет ли блок ссылку «Все…» сам (BlockRenderer::enrichData).
     *
     * @param array<string,mixed> $data
     */
    private static function fillsSectionLink(string $type, array $data): bool
    {
        if (in_array($type, ['news_latest', 'news_feature', 'news_docs'], true)) {
            return true;
        }
        $source = (string) ($data['source'] ?? 'manual');

        return ($type === 'cards_grid' && ($data['variant'] ?? 'icon') === 'image' && $source === 'projects')
            || ($type === 'media_gallery' && $source === 'albums');
    }

    /**
     * Пустой ли блок после сохранения: такой не выводится на сайте, и редактору
     * стоит сказать об этом сразу, а не оставлять гадать.
     *
     * @param array<string,mixed> $block строка блока (id/type/data)
     */
    public static function rendersEmpty(array $block): bool
    {
        $rendered = BlockRenderer::render($block);

        return empty($rendered['hidden']) && BlockRenderer::isVisuallyEmpty((string) $rendered['html']);
    }
}
