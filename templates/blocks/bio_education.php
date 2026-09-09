<?php
/** @var array $data */
$career = $data['career'] ?? [];
$careerTitle = trim((string) ($data['career_title'] ?? ''));
$edu = $data['edu_items'] ?? [];
$extraTitle = trim((string) ($data['extra_title'] ?? ''));
$extraText = trim((string) ($data['extra_text'] ?? ''));
$widgetsBefore = trim((string) ($data['_widgets_before_html'] ?? ''));
$widgetsAfter = trim((string) ($data['_widgets_after_html'] ?? ''));
$quote = trim((string) ($data['quote_text'] ?? ''));
?>
<div class="block-bio">
    <div class="bio-main">
        <?php if (!empty($data['bio_title'])): ?><h2 class="bio__title"><?= htmlspecialchars((string) $data['bio_title'], ENT_QUOTES) ?></h2><?php endif; ?>
        <?php if (!empty($data['bio_text'])): ?>
            <?php /* Пустая строка разделяет абзацы. Прежде весь текст шёл одним
                     куском с <br><br>: при межстрочном интервале биографии это
                     давало полсотни пикселей между абзацами и не давало
                     скринридеру границ абзаца. */ ?>
            <div class="bio__text">
                <?php foreach (preg_split('/\R\s*\R/u', trim((string) $data['bio_text'])) ?: [] as $paragraph): ?>
                    <?php if (trim($paragraph) === '') { continue; } ?>
                    <p><?= nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES)) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($career)): ?>
            <?php if ($careerTitle !== ''): ?><h3 class="bio-career__title"><?= htmlspecialchars($careerTitle, ENT_QUOTES) ?></h3><?php endif; ?>
            <ol class="bio-career">
                <?php foreach ($career as $row): ?>
                    <li class="bio-career__item">
                        <span class="bio-career__years"><?= htmlspecialchars((string) ($row['years'] ?? ''), ENT_QUOTES) ?></span>
                        <span class="bio-career__text"><?= nl2br(htmlspecialchars((string) ($row['text'] ?? ''), ENT_QUOTES)) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </div>
    <div class="bio-side">
        <?php if ($widgetsBefore !== ''): ?>
            <div class="bio-side__widgets bio-side__widgets--before"><?= $widgetsBefore ?></div>
        <?php endif; ?>
        <div class="bio-edu">
            <?php if (!empty($data['edu_title'])): ?><h2 class="bio__title"><?= htmlspecialchars((string) $data['edu_title'], ENT_QUOTES) ?></h2><?php endif; ?>
            <?php if (!empty($edu)): ?>
                <ol class="bio-edu__list">
                    <?php foreach ($edu as $row): ?>
                        <li class="bio-edu__item">
                            <span class="bio-edu__years"><?= htmlspecialchars((string) ($row['years'] ?? ''), ENT_QUOTES) ?></span>
                            <span class="bio-edu__degree"><?= htmlspecialchars((string) ($row['title'] ?? ''), ENT_QUOTES) ?></span>
                            <?php if (!empty($row['org'])): ?><span class="bio-edu__org"><?= htmlspecialchars((string) $row['org'], ENT_QUOTES) ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
            <?php if ($extraTitle !== '' || $extraText !== ''): ?>
                <div class="bio-extra">
                    <?php if ($extraTitle !== ''): ?><h3 class="bio-extra__title"><?= htmlspecialchars($extraTitle, ENT_QUOTES) ?></h3><?php endif; ?>
                    <?php if ($extraText !== ''): ?>
                        <ul class="bio-extra__list">
                            <?php foreach (preg_split('/\r\n|\r|\n/', $extraText) ?: [] as $line): ?>
                                <?php if (trim($line) === '') { continue; } ?>
                                <li><?= htmlspecialchars(trim($line), ENT_QUOTES) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($widgetsAfter !== ''): ?>
            <div class="bio-side__widgets bio-side__widgets--after"><?= $widgetsAfter ?></div>
        <?php endif; ?>
        <?php if ($quote !== ''): ?>
            <figure class="bio-quote">
                <span class="bio-quote__mark">“</span>
                <blockquote class="bio-quote__text"><?= htmlspecialchars($quote, ENT_QUOTES) ?></blockquote>
                <?php if (!empty($data['quote_author'])): ?><figcaption class="bio-quote__author">— <?= htmlspecialchars((string) $data['quote_author'], ENT_QUOTES) ?></figcaption><?php endif; ?>
            </figure>
        <?php endif; ?>
    </div>
</div>
