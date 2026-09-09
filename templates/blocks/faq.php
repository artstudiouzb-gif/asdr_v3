<?php
/** @var array $data */
$title = $data['title'] ?? '';
$items = $data['items'] ?? [];
$searchEnabled = !array_key_exists('search_enabled', $data) || !empty($data['search_enabled']);
$singleOpen = !empty($data['single_open']);
$categories = array_values(array_unique(array_filter(array_map(
    static fn (array $item): string => trim((string) ($item['category'] ?? '')),
    is_array($items) ? $items : []
))));
?>
<div class="block-faq" data-faq-list<?= $singleOpen ? ' data-faq-single' : '' ?>>
    <?php if ($title !== ''): ?><h2 class="block-faq__title"><?= \App\Core\TitleMarkup::html($title) ?></h2><?php endif; ?>
    <?php if ($searchEnabled && count($items) >= 4): ?>
        <div class="faq-tools" role="search" aria-label="<?= htmlspecialchars(t('Поиск по вопросам'), ENT_QUOTES) ?>">
            <label class="faq-tools__search">
                <span class="visually-hidden"><?= htmlspecialchars(t('Найти вопрос'), ENT_QUOTES) ?></span>
                <?= \App\Core\Icon::render('search', 18, 'faq-tools__search-icon', 1.8) ?>
                <input type="search" data-faq-query placeholder="<?= htmlspecialchars(t('Найти вопрос…'), ENT_QUOTES) ?>" autocomplete="off">
            </label>
            <?php if (count($categories) > 1): ?>
                <label class="faq-tools__category">
                    <span class="visually-hidden"><?= htmlspecialchars(t('Категория вопросов'), ENT_QUOTES) ?></span>
                    <select data-faq-category>
                        <option value=""><?= htmlspecialchars(t('Все категории'), ENT_QUOTES) ?></option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= htmlspecialchars(mb_strtolower($category), ENT_QUOTES) ?>"><?= htmlspecialchars($category, ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="block-faq__list">
        <?php foreach ($items as $index => $item): ?>
            <?php
            $question = trim((string) ($item['question'] ?? ''));
            $category = trim((string) ($item['category'] ?? ''));
            $search = mb_strtolower(trim($question . ' ' . strip_tags((string) ($item['answer'] ?? '')) . ' ' . $category));
            $itemId = 'faq-' . (int) $blockId . '-' . ((int) $index + 1);
            ?>
            <details class="faq-item" id="<?= $itemId ?>" data-faq-item data-faq-search="<?= htmlspecialchars($search, ENT_QUOTES) ?>" data-faq-category-value="<?= htmlspecialchars(mb_strtolower($category), ENT_QUOTES) ?>">
                <summary class="faq-item__q">
                    <span><?= htmlspecialchars($question, ENT_QUOTES) ?></span>
                    <?php if ($category !== ''): ?><span class="faq-item__category"><?= htmlspecialchars($category, ENT_QUOTES) ?></span><?php endif; ?>
                </summary>
                <?php // Повторная очистка защищает ответы, сохранённые до строгого allowlist. ?>
                <div class="faq-item__a rich-content"><?= \App\Core\HtmlSanitizer::sanitizeText((string) ($item['answer'] ?? '')) ?></div>
            </details>
        <?php endforeach; ?>
    </div>
    <p class="block-faq__empty" data-faq-empty hidden><?= htmlspecialchars(t('Ничего не найдено.'), ENT_QUOTES) ?></p>
</div>

<?php
// Расширенный сниппет с раскрывающимися вопросами прямо в выдаче. Разметка
// собирается из тех же строк, что видит посетитель, и выводится только у
// первого блока вопросов на странице: два FAQPage поисковик отбрасывает оба.
if (\App\Core\SchemaOrg::claimFaqPage()) {
    echo \App\Core\SchemaOrg::render(\App\Core\SchemaOrg::faqPage(array_map(
        static fn (array $item): array => [
            'question' => (string) ($item['question'] ?? ''),
            'answer' => (string) ($item['answer'] ?? ''),
        ],
        array_values(array_filter(is_array($items) ? $items : [], 'is_array'))
    )));
}
?>
