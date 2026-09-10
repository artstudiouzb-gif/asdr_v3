<?php

use App\Core\AdminUi;
use App\Core\SystemHealth;

$pageTitle = 'Состояние системы';
$activeNav = 'health';
require __DIR__ . '/../layout/header.php';

/** @var list<array{title:string, checks:list<array{id:string,title:string,state:string,value:string,hint:string,at:?int}>}> $groups */
/** @var string $worst */

$stateLabel = [
    SystemHealth::OK => 'в порядке',
    SystemHealth::WARN => 'требует внимания',
    SystemHealth::FAIL => 'не работает',
    SystemHealth::UNKNOWN => 'неизвестно',
];
$stateBadge = [
    SystemHealth::OK => 'badge--published',
    SystemHealth::WARN => 'badge--draft',
    SystemHealth::FAIL => 'badge--danger',
    SystemHealth::UNKNOWN => 'badge--draft',
];
$stateIcon = [
    SystemHealth::OK => 'check',
    SystemHealth::WARN => 'warning',
    SystemHealth::FAIL => 'warning',
    SystemHealth::UNKNOWN => 'info',
];

$summary = [
    SystemHealth::OK => 'Проблем не видно.',
    SystemHealth::WARN => 'Есть, на что посмотреть.',
    SystemHealth::FAIL => 'Что-то не работает.',
];
?>
<p class="form-hint admin-section-intro">
    Раздел ничего не проверяет заново — он показывает то, что уже посчитали сторож, отметки воркеров,
    сверка целостности, репетиция восстановления и память интеграций. Раньше эти ответы жили в журналах
    и cron, то есть не доходили ни до кого.
</p>

<div class="health-summary health-summary--<?= htmlspecialchars($worst, ENT_QUOTES) ?>">
    <?= AdminUi::icon($stateIcon[$worst] ?? 'info', 22) ?>
    <span><?= htmlspecialchars($summary[$worst] ?? 'Состояние неизвестно.', ENT_QUOTES) ?></span>
</div>

<?php foreach ($groups as $group): ?>
    <section class="form-card">
        <?= AdminUi::cardHeader($group['title'], 'activity') ?>
        <div class="health-list">
            <?php foreach ($group['checks'] as $check): ?>
                <?php
                $checkId = 'health-' . preg_replace('/[^a-z0-9_-]+/i', '-', $check['id']);
                $solution = SystemHealth::solution($check['id']);
                ?>
                <div class="health-row health-row--<?= htmlspecialchars($check['state'], ENT_QUOTES) ?>"
                     id="<?= htmlspecialchars($checkId, ENT_QUOTES) ?>">
                    <div class="health-row__head">
                        <span class="health-row__title"><?= htmlspecialchars($check['title'], ENT_QUOTES) ?></span>
                        <span class="badge <?= htmlspecialchars($stateBadge[$check['state']] ?? 'badge--draft', ENT_QUOTES) ?>">
                            <?= AdminUi::icon($stateIcon[$check['state']] ?? 'info', 12) ?>
                            <?= htmlspecialchars($stateLabel[$check['state']] ?? '', ENT_QUOTES) ?>
                        </span>
                    </div>
                    <div class="health-row__value"><?= htmlspecialchars($check['value'], ENT_QUOTES) ?></div>
                    <?php if ($check['hint'] !== ''): ?>
                        <div class="health-row__hint"><?= htmlspecialchars($check['hint'], ENT_QUOTES) ?></div>
                    <?php endif; ?>
                    <?php if ($check['state'] !== SystemHealth::OK): ?>
                        <div class="health-row__solution">
                            <span class="health-row__solution-icon" aria-hidden="true"><?= AdminUi::icon('tool', 16) ?></span>
                            <div class="health-row__solution-body">
                                <strong>Как исправить</strong>
                                <span><?= htmlspecialchars($solution['instruction'], ENT_QUOTES) ?></span>
                            </div>
                            <?php if ($solution['href'] !== ''): ?>
                                <a class="btn btn--small" href="<?= htmlspecialchars($solution['href'], ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($solution['label'], ENT_QUOTES) ?> <?= AdminUi::icon('arrow-right', 14) ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
