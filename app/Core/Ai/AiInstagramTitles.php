<?php

declare(strict_types=1);

namespace App\Core\Ai;

use App\Core\InstagramPostTitle;

/**
 * Короткие заголовки к постам ленты Instagram.
 *
 * У поста Instagram заголовка нет: подпись начинается с эмодзи, «Diqqat!» или
 * с середины мысли, и полужирная выдержка из неё на карточке читалась как
 * заголовок, не будучи им. Заголовок формулирует модель — один раз, при
 * обновлении ленты (публичная страница в сеть не ходит), — и лежит в
 * сохранённой копии рядом с подписью.
 *
 * Все новые подписи уходят одним запросом: постов дюжина, и десять обращений
 * к модели ради десяти строк — это и время обновления, и квота.
 *
 * Без ключа или при отказе модели заголовок собирается из первой фразы
 * подписи (`InstagramPostTitle::fallback()`): карточка не остаётся без заголовка, а полноценный
 * придёт со следующим обновлением.
 */
final class AiInstagramTitles
{
    /**
     * @param array<string, string> $captions id поста → подпись
     * @return array<string, string> id поста → заголовок (только для тех, что сформулировала модель)
     */
    public static function generate(array $captions): array
    {
        $captions = array_filter($captions, static fn (string $c): bool => trim($c) !== '');
        if ($captions === [] || !AiClient::configured()) {
            return [];
        }

        $lines = [];
        foreach ($captions as $id => $caption) {
            $lines[] = ['id' => (string) $id, 'caption' => mb_substr(trim($caption), 0, 1200)];
        }
        $result = AiClient::json(
            'Ты редактор официального сайта государственного агентства. Пишешь короткие заголовки к постам его Instagram.',
            "Для каждого поста напиши заголовок:\n"
                . "- на языке подписи поста (узбекский латиницей, русский или английский);\n"
                . "- 30–80 символов, одной строкой, без точки в конце;\n"
                . "- о сути события, без эмодзи, хештегов, кавычек-ёлочек вокруг всего заголовка и слов «Diqqat», «Внимание»;\n"
                . "- не выдумывай имён, дат и цифр, которых нет в подписи.\n\n"
                . 'Посты (JSON): ' . json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            [
                'type' => 'object',
                'properties' => [
                    'titles' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                            ],
                            'required' => ['id', 'title'],
                        ],
                    ],
                ],
                'required' => ['titles'],
            ],
            ['label' => 'заголовки постов Instagram', 'maxTokens' => 2048, 'temperature' => 0.3]
        );

        $out = [];
        foreach ((array) ($result['titles'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            $title = InstagramPostTitle::clean((string) ($row['title'] ?? ''));
            if ($id !== '' && $title !== '' && isset($captions[$id])) {
                $out[$id] = $title;
            }
        }

        return $out;
    }
}
