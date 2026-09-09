<?php

declare(strict_types=1);

namespace App\Core\BlockData;

use App\Core\HtmlSanitizer;
use App\Core\TextProcessor;

/**
 * Нормализатор блока «Вопросы и ответы»: собирает только репитер, скалярные
 * поля описаны схемой (`BlockFieldSchema`).
 */
final class FaqBlockNormalizer
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input, string $locale = 'ru'): array
    {
        $items = [];
        foreach ((array) ($input['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $question = trim((string) ($item['question'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? ''));
            if ($question === '' && $answer === '') {
                continue;
            }

            $items[] = [
                'question' => TextProcessor::typographPlain($question, $locale),
                'answer' => TextProcessor::process(HtmlSanitizer::sanitizeText($answer), $locale),
                'category' => TextProcessor::typographPlain(trim((string) ($item['category'] ?? '')), $locale),
            ];
        }

        return array_merge(
            BlockFieldSchema::normalize('faq', $input, $locale),
            ['items' => $items]
        );
    }
}
