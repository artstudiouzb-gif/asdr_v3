<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockData\CardsGridBlockNormalizer;
use App\Core\BlockData\ContactCardsBlockNormalizer;
use App\Core\BlockData\IconTextBlockNormalizer;

/**
 * Смена типа уже собранного блока: «Карточки» ↔ «Контакты» ↔ «Иконка и
 * текст», «Таблица» ↔ «Диаграмма».
 *
 * Пары выбраны по данным, а не по виду: у каждой пары содержимое совпадает
 * по смыслу (иконка, заголовок, текст, ссылка; строки «Подпись | Значение»),
 * поэтому смена переносит набранное, а не только название. Пары, где перенос
 * терял бы главное (число показателя, разметку ответа FAQ), сюда не входят.
 *
 * Правила объявлены здесь одним списком: что с чем меняется и что при этом
 * не переносится. Второй список в форме разъехался бы с первым — форма
 * предлагала бы смену, которой нет, или молчала бы о потере.
 *
 * Результат проходит тот же нормализатор, что и присланная форма: смена типа
 * не может сохранить того, чего не пропустила бы форма целевого блока.
 * Оформление секции (`_*`: фон, отступы, условия показа, появление) у всех
 * блоков общее и переносится как есть.
 */
final class BlockConversion
{
    /**
     * Откуда → куда → что не переносится (строки для редактора).
     *
     * @var array<string, array<string, list<string>>>
     */
    public const PAIRS = [
        'cards_grid' => [
            'contact_cards' => ['фотографии карточек', 'настройки вида карточек', 'ссылка без подписи не показывается — адрес сохранится, подпись задайте сами'],
            'icon_text' => ['фотографии и ссылки карточек', 'настройки вида карточек'],
        ],
        'contact_cards' => [
            'cards_grid' => ['подпись ссылки (ссылкой станет вся карточка)', 'строки текста идут одним абзацем', 'картинки вместо значков'],
            'icon_text' => ['ссылки', 'картинки вместо значков'],
        ],
        'icon_text' => [
            'cards_grid' => ['цвет значков', 'строки текста идут одним абзацем', 'настройки вида'],
            'contact_cards' => ['цвет значков', 'настройки вида'],
        ],
        'table' => [
            'chart' => ['строка заголовков', 'столбцы после второго', 'ячейки без числа'],
        ],
        'chart' => [
            'table' => ['подпись под диаграммой', 'итог и вид диаграммы'],
        ],
    ];

    /**
     * Куда можно сменить тип этого блока.
     *
     * @return array<string, list<string>> тип → что не перенесётся
     */
    public static function targets(string $from): array
    {
        return self::PAIRS[$from] ?? [];
    }

    public static function allowed(string $from, string $to): bool
    {
        return isset(self::PAIRS[$from][$to]);
    }

