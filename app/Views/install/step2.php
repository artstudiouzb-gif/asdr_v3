<?php

use App\Core\Csrf;

/** @var string|null $error */
/** @var array $data */
$step = '2';
require __DIR__ . '/_header.php';
?>
<div class="install-header">
    <h1 class="install-header__title">Подключение к базе данных</h1>
    <p class="install-header__desc">Укажите параметры подключения к вашей СУБД MySQL / MariaDB.</p>
</div>

<div class="install-callout">
    <?= \App\Core\Icon::render('info-circle', 20, 'install-callout__icon') ?>
    <div>
        Установщик автоматически проверит подключение и создаст структуру таблиц. Если база данных ещё не создана на сервере, мастер попробует создать её самостоятельно.
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert--error u-inline-d7a4b27f07">
        <?= htmlspecialchars($error, ENT_QUOTES) ?>
    </div>
<?php endif; ?>

<form method="post" action="/install/step2" class="form-grid">
    <?= Csrf::field() ?>

    <div class="form-grid-2col u-inline-a0f0a7d9e5">
        <div class="form-field">
            <label class="u-inline-aa1820469b" for="db_host">Сервер MySQL (Host) <span class="u-inline-9dd1207e58">*</span></label>
            <div class="install-input-wrap">
                <?= \App\Core\Icon::render('server', 18, 'install-input-icon') ?>
                <input type="text" id="db_host" name="db_host" value="<?= htmlspecialchars($data['host'] ?? 'localhost', ENT_QUOTES) ?>" required placeholder="localhost или 127.0.0.1">
            </div>
        </div>
        <div class="form-field">
            <label class="u-inline-aa1820469b" for="db_port">Порт <span class="u-inline-9dd1207e58">*</span></label>
            <div class="install-input-wrap">
                <?= \App\Core\Icon::render('hash', 18, 'install-input-icon') ?>
                <input type="text" id="db_port" name="db_port" value="<?= htmlspecialchars($data['port'] ?? '3306', ENT_QUOTES) ?>" required placeholder="3306">
            </div>
        </div>
    </div>

    <div class="form-field u-inline-9eb125f52f">
        <label class="u-inline-aa1820469b" for="db_name">Имя базы данных <span class="u-inline-9dd1207e58">*</span></label>
        <div class="install-input-wrap">
            <?= \App\Core\Icon::render('database', 18, 'install-input-icon') ?>
            <input type="text" id="db_name" name="db_name" value="<?= htmlspecialchars($data['database'] ?? 'asdr_cms', ENT_QUOTES) ?>" required placeholder="asdr_cms">
        </div>
    </div>

    <div class="form-grid-2col u-inline-e6e3fab9a5">
        <div class="form-field">
            <label class="u-inline-aa1820469b" for="db_user">Пользователь БД <span class="u-inline-9dd1207e58">*</span></label>
            <div class="install-input-wrap">
                <?= \App\Core\Icon::render('user', 18, 'install-input-icon') ?>
                <input type="text" id="db_user" name="db_user" value="<?= htmlspecialchars($data['username'] ?? '', ENT_QUOTES) ?>" required placeholder="root или db_user">
            </div>
        </div>
        <div class="form-field">
            <label class="u-inline-aa1820469b" for="db_pass">Пароль пользователя БД</label>
            <div class="install-input-wrap">
                <?= \App\Core\Icon::render('lock', 18, 'install-input-icon') ?>
                <input class="u-inline-614afdfeb3" type="password" id="db_pass" name="db_pass" value="" placeholder="Пароль базы данных">
                <button type="button" id="toggle_db_pass" class="auth-eye-btn u-inline-761ba8828b" title="Показать пароль" aria-label="Показать пароль">
                    <?= \App\Core\Icon::render('eye', 18, 'auth-eye-icon auth-eye-icon--show') ?>
                    <?= \App\Core\Icon::render('eye-off', 18, 'auth-eye-icon auth-eye-icon--hide u-inline-c8be1ccba6') ?>
                </button>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn--primary">
            Проверить подключение и продолжить
            <?= \App\Core\Icon::render('chevron-right', 18, 'btn__icon', 2.5) ?>
        </button>
    </div>
</form>
<?php require __DIR__ . '/_footer.php'; ?>
