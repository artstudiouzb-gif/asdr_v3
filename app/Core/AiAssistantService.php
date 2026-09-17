<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Ai\AiClient;

/**
 * ИИ-ассистент редактора: редакционный анонс, хештеги и SEO-метаданные.
 *
 * Запрос к модели ведёт `AiClient` — здесь остаются только задача (что просим)
 * и разбор ответа. Локальный разбор текста при этом никуда не делся: без ключа
 * и при отказе модели кнопка обязана что-то дать, иначе редактор видит
 * сломанную форму вместо ненастроенной интеграции.
 */
final class AiAssistantService
{
    private const TARGETS = ['summary', 'meta_title', 'meta_description'];

    /** Вид записи подставляется в задачу: «новость», «страница», «проект». */
    public const KIND_NEWS = 'новость';

    /**
     * @return array{
     *     excerpt:string,
     *     hashtags:string,
     *     meta_title:string,
     *     meta_description:string,
     *     provider:string,
     *     model:string,
     *     notice:string
     * }
     */
    public static function generateNewsField(string $title, string $content, string $target = 'summary'): array
    {
        return self::generateField($title, $content, $target, self::KIND_NEWS);
    }

    /**
     * Тот же генератор для любой записи с заголовком и текстом: у страницы и
     * проекта SEO-поля те же, и второй набор промптов разъехался бы с первым
     * при первой правке. Отличается только слово, которым материал назван в
     * задаче, — от него зависит формулировка ответа.
     *
     * @return array{
     *     excerpt:string,
     *     hashtags:string,
     *     meta_title:string,
     *     meta_description:string,
     *     provider:string,
     *     model:string,
     *     notice:string
     * }
     */
    public static function generateField(
        string $title,
        string $content,
        string $target = 'summary',
        string $kind = self::KIND_NEWS
    ): array {
        $target = in_array($target, self::TARGETS, true) ? $target : 'summary';
        $cleanTitle = self::cleanText($title);
        $cleanContent = self::cleanText($content);
        $fallback = self::generateLocalNewsFields($cleanTitle, $cleanContent);

        if (!AiClient::configured()) {
            return self::answer(
                $fallback,
                'local',
                '',
                'Ключ Gemini не настроен: применён локальный анализ ключевых фактов. '
                    . 'Для полноценной переформулировки настройте ИИ-интеграцию.'
            );
        }

        $generated = AiClient::json(
            'Ты опытный редактор официального новостного сайта и SEO-специалист. '
                . 'Пиши точно, естественно и информативно. Не выдумывай факты.',
            self::editorialPrompt($cleanTitle, $cleanContent, $target, $kind),
            self::responseSchema($target),
            ['label' => 'генерация текста']
        );

        if (is_array($generated) && self::hasGeneratedTarget($generated, $target)) {
            $result = self::normalizeGeneratedFields($fallback, $generated, $target);
            if (self::hasGeneratedTarget($result, $target)) {
                return self::answer($result, 'gemini', (string) ($generated['_model'] ?? ''), '');
            }
        }

        return self::answer($fallback, 'local', '', 'Gemini временно недоступен: применён локальный анализ ключевых фактов.');
    }

    /**
     * Ответ собирается поимённо, а не дополнением массива полей: набор ключей
     * — это контракт с формой редактора, и «дописали ещё один ключ» читается
     * как «поле появится», пока не окажется, что его никто не печатает.
     *
     * @param array<string, string> $fields
     * @return array{
     *     excerpt:string,
     *     hashtags:string,
     *     meta_title:string,
     *     meta_description:string,
     *     provider:string,
     *     model:string,
     *     notice:string
     * }
     */
    private static function answer(array $fields, string $provider, string $model, string $notice): array
    {
        return [
            'excerpt' => (string) ($fields['excerpt'] ?? ''),
            'hashtags' => (string) ($fields['hashtags'] ?? ''),
            'meta_title' => (string) ($fields['meta_title'] ?? ''),
            'meta_description' => (string) ($fields['meta_description'] ?? ''),
            'provider' => $provider,
            'model' => $model,
            'notice' => $notice,
        ];
    }

    /**
     * Детерминированный резерв: выбирает наиболее информативные предложения
     * по всему материалу, а не копирует начало новости.
     *
     * @return array{excerpt:string,hashtags:string,meta_title:string,meta_description:string}
     */
    public static function generateLocalNewsFields(string $title, string $content): array
    {
        $cleanTitle = self::cleanText($title);
        $cleanContent = self::cleanText($content);
        $excerpt = self::extractKeySentences($cleanTitle, $cleanContent, 360);

        return [
            'excerpt' => $excerpt,
            'hashtags' => self::generateHashtags($cleanTitle, $cleanContent),
            'meta_title' => self::limitAtWord($cleanTitle, 60),
            'meta_description' => self::limitAtWord($excerpt !== '' ? $excerpt : $cleanContent, 160),
        ];
    }

