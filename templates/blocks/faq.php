<?php
/** @var array $data */
use App\Core\UrlGuard;

$title = $data['title'] ?? '';
$items = $data['items'] ?? [];
$panel = (string) $data['panel'];
$layout = (string) $data['layout'];
$split = $layout === 'split';
$searchEnabled = !array_key_exists('search_enabled', $data) || !empty($data['search_enabled']);
$singleOpen = !empty($data['single_open']);
$categoryCounts = [];
foreach (is_array($items) ? $items : [] as $item) {
    $name = trim((string) ($item['category'] ?? ''));
    if ($name !== '') {
        $categoryCounts[$name] = ($categoryCounts[$name] ?? 0) + 1;
    }
}
$categories = array_keys($categoryCounts);
$showTools = $searchEnabled && count($items) >= 4;
// В двух колонках темы — кнопки в левой колонке, а не список рядом с поиском:
// там есть место, и выбранная тема видна сразу, без раскрытия списка.
$topicButtons = $split && $showTools && count($categories) > 1;

$intro = trim((string) $data['intro']);
$contactLabel = trim((string) $data['contact_label']);
$contactValue = trim((string) $data['contact_value']);
$contactLinkText = trim((string) $data['contact_link_text']);
$contactLinkUrl = trim((string) $data['contact_link_url']);
if ($contactLinkUrl !== '' && !UrlGuard::isSafeLink($contactLinkUrl)) {
    $contactLinkUrl = '';
}
// Телефон и почта становятся ссылками сами: редактор набирает их как в
// документе, а посетителю с телефона нужен звонок одним касанием.
$contactHref = '';
if (filter_var($contactValue, FILTER_VALIDATE_EMAIL)) {
    $contactHref = 'mailto:' . $contactValue;
} elseif (preg_match('/^\+?[\d\s()\-]{5,}$/', $contactValue)) {
    $contactHref = 'tel:' . preg_replace('/[^\d+]/', '', $contactValue);
}
$hasContact = $contactValue !== '' || ($contactLinkText !== '' && $contactLinkUrl !== '');
?>
<div class="block-faq block-faq--panel-<?= htmlspecialchars($panel, ENT_QUOTES) ?><?= $split ? ' block-faq--split' : '' ?>" data-faq-list<?= $singleOpen ? ' data-faq-single' : '' ?>>
    <?php if ($split): ?><div class="block-faq__aside"><?php endif; ?>
    <?= \App\Core\SectionHead::render(['title' => $title]) ?>
    <?php if ($split && $intro !== ''): ?>
        <p class="block-faq__intro"><?= nl2br(htmlspecialchars($intro, ENT_QUOTES)) ?></p>
    <?php endif; ?>
    <?php if ($topicButtons): ?>
        <?php // Кнопки без скрипта ничего не фильтруют — поэтому их показывает скрипт. ?>
        <div class="faq-topics" role="group" aria-label="<?= htmlspecialchars(t('Темы вопросов'), ENT_QUOTES) ?>" data-faq-topics hidden>
            <button type="button" class="faq-topics__item" data-faq-topic="" aria-pressed="true"><?= htmlspecialchars(t('Все темы'), ENT_QUOTES) ?> <span class="faq-topics__count"><?= count($items) ?></span></button>
            <?php foreach ($categoryCounts as $category => $count): ?>
                <button type="button" class="faq-topics__item" data-faq-topic="<?= htmlspecialchars(mb_strtolower((string) $category), ENT_QUOTES) ?>" aria-pressed="false"><?= htmlspecialchars((string) $category, ENT_QUOTES) ?> <span class="faq-topics__count"><?= (int) $count ?></span></button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($split && $hasContact): ?>
        <div class="block-faq__contact">
            <?php if ($contactLabel !== ''): ?><span class="block-faq__contact-label"><?= htmlspecialchars($contactLabel, ENT_QUOTES) ?></span><?php endif; ?>
            <?php if ($contactValue !== ''): ?>
                <?php if ($contactHref !== ''): ?>
                    <a class="block-faq__contact-value" href="<?= htmlspecialchars($contactHref, ENT_QUOTES) ?>"><?= htmlspecialchars($contactValue, ENT_QUOTES) ?></a>
                <?php else: ?>
                    <span class="block-faq__contact-value"><?= htmlspecialchars($contactValue, ENT_QUOTES) ?></span>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($contactLinkText !== '' && $contactLinkUrl !== ''): ?>
                <a class="block-faq__contact-link" href="<?= htmlspecialchars($contactLinkUrl, ENT_QUOTES) ?>"><?= htmlspecialchars($contactLinkText, ENT_QUOTES) ?></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($split): ?></div><div class="block-faq__main"><?php endif; ?>
    <?php if ($showTools): ?>
        <div class="faq-tools" role="search" aria-label="<?= htmlspecialchars(t('Поиск по вопросам'), ENT_QUOTES) ?>">
            <label class="faq-tools__search">
                <span class="visually-hidden"><?= htmlspecialchars(t('Найти вопрос'), ENT_QUOTES) ?></span>
                <?= \App\Core\Icon::render('search', 18, 'faq-tools__search-icon', 1.8) ?>
                <input type="search" data-faq-query placeholder="<?= htmlspecialchars(t('Найти вопрос…'), ENT_QUOTES) ?>" autocomplete="off">
            </label>
            <?php if (!$topicButtons && count($categories) > 1): ?>
                <label class="faq-tools__category">
                    <span class="visually-hidden"><?= htmlspecialchars(t('Категория вопросов'), ENT_QUOTES) ?></span>
                    <select data-faq-category>
                        <option value=""><?= htmlspecialchars(t('Все категории'), ENT_QUOTES) ?></option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= htmlspecialchars(mb_strtolower((string) $category), ENT_QUOTES) ?>"><?= htmlspecialchars((string) $category, ENT_QUOTES) ?></option>
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
                    <?php if ($category !== '' && !$topicButtons): ?><span class="faq-item__category"><?= htmlspecialchars($category, ENT_QUOTES) ?></span><?php endif; ?>
                </summary>
                <?php // Повторная очистка защищает ответы, сохранённые до строгого allowlist. ?>
                <div class="faq-item__a rich-content"><?= \App\Core\HtmlSanitizer::sanitizeText((string) ($item['answer'] ?? '')) ?></div>
            </details>
        <?php endforeach; ?>
    </div>
    <p class="block-faq__empty" data-faq-empty hidden><?= htmlspecialchars(t('Ничего не найдено.'), ENT_QUOTES) ?></p>
    <?php if ($split): ?></div><?php endif; ?>
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