    /**
     * Данные блока нового типа из данных прежнего.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function convert(string $from, string $to, array $data, string $locale = 'ru'): array
    {
        if (!self::allowed($from, $to)) {
            throw new \InvalidArgumentException('Смена типа ' . $from . ' → ' . $to . ' не описана.');
        }

        $presentation = array_filter($data, static fn ($key): bool => is_string($key) && str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);

        $input = in_array($to, ['table', 'chart'], true)
            ? self::rowsInput($to, $data)
            : self::cardsInput($to, self::cards($from, $data), $data);

        $converted = match ($to) {
            'cards_grid' => CardsGridBlockNormalizer::normalize($input, $locale),
            'contact_cards' => ContactCardsBlockNormalizer::normalize($input, $locale),
            'icon_text' => IconTextBlockNormalizer::normalize($input, $locale),
            'table' => BlockFieldSchema::normalize('table', $input, $locale),
            default => BlockFieldSchema::normalize('chart', $input, $locale),
        };

        return array_merge($converted, $presentation);
    }

    /**
     * Карточки в нейтральном виде: значок, заголовок, текст (строками),
     * ссылка и её подпись.
     *
     * @param array<string, mixed> $data
     * @return list<array{icon: string, title: string, text: string, url: string, link_text: string}>
     */
    private static function cards(string $from, array $data): array
    {
        $cards = [];
        foreach ((array) ($data['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $card = match ($from) {
                'contact_cards' => [
                    'title' => (string) ($item['title'] ?? ''),
                    'text' => (string) ($item['lines'] ?? ''),
                    'url' => (string) ($item['link_url'] ?? ''),
                    'link_text' => (string) ($item['link_text'] ?? ''),
                ],
                'icon_text' => self::fromRows((string) ($item['rows'] ?? '')),
                default => [
                    'title' => (string) ($item['title'] ?? ''),
                    'text' => (string) ($item['text'] ?? ''),
                    'url' => (string) ($item['url'] ?? ''),
                    'link_text' => '',
                ],
            };
            $cards[] = ['icon' => (string) ($item['icon_svg'] ?? '')] + $card;
        }

        return $cards;
    }

    /**
     * Строки «Иконки и текста» → карточка. Подпись первой строки становится
     * заголовком, её значение — первой строкой текста, остальные строки идут
     * как есть. Обратное преобразование (toRows) собирает ровно то же, поэтому
     * «туда и обратно» строки не меняет.
     *
     * @return array{title: string, text: string, url: string, link_text: string}
     */
    private static function fromRows(string $rows): array
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/u', $rows) ?: []),
            static fn (string $line): bool => $line !== ''
        ));
        $first = array_map('trim', explode('|', (string) array_shift($lines), 2));
        if (($first[1] ?? '') !== '') {
            array_unshift($lines, $first[1]);
        }

        return ['title' => $first[0], 'text' => implode("\n", $lines), 'url' => '', 'link_text' => ''];
    }

    /** Карточка → строки «Иконки и текста» (обратное к fromRows). */
    private static function toRows(string $title, string $text): string
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/u', $text) ?: []),
            static fn (string $line): bool => $line !== ''
        ));
        // Первая строка текста встаёт значением к заголовку, если сама она не
        // пара «подпись | значение» — тогда это отдельная строка.
        $head = $title;
        if ($lines !== [] && !str_contains($lines[0], '|')) {
            $head .= ' | ' . array_shift($lines);
        }

        return implode("\n", array_merge($title !== '' ? [$head] : [], $lines));
    }

    /**
     * Присланная форма целевого блока, собранная из карточек.
     *
     * @param list<array{icon: string, title: string, text: string, url: string, link_text: string}> $cards
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function cardsInput(string $to, array $cards, array $data): array
    {
        $items = [];
        foreach ($cards as $card) {
            $items[] = match ($to) {
                'contact_cards' => [
                    'icon_svg' => $card['icon'],
                    'title' => $card['title'],
                    'lines' => $card['text'],
                    'link_url' => $card['url'],
                    'link_text' => $card['link_text'],
                ],
                'icon_text' => [
                    'icon_svg' => $card['icon'],
                    'rows' => self::toRows($card['title'], $card['text']),
                ],
                default => [
                    'icon_svg' => $card['icon'],
                    'title' => $card['title'],
                    // Карточка печатает текст одним абзацем: строки контактов
                    // склеиваются пробелом, а не теряются.
                    'text' => $card['text'],
                    'url' => $card['url'],
                ],
            };
        }

        // Переносятся только поля с одним смыслом у всех трёх типов. Прочие
        // настройки (вариант, стиль, выравнивание) названы у каждого типа
        // по-своему, и совпавшее имя значения значило бы другое.
        $input = ['items' => $items] + self::heading($data);
        foreach (['description', 'columns'] as $key) {
            if (array_key_exists($key, $data)) {
                $input[$key] = $data[$key];
            }
        }

        return $input;
    }

    /**
     * Заголовок секции. В форме его поле называется `title_field`, а не
     * `title` (`title` — название блока в списке, Field::named), и
     * нормализатор читает присланное по имени поля формы: без второго ключа
     * заголовок при смене типа пропадал.
     *
     * @param array<string, mixed> $data
     * @return array{title: string, title_field: string}
     */
    private static function heading(array $data): array
    {
        $title = (string) ($data['title'] ?? '');

        return ['title' => $title, 'title_field' => $title];
    }

    /**
     * «Таблица» ↔ «Диаграмма»: обе хранят строки «Подпись | Значение».
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function rowsInput(string $to, array $data): array
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/u', (string) ($data['rows'] ?? '')) ?: []),
            static fn (string $line): bool => $line !== ''
        ));
        $input = self::heading($data);

        if ($to === 'chart') {
            // Строка заголовков — подписи столбцов, а не данные: в диаграмме
            // она стала бы строкой без числа.
            if (!empty($data['header_row'])) {
                array_shift($lines);
            }
            $input['rows'] = implode("\n", array_map(static function (string $line): string {
                $cells = array_map('trim', explode('|', $line));

                return $cells[0] . (isset($cells[1]) ? ' | ' . $cells[1] : '');
            }, $lines));

            return $input;
        }

        // Единица измерения у диаграммы отдельным полем; в таблице она
        // становится частью значения — иначе «24» потеряло бы свои проценты.
        $unit = trim((string) ($data['unit'] ?? ''));
        $input['rows'] = implode("\n", array_map(static function (string $line) use ($unit): string {
            $cells = array_map('trim', explode('|', $line, 2));

            return $cells[0] . (isset($cells[1]) ? ' | ' . $cells[1] . ($unit !== '' ? ' ' . $unit : '') : '');
        }, $lines));
        $input['header_row'] = false;
        $input['header_col'] = true;

        return $input;
    }
}
