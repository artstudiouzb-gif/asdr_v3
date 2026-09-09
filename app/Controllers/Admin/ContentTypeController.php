<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Slug;
use App\Core\View;
use App\Models\ContentType;

/**
 * Конструктор типов контента и их полей (задачи 131/132) — супер-администратор.
 */
final class ContentTypeController
{
    public function index(): void
    {
        Auth::requireSuperAdmin();
        View::render('admin/content_types/index', ['items' => ContentType::all()]);
    }

    public function store(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $name = trim((string) ($_POST['name'] ?? ''));
        $slug = Slug::make((string) ($_POST['slug'] ?? '') ?: $name);
        $hasTr = !empty($_POST['has_translations']);
        $description = trim((string) ($_POST['description'] ?? ''));
        $isPublic = !empty($_POST['is_public']);
        $rootUrl = !empty($_POST['root_url']);
        // Адрес в корне сайта проверяем до сохранения: маршрут `/{slug}` сначала
        // ищет страницу, поэтому занятый слаг просто не открылся бы, а форма
        // показывала бы сохранённую настройку.
        $rootConflict = $rootUrl ? ContentType::rootUrlConflict($slug) : '';

        if ($name === '' || $slug === '') {
            Flash::error('Укажите название типа.');
        } elseif (ContentType::slugExists($slug)) {
            Flash::error('Тип с таким адресом уже существует.');
        } elseif ($rootConflict !== '') {
            Flash::error($rootConflict);
        } else {
            $id = ContentType::create($slug, $name, $hasTr, $description, $isPublic, (string) ($_POST['icon'] ?? ''), $rootUrl);
            Flash::success('Тип контента создан. Добавьте поля.');
            header('Location: /admin/content-types/' . $id . '/fields');
            exit;
        }
        header('Location: /admin/content-types');
        exit;
    }

    /** @param array<string, string> $params */
    public function fields(array $params): void
    {
        Auth::requireSuperAdmin();
        $type = ContentType::findById((int) $params['id']);
        if (!$type) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }
        View::render('admin/content_types/fields', [
            'type' => $type,
            'fields' => ContentType::fields((int) $type['id']),
            'allTypes' => ContentType::all(),
        ]);
    }

    /** @param array<string, string> $params */
    public function saveFields(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $type = ContentType::findById((int) $params['id']);
        if (!$type) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        // Столкновение адреса — причина отказать в переносе в корень, а не
        // повод отменить сохранение полей: поля к адресу отношения не имеют.
        $rootUrl = !empty($_POST['root_url']);
        $rootConflict = $rootUrl ? ContentType::rootUrlConflict((string) $type['slug']) : '';
        if ($rootConflict !== '') {
            $rootUrl = false;
            Flash::error($rootConflict . ' Раздел остался на прежнем адресе.');
        }

        ContentType::update(
            (int) $type['id'],
            trim((string) ($_POST['name'] ?? $type['name'])),
            !empty($_POST['has_translations']),
            trim((string) ($_POST['description'] ?? ($type['description'] ?? ''))),
            !empty($_POST['is_public']),
            (string) ($_POST['icon'] ?? ($type['icon'] ?? '')),
            $rootUrl
        );

        // Адрес раздела изменился — переписываем пункты меню, которые на него
        // вели. Старый адрес остаётся рабочим (редирект), но пункт на нём
        // перестал бы подсвечиваться: посетитель приходит уже на новый.
        if ($rootUrl !== !empty($type['root_url'])) {
            $slug = (string) $type['slug'];
            $moved = $rootUrl
                ? \App\Models\MenuItem::retargetCustomUrl('catalog/' . $slug, $slug)
                : \App\Models\MenuItem::retargetCustomUrl($slug, 'catalog/' . $slug);
            if ($moved > 0) {
                Flash::success('Адрес раздела изменён, пунктов меню обновлено: ' . $moved . '.');
            }
        }

        $fields = [];
        foreach ((array) ($_POST['fields'] ?? []) as $f) {
            $fname = preg_replace('/[^a-z0-9_]/i', '', trim((string) ($f['name'] ?? ''))) ?? '';
            $label = trim((string) ($f['label'] ?? ''));
            if ($fname === '' || $label === '') {
                continue;
            }
            $options = [];
            if (($f['field_type'] ?? '') === 'relation' && !empty($f['relation_type'])) {
                $options['relation_type'] = preg_replace('/[^a-z0-9_-]/i', '', (string) $f['relation_type']) ?? '';
            }
            $fields[] = [
                'name' => $fname,
                'label' => $label,
                'field_type' => (string) ($f['field_type'] ?? 'text'),
                'required' => !empty($f['required']),
                'options' => $options,
            ];
        }

        ContentType::replaceFields((int) $type['id'], $fields);
        Flash::success('Поля типа сохранены.');
        header('Location: /admin/content-types/' . (int) $type['id'] . '/fields');
        exit;
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        ContentType::delete((int) $params['id']);
        Flash::success('Тип контента и все его записи удалены.');
        header('Location: /admin/content-types');
        exit;
    }
}
