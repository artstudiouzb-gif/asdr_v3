<?php

declare(strict_types=1);

use App\Core\AdminUi;

test('Все разделы боковой навигации админки имеют существующую Tabler-иконку', function (): void {
    $sections = [
        'dashboard',
        'news',
        'pages',
        'projects',
        'team',
        'albums',
        'videos',
        'forms',
        'files',
        'trash',
        'design',
        'menu',
        'widgets',
        'header',
        'footer',
        'languages',
        'content_types',
        'telegram',
        'social',
        'webhooks',
        'redirects',
        'performance',
        'security',
        'settings',
        'users',
        'audit',
        'subscribers',
        'repository',
        'profile',
    ];

    foreach ($sections as $section) {
        $icon = AdminUi::navigationIcon($section);
        assert_true($icon !== '', 'Нет Tabler-иконки для раздела: ' . $section);
        assert_contains('#tabler-', $icon);
    }
});
