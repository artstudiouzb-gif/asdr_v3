<?php

declare(strict_types=1);

test('Сохранение главной страницы сохраняет статус is_home=1 и status=published', function (): void {
    $source = (string) file_get_contents(
        dirname(__DIR__, 2) . '/app/Controllers/Admin/PageController.php'
    );
    assert_contains(
        "if (\$existing !== null && (!empty(\$existing['is_home'])",
        $source,
        'контроллер учитывает текущий статус главной'
    );
    assert_contains(
        "\$status = 'published';",
        $source,
        'главную нельзя перевести в черновик'
    );
    assert_contains("'is_home' => \$isHome", $source);
});
