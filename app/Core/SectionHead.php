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
     * Типы блоков, у которых есть шапка секции: им форма предлагает
     * подзаголовок и выравнивание заголовка (`_head_*`). Остальные либо без
     * заголовка (кнопки, разделитель), либо держат свой (обложка, призыв к
     * действию, текст) — настройка там ничего бы не меняла. Список сверяет
     * с шаблонами тест 433.
     *
     * @var list<string>
     */
    public const DECORATED_TYPES = [
        'cards_grid', 'chart', 'collage', 'contact_cards', 'counters', 'docs_list', 'embed', 'faq',
        'icon_text', 'image', 'instagram_feed', 'map_point', 'media_gallery', 'news_docs',
        'news_feature', 'news_latest', 'org_structure', 'partners', 'projects_list', 'slider',
        'stages', 'table', 'team_list', 'testimonials',
    ];

    /**
     * Подзаголовок и выравнивание из оформления секции.
     *
     * Применяется к готовой разметке блока, а не через параметры render():
     * шапку печатают два десятка шаблонов, часть — своей разметкой, и
     * протаскивать настройку через каждый значило бы получить два десятка мест,
     * где её можно забыть. Трогается только первая шапка блока — у вложенных
     * карточек своих шапок нет, а у ленты новостей вторая шапка принадлежит
     * колонке, а не секции.
     *
     * @param array<string, mixed> $data
     */
    public static function decorate(string $html, array $data): string
    {
        $kicker = trim((string) ($data['_head_kicker'] ?? ''));
        $center = ($data['_head_align'] ?? '') === 'center';
        if ($kicker === '' && !$center) {
            return $html;
        }
        if (preg_match('~<div class="section-head(?=[ "])~', $html, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return $html;
        }
        $headAt = (int) $m[0][1];
        if ($kicker !== '') {
            // Подзаголовок стоит прямо перед заголовком: шапки собраны
            // по-разному (с обёрткой __copy и без), а заголовок есть у всех.
            if (preg_match('~<h[2-4] class="section-head__title~', $html, $t, PREG_OFFSET_CAPTURE, $headAt) === 1) {
                $style = in_array($data['_head_kicker_style'] ?? '', ['line', 'plain'], true) ? (string) $data['_head_kicker_style'] : 'line';
                $kickerHtml = '<p class="section-head__kicker section-head__kicker--' . $style . '">'
                    . htmlspecialchars($kicker, ENT_QUOTES) . '</p>';
                $html = substr_replace($html, $kickerHtml, (int) $t[0][1], 0);
            }
        }
        if ($center) {
            $html = substr_replace($html, '<div class="section-head section-head--align-center', $headAt, strlen('<div class="section-head'));
        }

        return $html;
    }

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
            $all = $allUrl !== '' ? self::allLink($allText, $allUrl) : '';
            // По умолчанию ссылка идёт первой — так у каруселей, где следом
            // стоят стрелки. У переключателя вкладок порядок обратный: вкладки
            // это навигация по содержимому, а ссылка «все» — выход из него, и
            // читаться она должна после, у правого края строки.
            $html .= ($options['all_after_tools'] ?? false) ? $tools . $all : $all . $tools;
            $html .= '</div>';
        }

        return $html . '</div>';
    }

    /**
     * Ссылка «Все …» раздела.
     *
     * Стрелка — отдельный элемент, скрытый от диктора: «Все новости стрелка
     * вправо» ему ничего не сообщает, а настройка «Дизайна» «Ссылки разделов»
     * (`design-links-draw`) заменяет стрелку рисуемым подчёркиванием. Пока
     * стрелка была частью текста, спрятать её было нечем. Шаблоны, которые
     * собирают шапку сами, берут ссылку отсюда же — пятая копия разметки
     * разъехалась бы с остальными при первой правке.
     */
    public static function allLink(string $text, string $url): string
    {
        return '<a class="section-head__all" href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
            . '<span class="section-head__all-text">' . htmlspecialchars($text, ENT_QUOTES) . '</span>'
            . '<span class="section-head__arrow" aria-hidden="true">→</span></a>';
    }
}
