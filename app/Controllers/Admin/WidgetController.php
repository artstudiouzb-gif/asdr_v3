<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;
use App\Models\Language;
use App\Models\Widget;

final class WidgetController
{
    public function index(): void
    {
        Auth::requireSuperAdmin();
        View::render('admin/widgets/index', [
            'left' => Widget::forSidebar('left'),
            'right' => Widget::forSidebar('right'),
            'languages' => Language::active(),
        ]);
    }

    public function create(): void
    {
        Auth::requireSuperAdmin();
        View::render('admin/widgets/form', [
            'widget' => null,
            'data' => [],
            'languages' => Language::active(),
            'error' => null,
        ]);
    }

    public function store(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        [$data, $error] = $this->collectInput();
        if ($error !== null) {
            View::render('admin/widgets/form', [
                'widget' => $_POST,
                'data' => $data['data'] ?? [],
                'languages' => Language::active(),
                'error' => $error,
            ]);
            return;
        }

        Widget::create($data);
        Cache::flush();
        Flash::success('Виджет добавлен.');
        header('Location: /admin/widgets');
        exit;
    }

    /** @param array<string, string> $params */
    public function edit(array $params): void
    {
        Auth::requireSuperAdmin();

        $widget = Widget::findById((int) $params['id']);
        if (!$widget) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        View::render('admin/widgets/form', [
            'widget' => $widget,
            'data' => json_decode((string) $widget['data'], true) ?: [],
            'languages' => Language::active(),
            'error' => null,
        ]);
    }

    /** @param array<string, string> $params */
    public function update(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $widget = Widget::findById((int) $params['id']);
        if (!$widget) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        [$data, $error] = $this->collectInput((string) $widget['type']);
        if ($error !== null) {
            View::render('admin/widgets/form', [
                'widget' => array_merge($widget, $_POST),
                'data' => $data['data'] ?? [],
                'languages' => Language::active(),
                'error' => $error,
            ]);
            return;
        }

        Widget::update((int) $widget['id'], $data);
        Cache::flush();
        Flash::success('Виджет обновлён.');
        header('Location: /admin/widgets');
        exit;
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        Widget::delete((int) $params['id']);
        Cache::flush();
        Flash::success('Виджет удалён.');
        header('Location: /admin/widgets');
        exit;
    }

    /** @param array<string, string> $params */
    public function move(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $direction = ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down';
        Widget::move((int) $params['id'], $direction);
        Cache::flush();
        header('Location: /admin/widgets');
        exit;
    }

    /**
     * @return array{0: array, 1: string|null}
     */
    private function collectInput(?string $fixedType = null): array
    {
        $type = $fixedType ?? (string) ($_POST['type'] ?? '');
        if (!in_array($type, Widget::TYPES, true)) {
            return [[], 'Неизвестный тип виджета.'];
        }

        $sidebar = ($_POST['sidebar'] ?? 'left') === 'right' ? 'right' : 'left';
        $lang = (string) ($_POST['lang'] ?? '');
        if ($lang !== '' && !Language::isActive($lang)) {
            $lang = '';
        }
        $title = trim((string) ($_POST['title'] ?? ''));

        $data = $this->collectData($type);

        return [[
            'sidebar' => $sidebar,
            'type' => $type,
            'title' => $title !== '' ? $title : null,
            'lang' => $lang,
            'data' => $data,
            'is_active' => !empty($_POST['is_active']),
        ], null];
    }

    private function collectData(string $type): array
    {
        $data = match ($type) {
            'latest_news' => [
                'count' => max(1, min(20, (int) ($_POST['count'] ?? 5))),
                'show_thumb' => !empty($_POST['show_thumb']),
            ],
            'projects_list', 'team_list' => [
                'count' => max(1, min(20, (int) ($_POST['count'] ?? 5))),
            ],
            'contacts' => [
                'show_socials' => !empty($_POST['show_socials']),
            ],
            'custom_html' => [
                'html' => (string) ($_POST['html'] ?? ''),
            ],
            'subscribe' => [
                'text' => trim((string) ($_POST['text'] ?? '')),
            ],
            'photo_slider' => [
                'source' => isset(\App\Core\WidgetRenderer::SLIDER_SOURCES[(string) ($_POST['source'] ?? '')])
                    ? (string) $_POST['source']
                    : 'manual',
                'slides' => $this->collectSlides(),
                'shuffle' => !empty($_POST['shuffle']),
                'autoplay' => max(0, min(30, (int) ($_POST['autoplay'] ?? 0))),
                'ratio' => \App\Core\SliderRatio::normalize($_POST['ratio'] ?? null),
            ],
            default => [],
        };

        // Языковые переводы заголовка виджета (по коду языка)
        $titles = [];
        foreach ((array) ($_POST['titles'] ?? []) as $code => $val) {
            $code = preg_replace('/[^a-z_]/', '', strtolower((string) $code));
            $val = trim((string) $val);
            if ($code !== '' && $val !== '') {
                $titles[$code] = $val;
            }
        }
        if ($titles !== []) {
            $data['titles'] = $titles;
        }

        // Панель оформления (общая для всех типов); нормализация в рендерере.
        $data['_design'] = \App\Core\WidgetRenderer::normalizeDesign(['_design' => [
            'style' => (string) ($_POST['design_style'] ?? 'default'),
            'pad' => (string) ($_POST['design_pad'] ?? 'normal'),
            'accent' => !empty($_POST['design_accent']),
        ]]);

        return $data;
    }

    /**
     * Фотографии карусели из репитера. Пустая строка адреса — это удалённая
     * строка формы, а не картинка: такие пропускаем, иначе в карусели окажется
     * пустой кадр. Опасный адрес чистит UrlGuard на выводе, но отсеять его
     * лучше здесь — тогда он и в базу не попадёт.
     *
     * @return array<int, array{image: string, alt: string}>
     */
    private function collectSlides(): array
    {
        $images = [];
        foreach ((array) ($_POST['slides'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $src = trim((string) ($row['image'] ?? ''));
            if ($src === '' || !\App\Core\UrlGuard::isSafeMedia($src)) {
                continue;
            }
            $images[] = [
                'image' => $src,
                'alt' => mb_substr(trim((string) ($row['alt'] ?? '')), 0, 200),
            ];
        }

        return $images;
    }
}
