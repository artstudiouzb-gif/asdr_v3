<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\FormDef;
use App\Models\FormTranslation;
use App\Models\Language;

test('Форма: перевод вариантов меняет подпись, а значение остаётся основным', function (): void {
    // Условие показа и журнал заявок сравнивают значение со строкой основного
    // языка. Переведи значение — и узбекская заявка молча не включала бы
    // зависимое поле.
    $field = ['type' => 'select', 'options' => 'Да, , Нет, Не знаю', 'option_labels' => ['Ha', '', 'Yoʻq']];
    assert_same([
        ['value' => 'Да', 'label' => 'Ha'],
        // Пустое место в списке не сдвигает переводы остальных вариантов.
        ['value' => 'Нет', 'label' => 'Yoʻq'],
        // Недописанный перевод оставляет текст основного языка, а не дыру.
        ['value' => 'Не знаю', 'label' => 'Не знаю'],
    ], FormDef::choices($field));

    assert_same([], FormDef::choices(['type' => 'select', 'options' => '']));
});

test('Форма: перевод накладывается по имени поля и не трогает журнал (БД)', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();
    $default = Language::defaultCode();
    $other = $default === 'uz' ? 'ru' : 'uz';

    $id = FormDef::create([
        'name' => 'Обращение',
        'slug' => 'probe-form-tr-' . bin2hex(random_bytes(3)),
        'fields' => [
            ['name' => 'fio', 'label' => 'ФИО', 'type' => 'text', 'width' => 'auto', 'required' => true],
            ['name' => 'topic', 'label' => 'Тема', 'type' => 'radio', 'width' => 'auto', 'required' => false, 'options' => 'Жалоба, Предложение'],
        ],
        'notify_email' => null,
        'success_message' => 'Спасибо!',
    ]);
    FormTranslation::upsert($id, $other, [
        'name' => 'Murojaat',
        'success_message' => '',
        'fields' => ['topic' => ['label' => 'Mavzu', 'options' => 'Shikoyat, Taklif'], 'gone' => ['label' => 'X']],
    ]);

    $base = FormDef::findById($id);
    assert_true($base !== null);
    // Основной язык — ровно запись из базы.
    assert_same($base, FormDef::localize($base, $default));

    $form = FormDef::localize($base, $other);
    assert_same('Murojaat', $form['name']);
    // Пустой перевод сообщения берёт основной текст.
    assert_same('Спасибо!', $form['success_message']);
    assert_same('ФИО', $form['fields'][0]['label']);
    assert_same('Mavzu', $form['fields'][1]['label']);
    // Имя, тип и значения вариантов общие для всех языков.
    assert_same('topic', $form['fields'][1]['name']);
    assert_same('Жалоба, Предложение', $form['fields'][1]['options']);

    $before = \App\Core\Locale::current();
    \App\Core\Locale::set($other);
    try {
        $html = render_view('templates/blocks/form.php', ['data' => ['form' => $form], 'blockId' => 7]);
    } finally {
        \App\Core\Locale::set($before);
    }
    assert_contains('<h2>Murojaat</h2>', $html);
    assert_contains('value="Жалоба"', $html);
    assert_contains('Shikoyat', $html);
    // Обработчик узнаёт язык посетителя по адресу отправки.
    assert_contains('action="' . \App\Core\Locale::url('forms/' . $form['slug'] . '/submit', $other) . '"', $html);

    $pdo->prepare('DELETE FROM forms WHERE id = ?')->execute([$id]);
    assert_same([], FormTranslation::forForm($id));
});
