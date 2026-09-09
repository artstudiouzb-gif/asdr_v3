<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\RbacGuard;
use App\Core\Slug;
use App\Core\View;
use App\Models\FormDef;
use App\Models\FormSubmission;

final class FormController
{
    public function index(): void
    {
        Auth::requireLogin();
        $canManageSubmissions = RbacGuard::can('manage_submissions');
        $items = FormDef::all();
        $counts = $canManageSubmissions ? FormSubmission::countsByForm() : [];
        foreach ($items as &$item) {
            $stats = $counts[(int) $item['id']] ?? ['total' => 0, 'unread' => 0];
            $item['submissions_total'] = $canManageSubmissions ? (int) $stats['total'] : 0;
            $item['unread'] = $canManageSubmissions ? (int) $stats['unread'] : 0;
        }
        View::render('admin/forms/index', [
            'items' => $items,
            'canManageSubmissions' => $canManageSubmissions,
            'submissionSummary' => $canManageSubmissions ? FormSubmission::summary() : null,
        ]);
    }

    public function create(): void
    {
        Auth::requireLogin();
        View::render('admin/forms/form', ['form' => null, 'error' => null]);
    }

    public function store(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        [$data, $error] = $this->collectInput(null);

        if ($error !== null) {
            View::render('admin/forms/form', ['form' => $data, 'error' => $error]);
            return;
        }

        FormDef::create($data);
        Flash::success('Форма создана.');
        header('Location: /admin/forms');
        exit;
    }

    /** @param array<string, string> $params */
    public function edit(array $params): void
    {
        Auth::requireLogin();

        $form = FormDef::findById((int) $params['id']);
        if (!$form) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        View::render('admin/forms/form', ['form' => $form, 'error' => null]);
    }

    /** @param array<string, string> $params */
    public function update(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $id = (int) $params['id'];
        $form = FormDef::findById($id);
        if (!$form) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        [$data, $error] = $this->collectInput($id, $form);

        if ($error !== null) {
            View::render('admin/forms/form', ['form' => array_merge($form, $data), 'error' => $error]);
            return;
        }

        FormDef::update($id, $data);
        Flash::success('Форма обновлена.');
        header('Location: /admin/forms');
        exit;
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        Auth::requireLogin();
        RbacGuard::requirePermission('manage_submissions');
        Csrf::verifyRequest();

        FormDef::delete((int) $params['id']);
        Flash::success('Форма удалена.');
        header('Location: /admin/forms');
        exit;
    }

    /** @param array<string, string> $params */
    public function submissions(array $params): void
    {
        Auth::requireLogin();
        RbacGuard::requirePermission('manage_submissions');

        $form = FormDef::findById((int) ($params['id'] ?? 0));
        if (!$form) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $this->renderSubmissions((int) $form['id']);
    }

    /** Общий журнал заявок всех форм. */
    public function allSubmissions(): void
    {
        Auth::requireLogin();
        RbacGuard::requirePermission('manage_submissions');

        $this->renderSubmissions(null);
    }

    /**
     * Карточка одной заявки; только здесь она считается прочитанной.
     * @param array<string, string> $params
     */
    public function submission(array $params): void
    {
        Auth::requireLogin();
        RbacGuard::requirePermission('manage_submissions');

        $submission = FormSubmission::findWithForm((int) ($params['id'] ?? 0));
        if ($submission === null) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }
        FormSubmission::markRead((int) $submission['id']);
        $submission['is_read'] = 1;

