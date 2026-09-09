<?php
/** @var array $requirements */
/** @var array $permissions */
/** @var bool $allPassed */
$step = '1';
require __DIR__ . '/_header.php';
?>
<div class="install-header">
    <h1 class="install-header__title">Проверка системного окружения</h1>
    <p class="install-header__desc">Убедитесь, что ваш сервер полностью соответствует всем техническим требованиями для стабильной работы ASDR CMS.</p>
</div>

<div class="check-grid-2col">
    <div class="check-card-group">
        <div class="check-card-group__title">
            <?= \App\Core\Icon::render('server', 16, 'check-card-group__icon', 2.5) ?>
            Параметры PHP и модули
        </div>
        <?php foreach ($requirements as $check): ?>
            <div class="check-item">
                <div class="check-item__info">
                    <div class="check-item__icon <?= $check['ok'] ? 'ok' : 'fail' ?>">
                        <?= \App\Core\Icon::render($check['ok'] ? 'check' : 'x', 15, 'check-item__status-icon', 2.5) ?>
                    </div>
                    <div>
                        <div class="check-item__label"><?= htmlspecialchars($check['label'], ENT_QUOTES) ?></div>
                        <?php if (!$check['ok'] && !empty($check['hint'])): ?>
                            <div class="check-item__hint"><?= htmlspecialchars($check['hint'], ENT_QUOTES) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="badge badge--<?= $check['ok'] ? 'success' : 'danger' ?> u-inline-07bbf13a37">
                    <?= $check['ok'] ? 'Соответствует' : 'Ошибка' ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="check-card-group">
        <div class="check-card-group__title">
            <?= \App\Core\Icon::render('folder', 16, 'check-card-group__icon', 2.5) ?>
            Права доступа к директориям
        </div>
        <?php foreach ($permissions as $check): ?>
            <div class="check-item">
                <div class="check-item__info">
                    <div class="check-item__icon <?= $check['ok'] ? 'ok' : 'fail' ?>">
                        <?= \App\Core\Icon::render($check['ok'] ? 'check' : 'x', 15, 'check-item__status-icon', 2.5) ?>
                    </div>
                    <div>
                        <div class="check-item__label"><?= htmlspecialchars($check['label'], ENT_QUOTES) ?></div>
                        <?php if (!$check['ok'] && !empty($check['hint'])): ?>
                            <div class="check-item__hint"><?= htmlspecialchars($check['hint'], ENT_QUOTES) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="badge badge--<?= $check['ok'] ? 'success' : 'danger' ?> u-inline-07bbf13a37">
                    <?= $check['ok'] ? 'Запись разрешена' : 'Нет прав' ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="form-actions">
    <?php if ($allPassed): ?>
        <a href="/install/step2" class="btn btn--primary">
            Продолжить к настройке БД
            <?= \App\Core\Icon::render('chevron-right', 18, 'btn__icon', 2.5) ?>
        </a>
    <?php else: ?>
        <a href="/install" class="btn btn--secondary">
            <?= \App\Core\Icon::render('refresh', 16, 'btn__icon', 2.5) ?>
            Проверить снова
        </a>
        <span class="form-hint u-inline-30739594c7">Исправьте ошибки выше для продолжения.</span>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
