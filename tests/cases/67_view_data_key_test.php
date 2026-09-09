<?php

declare(strict_types=1);

use App\Core\View;

// Регрессия: View::render/renderPartial должны прокидывать во вьюху ключ с
// именем 'data'. Раньше extract() вызывался в области, где параметр назывался
// $data, и при EXTR_SKIP переменная $data во вьюху не попадала — из-за этого
// редакторы блоков (block_form использует $data['items'] и т.п.) не показывали
// сохранённое содержимое.

test("View::renderPartial отдаёт во вьюху переменную \$data", function () {
    $fixture = APP_ROOT . '/app/Views/_selftest_data_key.php';
    file_put_contents(
        $fixture,
        '<?php echo "items:" . count($data["items"] ?? []) . "|title:" . ($data["title"] ?? "") . "|block:" . ($block["id"] ?? "?");'
    );
    try {
        $html = View::renderPartial('_selftest_data_key', [
            'block' => ['id' => 7],
            'data' => ['items' => [1, 2, 3], 'title' => 'Адрес'],
        ]);
    } finally {
        @unlink($fixture);
    }

    assert_same('items:3|title:Адрес|block:7', $html, 'ключ data и соседние ключи доходят до вьюхи');
});
