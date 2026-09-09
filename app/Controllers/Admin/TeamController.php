<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\ImageField;
use App\Core\View;
use App\Models\Language;
use App\Models\TeamMember;
use App\Models\TeamMemberTranslation;

final class TeamController
{
    public function index(): void
    {
        Auth::requireLogin();
        View::render('admin/team/index', ['items' => TeamMember::all()]);
    }

    public function create(): void
    {
        Auth::requireLogin();
        View::render('admin/team/form', [
            'member' => null,
            'translations' => [],
            'departments' => TeamMember::departments(),
            'error' => null,
        ]);
    }

    public function store(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        [$data, $error] = $this->collectInput(null);
        $translations = $this->collectTranslations();

        if ($error !== null) {
            View::render('admin/team/form', [
                'member' => $data,
                'translations' => $translations,
                'departments' => TeamMember::departments(),
                'error' => $error,
            ]);
            return;
        }

        $id = TeamMember::create($data);
        $this->saveTranslations($id, $translations);
        Flash::success('Сотрудник добавлен.');
        header('Location: /admin/team');
        exit;
    }

    /** @param array<string, string> $params */
    public function edit(array $params): void
    {
        Auth::requireLogin();

        $member = TeamMember::findById((int) $params['id']);
        if (!$member) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }
        $member['socials'] = json_decode((string) $member['socials_json'], true) ?: [];

        View::render('admin/team/form', [
            'member' => $member,
            'translations' => TeamMemberTranslation::forMember((int) $member['id']),
            'departments' => TeamMember::departments(),
            'error' => null,
        ]);
    }

    /** @param array<string, string> $params */
    public function update(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $id = (int) $params['id'];
        $member = TeamMember::findById($id);
        if (!$member) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        [$data, $error] = $this->collectInput($id, $member);
        $translations = $this->collectTranslations();

        if ($error !== null) {
            View::render('admin/team/form', [
                'member' => array_merge($member, $data),
                'translations' => $translations,
                'departments' => TeamMember::departments(),
                'error' => $error,
            ]);
            return;
        }

        TeamMember::update($id, $data);
        $this->saveTranslations($id, $translations);
        Flash::success('Данные сотрудника обновлены.');
        header('Location: /admin/team');
        exit;
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        TeamMember::delete((int) $params['id']);
        Flash::success('Сотрудник удалён.');
        header('Location: /admin/team');
        exit;
    }

    /**
     * @return array{0: array, 1: string|null}
     */
    private function collectInput(?int $id, ?array $existing = null): array
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $position = trim((string) ($_POST['position'] ?? ''));
        $department = trim((string) ($_POST['department'] ?? ''));
        $unit = trim((string) ($_POST['unit'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $status = ($_POST['status'] ?? 'published') === 'draft' ? 'draft' : 'published';
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);

        if ($name === '') {
            return [['name' => $name, 'position' => $position, 'department' => $department], 'Укажите имя сотрудника.'];
        }

        $photo = ImageField::resolve('photo_file', 'photo_url', $existing['photo'] ?? null, Auth::id());

        $socials = [];
        foreach (['facebook', 'instagram', 'telegram', 'linkedin', 'whatsapp'] as $network) {
            $value = trim((string) ($_POST['social_' . $network] ?? ''));
            if ($value !== '') {
                $socials[$network] = $value;
            }
        }

        $data = [
            'name' => $name,
            'position' => $position !== '' ? $position : null,
            'department' => $department !== '' ? $department : null,
            'unit' => $unit !== '' ? $unit : null,
            'photo' => $photo,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
            'socials' => $socials,
            'status' => $status,
            'sort_order' => $sortOrder,
        ];

        return [$data, null];
    }

    /**
     * Переводы из полей translations[<lang>][name|position|department|unit] для
     * всех НЕ-основных активных языков. Ключ — код языка.
     *
     * @return array<string, array{name: string, position: string, department: string, unit: string}>
     */
    private function collectTranslations(): array
    {
        $defaultCode = Language::defaultCode();
        $input = (array) ($_POST['translations'] ?? []);
        $out = [];
        foreach (Language::active() as $lang) {
            $code = (string) $lang['code'];
            if ($code === $defaultCode) {
                continue;
            }
            $t = (array) ($input[$code] ?? []);
            $out[$code] = [
                'name' => trim((string) ($t['name'] ?? '')),
                'position' => trim((string) ($t['position'] ?? '')),
                'department' => trim((string) ($t['department'] ?? '')),
                'unit' => trim((string) ($t['unit'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, array{name: string, position: string, department: string, unit: string}> $translations
     */
    private function saveTranslations(int $memberId, array $translations): void
    {
        foreach ($translations as $code => $t) {
            TeamMemberTranslation::upsert($memberId, (string) $code, [
                'name' => $t['name'] ?? '',
                'position' => $t['position'] ?? '',
                'department' => $t['department'] ?? '',
                'unit' => $t['unit'] ?? '',
            ]);
        }
    }
}
