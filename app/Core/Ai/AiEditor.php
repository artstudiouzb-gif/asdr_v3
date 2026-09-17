<?php

declare(strict_types=1);

namespace App\Core\Ai;

use App\Core\TitleMarkup;

/**
 * Редакторские задачи над готовой записью: вычитка и рубрикация.
 *
 * Обе — **подсказки**, а не действия: ничего не сохраняется само,
 * результат попадает в поле или в список замечаний, публикует человек. Это не
 * перестраховка: на госсайте выдуманная должность живёт до первой жалобы, а
 * стоит она дороже, чем сэкономленная минута редактора.
 */
final class AiEditor
{
    /** Замечания важнее трёх штук редактор не читает — он их пролистывает. */
    private const MAX_REMARKS = 6;

    /**
     * Вычитка: то, чего формальный чек-лист увидеть не может.
     *
     * `ContentChecklist` отвечает на вопрос «все ли поля заполнены» — обложка,
     * рубрика, перевод. Здесь другой вопрос: соответствует ли заголовок тексту,
     * не пересказывает ли лид первый абзац, нет ли канцелярита вместо смысла.
     * Ничего не блокирует — как и чек-лист.
     *
     * @return array{remarks: list<array{level:string, text:string}>, notice:string}
     */
    public static function review(string $title, string $lead, string $content, string $kind = 'новость'): array
    {
        if (!AiClient::configured()) {
            return ['remarks' => [], 'notice' => 'Ключ Gemini не настроен: вычитка недоступна.'];
        }

        $result = AiClient::json(
            'Ты требовательный выпускающий редактор официального сайта государственного агентства. '
                . 'Ищешь то, что мешает читателю понять материал.',
            "Вычитай материал (" . $kind . ") и назови только настоящие замечания:\n"
                . "- заголовок не отражает главного факта текста;\n"
                . "- лид дословно повторяет первый абзац или ничего не добавляет;\n"
                . "- канцелярит и отглагольные конструкции вместо простых формулировок;\n"
                . "- пропущено главное: кто, что, когда, зачем это читателю;\n"
                . "- числа, даты и должности, противоречащие друг другу внутри текста.\n\n"
                . "Правила ответа:\n"
                . "- не больше " . self::MAX_REMARKS . " замечаний, самые важные первыми;\n"
                . "- каждое замечание — одна строка: что не так и что сделать;\n"
                . "- level: high — мешает понять материал, normal — стоит поправить;\n"
                . "- нечего сказать — верни пустой список, выдумывать замечания нельзя;\n"
                . "- не предлагай добавить то, чего не может быть в этом материале.\n\n"
                . "Заголовок:\n" . TitleMarkup::plain($title)
                . "\n\nЛид:\n" . mb_substr($lead, 0, 2000)
                . "\n\nТекст:\n" . mb_substr($content, 0, 20000),
            [
                'type' => 'object',
                'properties' => [
                    'remarks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'level' => ['type' => 'string', 'enum' => ['high', 'normal']],
                                'text' => ['type' => 'string'],
                            ],
                            'required' => ['level', 'text'],
                        ],
                    ],
                ],
                'required' => ['remarks'],
            ],
            ['label' => 'вычитка материала', 'maxTokens' => 900, 'temperature' => 0.2]
        );

        if ($result === null) {
            return ['remarks' => [], 'notice' => 'Gemini не ответил — вычитку можно повторить позже.'];
        }

        $remarks = [];
        foreach ((array) ($result['remarks'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $text = trim((string) ($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $remarks[] = [
                'level' => (string) ($item['level'] ?? 'normal') === 'high' ? 'high' : 'normal',
                'text' => mb_substr($text, 0, 300),
            ];
            if (count($remarks) >= self::MAX_REMARKS) {
                break;
            }
        }

        return [
            'remarks' => $remarks,
            'notice' => $remarks === [] ? 'Замечаний нет — по тексту всё в порядке.' : '',
        ];
    }

    /**
     * Рубрика, метка и хештеги.
     *
     * Рубрика выбирается **только из существующих**: право заводить разделы
     * принадлежит редактору, и новая рубрика «Экономика и финансы» рядом с
     * живой «Экономикой» — это тихий раскол рубрикатора. Модель возвращает
     * slug, и он сверяется со списком: чужое значение отбрасывается.
     *
     * @param array<int, array{id:int, slug:string, name:string}> $categories
     * @return array{category_id:int, badge:string, hashtags:string, notice:string}
     */
    public static function classify(string $title, string $content, array $categories): array
    {
        if ($categories === []) {
            return ['category_id' => 0, 'badge' => '', 'hashtags' => '', 'notice' => 'Рубрик ещё нет — сначала заведите их в разделе «Категории новостей».'];
        }
        if (!AiClient::configured()) {
            return ['category_id' => 0, 'badge' => '', 'hashtags' => '', 'notice' => 'Ключ Gemini не настроен: рубрику и метки подобрать нечем.'];
        }

        $known = [];
        $list = [];
        foreach ($categories as $category) {
            $slug = (string) ($category['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $known[$slug] = (int) ($category['id'] ?? 0);
            $list[] = '- ' . $slug . ': ' . (string) ($category['name'] ?? '');
        }

        $result = AiClient::json(
            'Ты редактор новостной ленты официального сайта. Раскладываешь материалы по существующим рубрикам.',
            "Определи рубрику, метку и хештеги.\n\n"
                . "Рубрика — ровно один slug из списка ниже; подходящей нет — верни пустую строку:\n"
                . implode("\n", $list) . "\n\n"
                . "Метка (badge) — короткое слово-пометка на карточке: «Важно», «Анонс», «Итоги», «Официально». "
                . "Материал рядовой — верни пустую строку, метка на каждой новости обесценивает её.\n"
                . "Хештеги — 3–5 конкретных тем через пробел, без общих слов вроде #новости.\n\n"
                . "Заголовок:\n" . TitleMarkup::plain($title)
                . "\n\nТекст:\n" . mb_substr($content, 0, 12000),
            [
                'type' => 'object',
                'properties' => [
                    'category' => ['type' => 'string', 'description' => 'slug рубрики из списка или пустая строка'],
                    'badge' => ['type' => 'string'],
                    'hashtags' => ['type' => 'string'],
                ],
                'required' => ['category', 'badge', 'hashtags'],
            ],
            ['label' => 'рубрикация новости', 'maxTokens' => 256, 'temperature' => 0.1]
        );

        if ($result === null) {
            return ['category_id' => 0, 'badge' => '', 'hashtags' => '', 'notice' => 'Gemini не ответил — попробуйте ещё раз.'];
        }

        $slug = AiClient::str($result, 'category');
        $badge = mb_substr(AiClient::str($result, 'badge'), 0, 40);

        return [
            'category_id' => $known[$slug] ?? 0,
            'badge' => $badge,
            'hashtags' => self::hashtags(AiClient::str($result, 'hashtags')),
            'notice' => $slug !== '' && !isset($known[$slug])
                // Придуманная рубрика — не ошибка модели, а ответ «ни одна не
                // подходит», сказанный неудачно. Молча подставлять ближайшую
                // нельзя: рубрика решает, где новость окажется на сайте.
                ? 'Подходящей рубрики в списке не нашлось — выберите её сами.'
                : '',
        ];
    }

    /** Приводит хештеги к одному виду: «#слово» через пробел, без повторов. */
    private static function hashtags(string $raw): string
    {
        $tags = [];
        foreach (preg_split('/[\s,]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tag) {
            $tag = '#' . ltrim(trim($tag), '#');
            if (mb_strlen($tag) > 2 && !in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
            if (count($tags) >= 5) {
                break;
            }
        }

        return implode(' ', $tags);
    }
}
