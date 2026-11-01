<?php

use App\Core\Csrf;

/** @var bool $tokenReady */
/** @var string|null $error */
$step = '1';
require __DIR__ . '/_header.php';
?>
<div class="install-header">
    <h1 class="install-header__title">Подтвердите доступ к серверу</h1>
    <p class="install-header__desc">Установщик открыт по адресу сайта для всех, поэтому дальше он пускает только того, у кого есть доступ к файлам хостинга.</p>
</div>

<?php if ($tokenReady): ?>
    <div class="install-callout">
        <?= \App\Core\Icon::render('key', 20, 'install-callout__icon') ?>
        <div>
            Откройте файловым менеджером хостинга или по FTP файл <code>storage/install.token</code> в папке сайта, скопируйте строку из него и вставьте ниже. После установки файл удалится сам.
        </div>
    </div>
<?php else: ?>
    <div class="alert alert--error">
        Не удалось создать файл <code>storage/install.token</code>: у PHP нет прав на запись в папку <code>storage</code>. Разрешите запись (права 755 или 775) и обновите страницу.
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert--error" role="alert">
        <?= htmlspecialchars($error, ENT_QUOTES) ?>
    </div>
<?php endif; ?>

<?php if ($tokenReady): ?>
<form method="post" action="/install" class="form-grid">
    <?= Csrf::field() ?>
    <div class="form-field">
        <label for="install_token">Токен установки</label>
        <div class="install-input-wrap">
            <?= \App\Core\Icon::render('lock', 18, 'install-input-icon') ?>
            <input type="text" id="install_token" name="install_token" required autocomplete="off" spellcheck="false" inputmode="latin" maxlength="64" placeholder="32 символа из файла">
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn--primary">
            Открыть установку
            <?= \App\Core\Icon::render('chevron-right', 18, 'btn__icon', 2.5) ?>
        </button>
    </div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
