<?php

use App\Core\Icon;
use App\Core\Media;
use App\Core\NewsBadge;
use App\Core\NewsFeedRhythm;

/**
 * Одна карточка ритма новостей — общая разметка для ленты `/news` и для
 * мозаики блока «Подборка новостей».
 *
 * Зачем партиал: вид карточки задаёт `NewsFeedRhythm`, и мест вывода у него
 * два. Пока разметка стояла в каждом по копии, они разъезжались молча —
 * ровно тот случай, ради которого настройка блока объявляется один раз в
 * схеме полей. Слот приезжает снаружи, потому что позиция в цикле у ленты и
 * у блока считается по-разному (`slot()` против `blockSlot()`), а сама
 * карточка о цикле знать не обязана.
 *
 * Карточка ждёт **приведённую** запись, а не строку из базы: обложка уже
 * найдена, рубрика уже переведена в название, адрес уже собран. Иначе
 * партиалу пришлось бы знать и про модель, и про язык.
 *
 * @var array{
 *     url: string, title: string, published_at?: string, excerpt?: string,
 *     badge?: string, badge_color?: string|null, category?: string, cover?: ?string
 * } $card
 * @var string $slot Вид карточки: hero | wide | compact
 * @var callable(string):string $cardDate Формат даты
 */
$isHero = $slot === NewsFeedRhythm::SLOT_HERO;
$isWide = $slot === NewsFeedRhythm::SLOT_WIDE;

// Анонс — только у крупных: в компактную карточку он не помещается, а
// обрезанный до строки не сообщает ничего.
$cardExcerpt = $isHero || $isWide ? trim((string) ($card['excerpt'] ?? '')) : '';

// У крупных кадр лежит подложкой всей карточки, поэтому его просят во всю
// ширину колонки, а не под размер ячейки.
$cardSizes = $isHero
    ? '(max-width: 560px) 100vw, 50vw'
    : ($isWide ? '(max-width: 560px) 100vw, 30vw' : '(max-width: 700px) 100vw, 25vw');

$cardCover = trim((string) ($card['cover'] ?? ''));
$cardCategory = trim((string) ($card['category'] ?? ''));
?>
<a class="relnews-card relnews-card--<?= $slot ?>" href="<?= htmlspecialchars((string) $card['url'], ENT_QUOTES) ?>">
    <span class="news-cover">
        <?php if ($cardCover !== ''): ?>
            <?= Media::picture($cardCover, (string) $card['title'], null, null, 'relnews-card__img', !$isHero, $cardSizes, $isHero, 'relnews-card__media') ?>
        <?php else: ?>
            <span class="relnews-card__media relnews-card__media--empty" aria-hidden="true"></span>
        <?php endif; ?>
        <?= NewsBadge::renderOverlay($card['badge'] ?? '', $card['badge_color'] ?? null) ?>
    </span>
    <span class="relnews-card__body">
        <span class="news-meta">
            <?php if (!empty($card['published_at'])): ?>
                <time class="relnews-card__date"><?= Icon::render('calendar', 15, 'ui-icon', 1.7) ?><?= htmlspecialchars($cardDate((string) $card['published_at']), ENT_QUOTES) ?></time>
            <?php endif; ?>
            <?php if ($cardCategory !== ''): ?><span class="news-category"><?= htmlspecialchars($cardCategory, ENT_QUOTES) ?></span><?php endif; ?>
        </span>
        <h3 class="relnews-card__title"><?= htmlspecialchars((string) $card['title'], ENT_QUOTES) ?></h3>
        <?php if ($cardExcerpt !== ''): ?>
            <span class="relnews-card__excerpt"><?= htmlspecialchars($cardExcerpt, ENT_QUOTES) ?></span>
        <?php endif; ?>
        <?php // «Читать подробнее» в ритме нет: карточка сама является ссылкой,
              // и надпись ничего не добавляла — диктору она была скрыта
              // (aria-hidden), а глазу повторяла очевидное, зато на каждой
              // карточке рисовала лишнюю строку и рвала низ ряда. ?>
    </span>
</a>