        View::render('admin/forms/submission', [
            'submission' => $submission,
            'returnQuery' => $this->cleanSubmissionQuery($_GET['return_query'] ?? ''),
        ]);
    }

    /** @param array<string, string> $params */
    public function deleteSubmission(array $params): void
    {
        Auth::requireLogin();
        RbacGuard::requirePermission('manage_submissions');
        Csrf::verifyRequest();

        $id = (int) ($params['id'] ?? 0);
        if (FormSubmission::findWithForm($id) === null) {
            Flash::error('Заявка не найдена.');
        } else {
            FormSubmission::delete($id);
            Flash::success('Заявка удалена.');
        }
        $query = $this->cleanSubmissionQuery($_POST['return_query'] ?? '');
        header('Location: /admin/forms/submissions' . ($query !== '' ? '?' . $query : ''));
        exit;
    }

    private function renderSubmissions(?int $fixedFormId): void
    {
        $forms = FormDef::all();
        $formId = $fixedFormId ?? max(0, (int) ($_GET['form_id'] ?? 0));
        $selectedForm = null;
        if ($formId > 0) {
            foreach ($forms as $form) {
                if ((int) $form['id'] === $formId) {
                    $selectedForm = $form;
                    break;
                }
            }
            if ($selectedForm === null) {
                $formId = 0;
            }
        }
        $status = (string) ($_GET['status'] ?? '');
        $status = in_array($status, ['unread', 'read'], true) ? $status : '';
        $filters = [
            'form_id' => $formId,
            'status' => $status,
            'q' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 120),
        ];
        $result = FormSubmission::search(
            $filters,
            max(1, (int) ($_GET['page'] ?? 1)),
            20
        );

        View::render('admin/forms/submissions', [
            'forms' => $forms,
            'selectedForm' => $selectedForm,
            'fixedForm' => $fixedFormId !== null,
            'submissions' => $result['items'],
            'summary' => FormSubmission::summary($formId > 0 ? $formId : null),
            'filters' => $filters,
            'total' => $result['total'],
            'page' => $result['page'],
            'pages' => $result['pages'],
        ]);
    }

    /** Разрешённые параметры возврата из карточки/POST без доверия Referer. */
    private function cleanSubmissionQuery(mixed $raw): string
    {
        parse_str(mb_substr((string) $raw, 0, 1000), $input);
        $params = [];
        $rawQ = $input['q'] ?? '';
        $q = mb_substr(trim(is_string($rawQ) ? $rawQ : ''), 0, 120);
        if ($q !== '') {
            $params['q'] = $q;
        }
        $rawStatus = $input['status'] ?? '';
        $status = is_string($rawStatus) ? $rawStatus : '';
        if (in_array($status, ['unread', 'read'], true)) {
            $params['status'] = $status;
        }
        $formId = max(0, (int) ($input['form_id'] ?? 0));
        if ($formId > 0) {
            $params['form_id'] = $formId;
        }
        $page = max(1, (int) ($input['page'] ?? 1));
        if ($page > 1) {
            $params['page'] = $page;
        }

        return http_build_query($params);
    }

    /**
     * @return array{0: array, 1: string|null}
     */
    private function collectInput(?int $id, ?array $existing = null): array
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $notifyEmail = trim((string) ($_POST['notify_email'] ?? ''));
        $successMessage = trim((string) ($_POST['success_message'] ?? ''));

        if ($name === '') {
            return [['name' => $name], 'Укажите название формы.'];
        }

        $fields = [];
        foreach ((array) ($_POST['fields'] ?? []) as $field) {
            $fieldName = trim((string) ($field['name'] ?? ''));
            $fieldLabel = trim((string) ($field['label'] ?? ''));
            if ($fieldName === '' || $fieldLabel === '') {
                continue;
            }
            $fieldName = preg_replace('/[^a-z0-9_]/i', '', $fieldName) ?? '';
            $type = (string) ($field['type'] ?? 'text');
            $type = in_array($type, ['text', 'email', 'tel', 'textarea', 'file', 'select', 'radio', 'checkbox_group', 'checkbox', 'date'], true) ? $type : 'text';
            $entry = [
                'name' => $fieldName,
                'label' => $fieldLabel,
                'type' => $type,
                'required' => !empty($field['required']),
            ];
            if (in_array($type, ['select', 'radio', 'checkbox_group'], true)) {
                $entry['options'] = trim((string) ($field['options'] ?? ''));
            }
            // Условная логика показа поля (задача 135): показывать только если
            // другое поле равно заданному значению.
            $condField = preg_replace('/[^a-z0-9_]/i', '', trim((string) ($field['condition_field'] ?? ''))) ?? '';
            if ($condField !== '') {
                $entry['condition'] = [
                    'field' => $condField,
                    'value' => trim((string) ($field['condition_value'] ?? '')),
                ];
            }
            $fields[] = $entry;
        }

        if (empty($fields)) {
            return [['name' => $name], 'Добавьте хотя бы одно поле формы.'];
        }

        $slug = $slugInput !== '' ? Slug::make($slugInput) : Slug::make($name);
        if (FormDef::slugExists($slug, $id)) {
            $slug .= '-' . bin2hex(random_bytes(2));
        }

        $data = [
            'name' => $name,
            'slug' => $slug,
            'fields' => $fields,
            'notify_email' => $notifyEmail !== '' ? $notifyEmail : null,
            'success_message' => $successMessage !== '' ? $successMessage : 'Спасибо! Ваша заявка отправлена.',
        ];

        return [$data, null];
    }
}
