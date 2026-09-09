<?php
$step = '4';
/** @var string $email */
/** @var string $username */
/** @var bool $emailSent */
/** @var string $adminEntryUrl */
require __DIR__ . '/_header.php';
?>
<div class="u-inline-dc16df27e2">
    <div class="u-inline-c44cea58cf">
        <?= \App\Core\Icon::render('check', 40, 'install-success-icon', 3) ?>
    </div>

    <h1 class="install-header__title u-inline-e40283b01f">Установка успешно завершена!</h1>

    <?php if (!empty($emailSent)): ?>
        <p class="u-inline-59f69369f1">
            Ваша CMS полностью настроена и готова к работе. Уведомление с параметрами входа успешно отправлено на E-mail: <strong class="u-inline-13daa00158"><?= htmlspecialchars((string) ($email ?? ''), ENT_QUOTES) ?></strong>
        </p>
    <?php else: ?>
        <p class="u-inline-ff1c0e35f5">
            Ваша CMS полностью настроена и готова к работе! Ниже указаны параметры созданной учётной записи администратора.
        </p>
    <?php endif; ?>

    <div class="u-inline-c0715b171a">
        <div class="u-inline-e1652ceeff">
            Параметры входа в систему
        </div>
        <div class="u-inline-6e20fb40dc">
            <div><strong>Логин:</strong> <code><?= htmlspecialchars((string) ($username ?? 'admin'), ENT_QUOTES) ?></code></div>
            <div><strong>E-mail:</strong> <code><?= htmlspecialchars((string) ($email ?? ''), ENT_QUOTES) ?></code></div>
            <div><strong>Панель управления:</strong> <code><?= htmlspecialchars((string) ($adminEntryUrl ?? '/admin/login'), ENT_QUOTES) ?></code></div>
        </div>
        <?php if (empty($emailSent)): ?>
            <div class="u-inline-87ff6778ab">
                <?= \App\Core\Icon::render('bulb', 18, 'install-hint-icon') ?>
                <em>Поскольку SMTP-сервер еще не настроен, сообщение не отправлялось на почту. Сохраните адрес панели сейчас. Настроить отправку писем или подключить Telegram-бота можно после входа.</em>
            </div>
        <?php endif; ?>
    </div>

    <div class="u-inline-fdc4c2f742">
        <div class="u-inline-d850ac3a7e">
            <?= \App\Core\Icon::render('lock', 20, 'u-inline-6abc078b20') ?>
            <div class="u-inline-c6e9746c81">
                <strong class="u-inline-c0811a5932">Защита установщика и входа:</strong>
                Установщик заблокирован файлом <code>storage/installed.lock</code>. Прямой адрес <code>/admin/login</code> скрыт после создания защищённого адреса выше.
            </div>
        </div>
    </div>

    <a href="<?= htmlspecialchars((string) ($adminEntryUrl ?? '/admin/login'), ENT_QUOTES) ?>" class="btn btn--primary u-inline-8ca649dc3b">
        Войти в панель управления
        <?= \App\Core\Icon::render('arrow-right', 18, 'btn__icon', 2.5) ?>
    </a>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
