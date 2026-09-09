<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Единая шапка секции блока: заголовок, вводный текст, ссылка «все» и место
 * под инструменты (стрелки карусели, фильтры).
 *
 * Разметку `.section-head` каждый шаблон собирал сам, и расхождения копились:
 * где-то ссылка «все» оказывалась внутри условия по заголовку и пропадала
 * вместе с ним, где-то не было обёртки `__copy` и вводный текст растягивался
 * на всю ширину. Здесь один источник правды — шаблонам остаётся передать
 * значения.
 */
final class SectionHead
{
    /**
     * @param array{
     *     title?: string,
     *     description?: string,
     *     description_html?: bool,
     *     all_text?: string,
     *     all_url?: string,
     *     all_after_tools?: bool,
     *     tools?: string,
     *     class?: string,
     *     level?: string,
     *     title_class?: string,
     *     description_class?: string
     * } $options
     */
    public static function render(array $options = []): string
    {
        $title = trim((string) ($options['title'] ?? ''));
        $description = (string) ($options['description'] ?? '');
        if (!($options['description_html'] ?? false)) {
            $description = trim($description);
        }
        $tools = (string) ($options['tools'] ?? '');

        // Ссылка «все» живёт отдельно от заголовка: раньше она рендерилась
        // внутри `if ($title !== '')` и исчезала у безымянной секции.
        $allUrl = trim((string) ($options['all_url'] ?? ''));
        if ($allUrl !== '' && !UrlGuard::isSafeLink($allUrl)) {
            $allUrl = '';
        }
        $allText = trim((string) ($options['all_text'] ?? ''));
        if ($allUrl !== '' && $allText === '') {
            $allText = t('Все');
        }

        if ($title === '' && $description === '' && $tools === '' && $allUrl === '') {
            return '';
        }

        // H4 нужен группам внутри готового раздела (контейнер «Колонки» внутри
        // страницы, где H2 и H3 уже заняты): уровень — структура документа, по
        // нему диктор строит оглавление, поэтому пропуск ступени хуже мелкого
        // заголовка.
        $level = (string) ($options['level'] ?? 'h2');
        if (!in_array($level, ['h2', 'h3', 'h4'], true)) {
            $level = 'h2';
        }
        $titleClass = trim('section-head__title ' . (string) ($options['title_class'] ?? ''));
        $class = trim('section-head ' . (string) ($options['class'] ?? ''));

        $html = '<div class="' . htmlspecialchars($class, ENT_QUOTES) . '">';

        if ($title !== '' || $description !== '') {
            $html .= '<div class="section-head__copy">';
            if ($title !== '') {
                // Разметка строки (*слово* и | ) разбирается здесь же: общая
                // шапка секции — это заголовок полутора десятков блоков, и
                // экранирование в обход TitleMarkup оставляло их без обоих
                // приёмов, хотя у блоков со своим заголовком они работали.
                $html .= '<' . $level . ' class="' . htmlspecialchars($titleClass, ENT_QUOTES) . '">'
                    . TitleMarkup::html($title) . '</' . $level . '>';
            }
            if ($description !== '') {
                // HTML пропускаем только по явному флагу: вызывающий обязан
                // подать уже очищенную разметку (TextProcessor/HtmlSanitizer).
                $descriptionClass = trim('section-head__description '
                    . (($options['description_html'] ?? false) ? 'rich-content ' : '')
                    . (string) ($options['description_class'] ?? ''));
                $html .= '<div class="' . htmlspecialchars($descriptionClass, ENT_QUOTES) . '">'
                    . (($options['description_html'] ?? false)
                        ? $description
                        : '<p>' . htmlspecialchars($description, ENT_QUOTES) . '</p>')
                    . '</div>';
            }
            $html .= '</div>';
        }

        if ($allUrl !== '' || $tools !== '') {
            $html .= '<div class="section-head__tools">';
            $all = $allUrl !== ''
                ? '<a class="section-head__all" href="' . htmlspecialchars($allUrl, ENT_QUOTES) . '">'
                    . htmlspecialchars($allText, ENT_QUOTES) . ' →</a>'
                : '';
            // По умолчанию ссылка идёт первой — так у каруселей, где следом
            // стоят стрелки. У переключателя вкладок порядок обратный: вкладки
            // это навигация по содержимому, а ссылка «все» — выход из него, и
            // читаться она должна после, у правого края строки.
            $html .= ($options['all_after_tools'] ?? false) ? $tools . $all : $all . $tools;
            $html .= '</div>';
        }

        return $html . '</div>';
    }
}
