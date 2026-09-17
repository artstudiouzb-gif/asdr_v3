<?php

declare(strict_types=1);

namespace App\Core\Ai;

use App\Core\BlockTypeRegistry;
use App\Core\PageTemplateFile;

/**
 * Каркас страницы по описанию: «раздел о поддержке экспортёров — обложка
 * текстом, три преимущества, показатели, документы, форма обратной связи».
 *
 * Отдаёт модель не страницу, а **шаблон** — тот же конверт
 * `artstudio.page-template`, что приходит файлом. Отсюда три следствия,
 * которые и делают затею безопасной:
 * - разбирает ответ тот же `PageTemplateFile::parse`, что и присланный файл:
 *   тип сверяется с реестром, поля — с умолчаниями, оформление проходит через
 *   нормализатор. Второй проверки писать не пришлось, а значит и разъезжаться
 *   нечему;
 * - результат попадает **в библиотеку шаблонов**, а не на страницу: применение
 *   остаётся отдельным осознанным действием;
 * - блок «HTML» и свой CSS модель выдать не может — их принимает только
 *   супер-админ, и ровно это правило `parse()` уже проверяет.
 *
 * Тексты в таком каркасе — заготовки. Ценность здесь в структуре: какие блоки
 * в каком порядке, а не в том, что написано внутри.
 */
final class AiPageDraft
{
    /**
     * Типы, которые модель вправе предложить.
     *
     * Список закрытый и короткий намеренно. Контейнеры («Колонки», «Вкладки»)
     * требуют вложенных блоков и колонок — в снимке это отдельная структура,
     * которую без глаз редактора собирать бессмысленно. Обложка-запись
     * (`hero`) хранит только `hero_id`, то есть ссылку на несуществующую
     * запись. «HTML» — чужой код. Остальные блоки-обёртки (новости, проекты,
     * команда) тянут данные из базы и на пустом сайте покажут пустоту.
     */
    public const TYPES = [
        'text', 'cards_grid', 'counters', 'stages', 'faq', 'cta', 'buttons',
        'image', 'table', 'chart', 'icon_text', 'docs_list', 'anchor_nav',
        'text_image', 'testimonials', 'contact_cards', 'divider', 'form',
    ];

    /** Больше двенадцати секций — это уже не каркас, а свалка. */
    private const MAX_BLOCKS = 12;

    /**
     * @return array{name:string, blocks:array<int, array<string, mixed>>, warnings:list<string>, notice:string}
     */
    public static function fromDescription(string $description, bool $allowRawCode = false): array
    {
        $description = trim($description);
        if ($description === '') {
            return self::fail('Опишите страницу: о чём она и что на ней должно быть.');
        }
        if (!AiClient::configured()) {
            return self::fail('Ключ Gemini не настроен: собрать каркас страницы нечем.');
        }

        $result = AiClient::json(
            'Ты проектируешь страницы официального сайта государственного агентства в блочном конструкторе. '
                . 'Твоя задача — структура страницы, а не красивые слова.',
            "Собери каркас страницы по описанию.\n\n"
                . "Правила:\n"
                . "- от 3 до " . self::MAX_BLOCKS . " блоков, в том порядке, в каком они идут сверху вниз;\n"
                . "- тип каждого блока — строго из списка ниже;\n"
                . "- title блока — заголовок секции на языке описания;\n"
                . "- text — короткий текст-заготовка (1–2 предложения), который редактор заменит;\n"
                . "- ничего не выдумывай про агентство: ни цифр, ни дат, ни фамилий;\n"
                . "- не повторяй один и тот же тип подряд без причины.\n\n"
                . "Доступные типы:\n" . self::typeList()
                . "\n\nОписание страницы:\n" . mb_substr($description, 0, 4000),
            [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Короткое название шаблона.'],
                    'blocks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                                'text' => ['type' => 'string'],
                            ],
                            'required' => ['type', 'title'],
                        ],
                    ],
                ],
                'required' => ['name', 'blocks'],
            ],
            ['label' => 'каркас страницы', 'maxTokens' => 1600, 'temperature' => 0.4]
        );

        if ($result === null) {
            return self::fail('Gemini не ответил — попробуйте ещё раз.');
        }

        $blocks = [];
        foreach ((array) ($result['blocks'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string) ($item['type'] ?? '');
            if (!in_array($type, self::TYPES, true)) {
                continue; // чужой тип — не «почти подходящий», а несуществующий
            }
            $blocks[] = [
                'type' => $type,
                'title' => mb_substr(trim((string) ($item['title'] ?? '')), 0, 190),
                'data' => self::data($type, (string) ($item['text'] ?? '')),
                'custom_css' => '',
                'is_active' => 1,
            ];
            if (count($blocks) >= self::MAX_BLOCKS) {
                break;
            }
        }

        if ($blocks === []) {
            return self::fail('Модель не предложила ни одного знакомого блока — уточните описание.');
        }

        $name = AiClient::str($result, 'name');
        $name = $name !== '' ? mb_substr($name, 0, 190) : 'Каркас: ' . mb_substr($description, 0, 60);

        // Разбираем собственный ответ тем же кодом, что и присланный файл:
        // модель — такой же внешний источник, как чужая выгрузка.
        try {
            $parsed = PageTemplateFile::parse(PageTemplateFile::export($name, $blocks), $allowRawCode);
        } catch (\InvalidArgumentException $e) {
            return self::fail('Каркас не прошёл проверку: ' . $e->getMessage());
        }

        return [
            'name' => $parsed['name'] !== '' ? $parsed['name'] : $name,
            'blocks' => $parsed['blocks'],
            'warnings' => $parsed['warnings'],
            'notice' => '',
        ];
    }

    /**
     * Текст кладётся в то поле, которое у типа для него есть. Ключа нет —
     * блок остаётся с умолчаниями: лишний ключ всё равно потерялся бы при
     * первом сохранении, а выдуманный сбил бы нормализатор.
     *
     * @return array<string, mixed>
     */
    private static function data(string $type, string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $defaults = BlockTypeRegistry::defaultsFor($type);
        foreach (['content', 'text', 'description', 'lead'] as $key) {
            if (array_key_exists($key, $defaults)) {
                return [$key => $key === 'content' ? '<p>' . htmlspecialchars($text, ENT_QUOTES) . '</p>' : $text];
            }
        }

        return [];
    }

    private static function typeList(): string
    {
        $lines = [];
        foreach (self::TYPES as $type) {
            $label = BlockTypeRegistry::TYPE_LABELS[$type] ?? $type;
            $lines[] = '- ' . $type . ': ' . $label;
        }

        return implode("\n", $lines);
    }

    /** @return array{name:string, blocks:array<int, array<string, mixed>>, warnings:list<string>, notice:string} */
    private static function fail(string $notice): array
    {
        return ['name' => '', 'blocks' => [], 'warnings' => [], 'notice' => $notice];
    }
}