    public static function generateMetaTitle(string $title, string $content = ''): string
    {
        return self::generateLocalNewsFields($title, $content)['meta_title'];
    }

    public static function generateMetaDescription(string $content, int $maxLength = 160): string
    {
        $description = self::generateLocalNewsFields('', $content)['meta_description'];
        return self::limitAtWord($description, $maxLength);
    }

    public static function generateExcerpt(string $content, int $length = 320): string
    {
        $excerpt = self::generateLocalNewsFields('', $content)['excerpt'];
        return self::limitAtWord($excerpt, $length);
    }

    /** @return array<string, mixed> */
    private static function responseSchema(string $target): array
    {
        $properties = match ($target) {
            'meta_title' => [
                'meta_title' => [
                    'type' => 'string',
                    'description' => 'Самостоятельный SEO-заголовок длиной не более 60 символов.',
                ],
            ],
            'meta_description' => [
                'meta_description' => [
                    'type' => 'string',
                    'description' => 'Самостоятельное SEO-описание длиной не более 160 символов.',
                ],
            ],
            default => [
                'excerpt' => [
                    'type' => 'string',
                    'description' => 'Новый редакционный анонс в 1–2 предложениях, не копия начала текста.',
                ],
                'hashtags' => [
                    'type' => 'string',
                    'description' => 'От 3 до 5 тематических хештегов через пробел.',
                ],
            ],
        };

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
        ];
    }

    private static function editorialPrompt(string $title, string $content, string $target, string $kind): string
    {
        $task = match ($target) {
            'meta_title' => <<<'PROMPT'
Создай SEO Meta Title:
- 45–60 символов, главный смысл и ключевая тема в начале;
- это не механическая копия заголовка: улучши ясность и поисковую формулировку;
- без кликбейта, кавычек ради украшения, точки в конце и названия сайта;
- сохрани язык исходного материала.
PROMPT,
            'meta_description' => <<<'PROMPT'
Создай SEO Meta Description:
- 120–160 символов, один связный информативный текст;
- передай главное событие, участника и результат/значение, если они есть;
- не повторяй дословно заголовок и первые строки материала;
- без выдуманных фактов, кликбейта и хештегов; сохрани язык исходного материала.
PROMPT,
            default => <<<'PROMPT'
Создай редакционный анонс и хештеги:
- анонс: 1–3 самостоятельных предложения, примерно 180–360 символов;
- сначала определи главный факт по всему тексту, затем сформулируй его своими словами;
- не копируй первые предложения и не воспроизводи длинные фрагменты исходника дословно;
- сохрани имена, должности, числа и факты; ничего не выдумывай;
- сохрани язык исходного материала;
- хештеги: 3–5 конкретных тематических тегов, без общих слов вроде #новости.
PROMPT,
        };

        return $task
            . "\n\nЗаголовок:\n" . $title
            . "\n\nТекст материала (" . $kind . "):\n" . mb_substr($content, 0, 20000);
    }

    /**
     * @param array<string, string> $fallback
     * @param array<string, mixed> $generated
     * @return array<string, string>
     */
    private static function normalizeGeneratedFields(array $fallback, array $generated, string $target): array
    {
        if ($target === 'summary') {
            $excerpt = self::cleanText((string) ($generated['excerpt'] ?? ''));
            $hashtags = self::normalizeHashtags((string) ($generated['hashtags'] ?? ''));
            if ($excerpt !== '') {
                $fallback['excerpt'] = self::limitAtWord($excerpt, 360);
            }
            if ($hashtags !== '') {
                $fallback['hashtags'] = $hashtags;
            }
        } elseif ($target === 'meta_title') {
            $value = self::cleanText((string) ($generated['meta_title'] ?? ''));
            if ($value !== '') {
                $fallback['meta_title'] = rtrim(self::limitAtWord($value, 60), " \t\n\r\0\x0B.,;:!?—-");
            }
        } else {
            $value = self::cleanText((string) ($generated['meta_description'] ?? ''));
            if ($value !== '') {
                $fallback['meta_description'] = self::limitAtWord($value, 160);
            }
        }

        return $fallback;
    }

    /** @param array<string, mixed> $result */
    private static function hasGeneratedTarget(array $result, string $target): bool
    {
        return match ($target) {
            'meta_title' => self::cleanText((string) ($result['meta_title'] ?? '')) !== '',
            'meta_description' => self::cleanText((string) ($result['meta_description'] ?? '')) !== '',
            default => self::cleanText((string) ($result['excerpt'] ?? '')) !== '',
        };
    }

    private static function extractKeySentences(string $title, string $content, int $maxLength): string
    {
        if ($content === '') {
            return self::limitAtWord($title, $maxLength);
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $titleWords = array_fill_keys(self::meaningfulWords($title), true);
        $ranked = [];

        foreach ($sentences as $index => $sentence) {
            $sentence = trim($sentence);
            $length = mb_strlen($sentence);
            if ($length < 25) {
                continue;
            }

            $score = max(0.0, 1.2 - ($index * 0.08));
            foreach (self::meaningfulWords($sentence) as $word) {
                if (isset($titleWords[$word])) {
                    $score += 2.5;
                }
            }
            if (preg_match('/\d/u', $sentence)) {
                $score += 1.5;
            }
            if ($length >= 60 && $length <= 240) {
                $score += 1.0;
            }
            if (preg_match('/\b(пресс-служб[аы]|напомним|как известно|сообщается на сайте)\b/ui', $sentence)) {
                $score -= 4.0;
            }

            $ranked[] = ['index' => $index, 'score' => $score, 'text' => $sentence];
        }

        if ($ranked === []) {
            return self::limitAtWord($content, $maxLength);
        }

        usort($ranked, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'] ?: $a['index'] <=> $b['index'];
        });

        $selected = [];
        $length = 0;
        foreach ($ranked as $candidate) {
            $candidateLength = mb_strlen($candidate['text']);
            if ($selected !== [] && $length + 1 + $candidateLength > $maxLength) {
                continue;
            }
            $selected[] = $candidate;
            $length += ($length > 0 ? 1 : 0) + $candidateLength;
            if (count($selected) === 2 || $length >= 170) {
                break;
            }
        }

        usort($selected, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);
        return self::limitAtWord(implode(' ', array_column($selected, 'text')), $maxLength);
    }

    private static function generateHashtags(string $title, string $content): string
    {
        $scores = [];
        foreach (self::meaningfulWords($title) as $word) {
            $scores[$word] = ($scores[$word] ?? 0) + 3;
        }
        foreach (self::meaningfulWords($content) as $word) {
            $scores[$word] = ($scores[$word] ?? 0) + 1;
        }
        arsort($scores);

        return implode(' ', array_map(
            static fn (string $word): string => '#' . $word,
            array_slice(array_keys($scores), 0, 5)
        ));
    }

    /** @return list<string> */
    private static function meaningfulWords(string $text): array
    {
        $stopwords = [
            'который', 'которая', 'которые', 'этого', 'также', 'более', 'будет', 'были', 'была',
            'своей', 'своих', 'после', 'между', 'через', 'сообщил', 'сообщила', 'сообщили',
            'uchun', 'bilan', 'hamda', 'bo‘yicha', 'bo\'yicha', 'uning', 'ushbu', 'yangi',
            'the', 'and', 'that', 'with', 'from', 'this', 'will', 'have', 'were',
            'новости', 'новость', 'узбекистан', 'ташкент',
        ];
        $words = preg_split('/[^\p{L}\p{N}’\']+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, static function (string $word) use ($stopwords): bool {
            return mb_strlen($word) >= 4
                && !is_numeric($word)
                && !in_array($word, $stopwords, true);
        }));
    }

    private static function normalizeHashtags(string $hashtags): string
    {
        $parts = preg_split('/[\s,;]+/u', mb_strtolower($hashtags), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $result = [];
        foreach ($parts as $part) {
            $part = trim($part, "# \t\n\r\0\x0B");
            $part = UzbekText::normalizeApostrophes($part);
            $part = (string) preg_replace('/[^\p{L}\p{N}_-]+/u', '', $part);
            if ($part !== '' && mb_strlen($part) >= 3) {
                $result['#' . $part] = true;
            }
            if (count($result) === 5) {
                break;
            }
        }
        return implode(' ', array_keys($result));
    }

    private static function cleanText(string $text): string
    {
        $text = (string) preg_replace('#</?(?:p|div|li|h[1-6]|br|blockquote)\b[^>]*>#iu', ' ', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function limitAtWord(string $text, int $maxLength): string
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $cut = rtrim(mb_substr($text, 0, max(1, $maxLength - 1)));
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace >= (int) floor($maxLength * 0.6)) {
            $cut = rtrim(mb_substr($cut, 0, $lastSpace));
        }

        return rtrim($cut, " \t\n\r\0\x0B.,;:!?—-") . '…';
    }
}
