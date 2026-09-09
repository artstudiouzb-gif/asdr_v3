<?php
/**
 * Список команды. При включённой группировке сотрудники раскладываются по
 * секторам (заголовок с якорем — на него ссылается схема оргструктуры), а
 * внутри сектора — по отделам и группам.
 *
 * @var array $data
 */
$title = $data['title'] ?? '';
$members = $data['members'] ?? [];
$groups = $data['groups'] ?? [];

/** Карточка сотрудника. */
$teamCard = static function (array $m): void {
    ?>
    <div class="team-card">
        <?php if (!empty($m['photo'])): ?>
            <?= \App\Core\Media::picture((string) $m['photo'], (string) ($m['name'] ?? ''), null, null, 'team-card__photo', true, '(max-width: 700px) 100vw, 25vw') ?>
        <?php endif; ?>
        <div class="team-card__name"><?= htmlspecialchars($m['name'] ?? '', ENT_QUOTES) ?></div>
        <?php if (!empty($m['position'])): ?>
            <div class="team-card__position"><?= htmlspecialchars($m['position'], ENT_QUOTES) ?></div>
        <?php endif; ?>
    </div>
    <?php
};
?>
<div class="block-team">
    <?php if ($title !== ''): ?><h2 class="block-team__title"><?= \App\Core\TitleMarkup::html($title) ?></h2><?php endif; ?>
    <?php if (empty($members)): ?>
        <p class="block-team__empty"><?= htmlspecialchars(t('Раздел команды пока пуст.'), ENT_QUOTES) ?></p>
    <?php elseif ($groups !== []): ?>
        <?php foreach ($groups as $group): ?>
            <section class="block-team__group"<?= $group['slug'] !== '' ? ' id="team-' . htmlspecialchars((string) $group['slug'], ENT_QUOTES) . '"' : '' ?>>
                <?php if ($group['name'] !== ''): ?>
                    <h3 class="block-team__group-title"><?= htmlspecialchars((string) $group['name'], ENT_QUOTES) ?></h3>
                <?php endif; ?>
                <?php if ($group['members'] !== []): ?>
                    <div class="block-team__grid">
                        <?php foreach ($group['members'] as $m) {
                            $teamCard($m);
                        } ?>
                    </div>
                <?php endif; ?>
                <?php foreach (($group['units'] ?? []) as $unit): ?>
                    <div class="block-team__unit">
                        <h4 class="block-team__unit-title"><?= htmlspecialchars((string) $unit['name'], ENT_QUOTES) ?></h4>
                        <div class="block-team__grid">
                            <?php foreach ($unit['members'] as $m) {
                                $teamCard($m);
                            } ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="block-team__grid">
            <?php foreach ($members as $m) {
                $teamCard($m);
            } ?>
        </div>
    <?php endif; ?>
</div>
