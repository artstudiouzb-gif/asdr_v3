<?php

declare(strict_types=1);

use App\Core\BlockTypeRegistry;
use App\Core\FormBuilder;
use App\Core\FormLayout;

test('Форма: ширина поля — настройка, а не следствие типа', function (): void {
    // Прежде ширину вычислял публичный шаблон: многострочный текст, файл и
    // чекбоксы занимали ряд целиком, остальное — половину, и поставить «Имя»
    // рядом с «Фамилией» было нечем. Умолчание осталось прежним, поэтому у
    // полей, сохранённых до появления настройки, вид не меняется.
    assert_same('full', FormLayout::widthOf(['type' => 'textarea']));
    assert_same('full', FormLayout::widthOf(['type' => 'checkbox']));
    assert_same('auto', FormLayout::widthOf(['type' => 'text']));

    // Выбор редактора главнее умолчания типа.
    assert_same('half', FormLayout::widthOf(['type' => 'textarea', 'width' => 'half']));
    assert_same('full', FormLayout::widthOf(['type' => 'text', 'width' => 'full']));

    // Значение вне набора заменяется умолчанием, а не ближайшим допустимым:
    // в списке его нет, значит форма подделана.
    assert_same('auto', FormLayout::widthOf(['type' => 'text', 'width' => '37%']));
    assert_same('auto', FormLayout::normalizeWidth('<script>'));
    assert_same('text', FormLayout::normalizeType('script'));
});

test('Форма: сетка читает и прежний ключ layout', function (): void {
    // Настройка переименована (1col/2col → число колонок), но данные блока
    // приезжают ещё и из файла шаблона страницы: старый ключ обязан давать
    // прежнюю раскладку, иначе вид собранных страниц поменялся бы молча.
    assert_same(1, FormLayout::columns([]));
    assert_same(1, FormLayout::columns(['layout' => '1col']));
    assert_same(2, FormLayout::columns(['layout' => '2col']));
    assert_same(3, FormLayout::columns(['columns' => 3]));
    assert_same(2, FormLayout::columns(['columns' => 2, 'layout' => '1col']));
    assert_same(1, FormLayout::columns(['columns' => 9]));

    assert_same('block-form__form block-form__form--cols-2', FormLayout::formClass(['layout' => '2col']));
    assert_contains('block-form__field--w-half', FormLayout::fieldClass(['type' => 'text', 'width' => 'half']));

    // Ключ настройки объявлен и в реестре типов: иначе первое же сохранение
    // блока в панели потеряло бы её.
    assert_true(array_key_exists('columns', BlockTypeRegistry::defaults()['form']));
});

test('Форма: каждая ширина что-то меняет на выводе', function (): void {
    // Настройка без правила в CSS — мёртвая: список предлагает выбор,
    // которого не существует (тот же дефект, что был у «Масштаба типографики»).
    $css = (string) file_get_contents(__DIR__ . '/../../public/assets/css/frontend.css');
    foreach (array_keys(FormLayout::WIDTHS) as $width) {
        $class = FormLayout::widthClass($width, '.block-form__field');
        if ($width === 'auto') {
            // «Как в сетке» действует через класс формы, а не через свой span.
            assert_contains('.block-form__form--cols-2 ' . $class, $css);
            assert_contains('.block-form__form--cols-3 ' . $class, $css);
            continue;
        }
        assert_contains($class . ' {', $css);
    }

    // Одна колонка — базовое состояние сетки (поле занимает ряд целиком),
    // своего правила ей не нужно; остальные обязаны его иметь.
    foreach (array_keys(FormLayout::COLUMNS) as $columns) {
        if ($columns === 1) {
            continue;
        }
        assert_contains('.block-form__form--cols-' . $columns . ' ', $css);
    }

    // Та же шкала рисует карточки конструктора: редактор видит раскладку.
    $adminCss = (string) file_get_contents(__DIR__ . '/../../public/assets/css/admin.css');
    foreach (array_keys(FormLayout::WIDTHS) as $width) {
        assert_contains(FormLayout::widthClass($width, '.formb-cell'), $adminCss);
    }
});

test('Форма: набор типов объявлен один раз', function (): void {
    // Список типов лежал в четырёх местах — в форме редактора, в её шаблоне
    // репитера, в белом списке контроллера и ветками в публичном шаблоне.
    // Тип, добавленный в один список и забытый в другом, сохранялся бы как
    // «Текст», ничего об этом не сообщая.
    $controller = (string) file_get_contents(__DIR__ . '/../../app/Controllers/Admin/FormController.php');
    assert_contains('FormLayout::normalizeType', $controller);
    assert_contains('FormLayout::OPTION_TYPES', $controller);
    assert_contains('FormLayout::normalizeWidth', $controller);
    foreach (['checkbox_group', 'textarea', 'radio'] as $type) {
        assert_true(
            !str_contains($controller, "'" . $type . "'"),
            'Контроллер форм не должен вести свой список типов: ' . $type
        );
    }

    $template = (string) file_get_contents(__DIR__ . '/../../templates/blocks/form.php');
    assert_contains('FormLayout::fieldClass', $template);
    assert_contains('FormLayout::formClass', $template);
});

test('Форма: у конструктора есть порядок полей и ширина', function (): void {
    // Порядок полей менялся только перенабором: кнопок перестановки у
    // репитера форм не было вовсе, хотя сервер читает fields[] в порядке
    // присланных пар — то есть порядок карточек и есть порядок полей.
    $card = FormBuilder::fieldCard([
        'name' => 'fio',
        'label' => 'Фамилия и имя',
        'type' => 'text',
        'width' => 'half',
        'required' => true,
    ], '2');

    assert_contains('data-repeater-move="up"', $card);
    assert_contains('data-repeater-move="down"', $card);
    assert_contains('name="fields[2][width]"', $card);
    assert_contains('<option value="half" selected>', $card);
    assert_contains('value="Фамилия и имя"', $card);
    assert_contains('id="formb-req-2" checked', $card);
    assert_contains('repeater-row formb-cell formb-cell--w-half', FormBuilder::cellClass('half'));

    // Варианты выбора спрашиваются только у списков, и знает об этом сам тип:
    // признак едет атрибутом из PHP, где набор типов и объявлен.
    assert_contains('formb-card__full is-hidden" data-field-options-container', $card);
    $select = FormBuilder::fieldCard(['label' => 'Район', 'type' => 'select'], '0');
    assert_contains('formb-card__full" data-field-options-container', $select);
    assert_contains('<option value="select" data-has-options="1" selected>', $select);

    // Пустая карточка — это шаблон для новых полей: та же разметка, иначе
    // новое поле приходило бы без части настроек.
    $blank = FormBuilder::fieldCard([], '__INDEX__');
    assert_contains('name="fields[__INDEX__][type]"', $blank);
    assert_contains('name="fields[__INDEX__][condition_field]"', $blank);
    assert_contains('Новое поле', $blank);

    // Карточку можно перетащить, и раскладку задаёт та же шкала, что на сайте.
    $view = (string) file_get_contents(__DIR__ . '/../../app/Views/admin/forms/form.php');
    assert_contains('draggable="true"', $view);
    assert_contains('FormBuilder::fieldCard', $view);

    $js = (string) file_get_contents(__DIR__ . '/../../public/assets/js/admin.js');
    assert_contains('data-form-builder', $js);
    assert_contains('formb-cell--w-', $js);
});
