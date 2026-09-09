<?php

declare(strict_types=1);

/** @var string $username */
/** @var string $role */
/** @var string $editUrl */
/** @var string $editLabel */
/** @var string $csrfToken */

$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES);
?>
<aside
    class="app-admin-bar"
    id="app-admin-bar"
    aria-label="Панель администратора"
    lang="ru"
>
    <nav class="app-admin-bar__left" aria-label="Управление содержимым">
        <a href="/admin" class="app-admin-bar__brand">
            <?= \App\Core\Icon::render('bolt', 13, 'ui-icon', 2.2) ?>
            ASDR CMS
        </a>

        <a
            class="app-admin-bar__btn app-admin-bar__btn--edit"
            href="<?= $e($editUrl) ?>"
        >
            <?= \App\Core\Icon::render('edit', 13, 'ui-icon', 2.2) ?>
            <?= $e($editLabel) ?>
        </a>

        <div class="app-admin-bar__drop">
            <button type="button" class="app-admin-bar__btn">
                <?= \App\Core\Icon::render('plus', 12, 'ui-icon', 2.5) ?>
                Создать ▾
            </button>

            <div class="app-admin-bar__drop-menu">
                <a href="/admin/pages/create" class="app-admin-bar__drop-item">Страницу</a>
                <a href="/admin/news/create" class="app-admin-bar__drop-item">Новость</a>
                <a href="/admin/projects/create" class="app-admin-bar__drop-item">Проект</a>
            </div>
        </div>

        <form
            class="u-inline-1654353117"
            method="post"
            action="/admin/performance/clear-cache"
        >
            <input
                type="hidden"
                name="csrf_token"
                value="<?= $e($csrfToken) ?>"
            >
            <button
                type="submit"
                class="app-admin-bar__btn"
                title="Сбросить кеш сайта"
            >
                <?= \App\Core\Icon::render('refresh', 13, 'ui-icon', 2.2) ?>
                Сбросить кеш
            </button>
        </form>
    </nav>

    <div class="app-admin-bar__right">
        <span class="u-inline-a223f6dc36">
            <?= \App\Core\Icon::render('user', 13, 'ui-icon', 2.2) ?>
            <?= $e($username) ?> (<?= $e($role) ?>)
        </span>
        <a href="/admin/profile" class="app-admin-bar__btn">Профиль</a>
        <form class="u-inline-1654353117" method="post" action="/admin/logout">
            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
            <button type="submit" class="app-admin-bar__btn">Выйти</button>
        </form>
    </div>
</aside>
