<?php

declare(strict_types=1);

use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockRenderer;
use App\Core\VariantPreview;

/**
 * «Вопросы и ответы», раскладка «Две колонки»: слева закреплены заголовок,
 * пояснение, темы кнопками и контакт, справа поиск и вопросы. Умолчание —
 * «Одна колонка», прежний вид.
 */

/** @param array<string, mixed> $data */
function faq_split_html(array $data): string
{
    $items = [];
    foreach (['Обращения', 'Обращения', 'Данные', 'Данные', 'Вакансии'] as $i => $category) {
        $items[] = ['question' => 'Вопрос ' . $i, 'answer' => '<p>Ответ.</p>', 'category' => $category];
    }
    $rendered = BlockRenderer::render([
        'id' => 397,
        'type' => 'faq',
        'data' => json_encode($data + ['title' => 'Вопросы', 'items' => $items, 'search_enabled' => 1], JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);

    return (string) $rendered['html'];
}

test('Раскладка вопросов предложена плитками, поля колонки видны только у двух колонок', function (): void {
    $fields = BlockFieldSchema::all()['faq'];
    assert_same(['stack', 'split'], array_keys($fields['layout']->options));
    assert_same('stack', $fields['layout']->default, 'умолчание обязано быть прежним видом');
    assert_true(VariantPreview::isKnown('qa+aside'), 'рисунок раскладки не описан');
    assert_contains('<svg', VariantPreview::svg('qa+aside'));
    foreach (['intro', 'contact_label', 'contact_value', 'contact_link_text', 'contact_link_url'] as $key) {
        assert_same(['field' => 'layout', 'values' => ['split']], $fields[$key]->when, "поле {$key} видно там, где ни на что не влияет");
    }
});

test('Две колонки: темы кнопками, контакт ссылкой, без списка категорий', function (): void {
    $html = faq_split_html([
        'layout' => 'split', 'intro' => 'Не нашли ответ?', 'contact_label' => 'Приёмная',
        'contact_value' => '+998 71 000-00-00', 'contact_link_text' => 'Форма', 'contact_link_url' => '/contacts',
    ]);
    assert_contains('block-faq--split', $html);
    assert_contains('block-faq__aside', $html);
    // Кнопки без скрипта ничего не фильтруют — приходят скрытыми.
    assert_contains('data-faq-topics hidden', $html);
    assert_same(4, substr_count($html, 'data-faq-topic='), 'тем «Все» + три категории');
    assert_false(str_contains($html, 'data-faq-category>'), 'второй способ выбрать тему рядом с кнопками');
    assert_contains('href="tel:+998710000000"', $html);
    assert_contains('href="/contacts">Форма', $html);
    assert_contains('Не нашли ответ?', $html);

    assert_contains('href="mailto:info@example.com"', faq_split_html(['layout' => 'split', 'contact_value' => 'info@example.com']));
    $unsafe = faq_split_html(['layout' => 'split', 'contact_link_text' => 'Опасно', 'contact_link_url' => 'javascript:alert(1)']);
    assert_false(str_contains($unsafe, 'javascript:'));
});

test('Одна колонка осталась прежней, а поля колонки на неё не влияют', function (): void {
    $html = faq_split_html(['intro' => 'Скрытое пояснение', 'contact_value' => '+998 71 000-00-00']);
    assert_false(str_contains($html, 'block-faq--split'));
    assert_false(str_contains($html, 'data-faq-topics'));
    assert_contains('data-faq-category>', $html);
    assert_false(str_contains($html, 'Скрытое пояснение'));
    assert_false(str_contains($html, 'tel:'));
});

test('Скрипт открывает темы, а стили закрепляют колонку', function (): void {
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/frontend.js');
    assert_contains("list.querySelector('[data-faq-topics]')", $js);
    assert_contains('topicWrap.hidden = false', $js);
    $css = theme_css();
    assert_contains('.block-faq--split {', $css);
    assert_contains('position: sticky; top: calc(var(--scroll-offset, 0px) + 24px);', $css);
    assert_contains('.faq-topics__item[aria-pressed="true"]', $css);
});
