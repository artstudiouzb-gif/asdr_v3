<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Mailer;
use App\Core\NotificationCenter;
use App\Core\RateLimiter;
use App\Core\View;
use App\Models\FormDef;
use App\Models\FormSubmission;

final class FormController
{
    /** @param array<string, string> $params */
    public function submit(array $params): void
    {
        $slug = $params['slug'] ?? '';
        $form = FormDef::findBySlug($slug);

        if (!$form) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $successMessage = $form['success_message'] ?: 'Спасибо! Ваша заявка отправлена.';

        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->fail('Сессия устарела, обновите страницу и попробуйте снова.', [], 419);
        }

        // Анти-флуд: не более 10 отправок форм с одного IP за 10 минут.
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!RateLimiter::throttle('form', $ip, 10, 10)) {
            $this->fail('Слишком много отправок. Пожалуйста, попробуйте позже.', [], 429);
        }

        // Honeypot: боты заполняют скрытое поле или отправляют форму мгновенно.
        // Тихо показываем «успех», чтобы не подсказывать спамеру о срабатывании.
        if (Csrf::isSpam()) {
            $this->success($successMessage);
        }

        // Капча (одноразовый код из сессии; выключается в «Настройках»).
        if (\App\Core\Captcha::isEnabled() && !\App\Core\Captcha::verify($_POST['_captcha'] ?? null)) {
            $this->fail('Неверный код с картинки. Попробуйте ещё раз.', ['_captcha' => 'Код не совпал или устарел.']);
        }

        // Согласие на обработку персональных данных (если включено глобально).
        if (\App\Models\Setting::get('form_consent_enabled', '0') === '1' && empty($_POST['_consent'])) {
            $this->fail('Подтвердите согласие на обработку персональных данных.', ['_consent' => 'Требуется согласие.']);
        }

        $data = [];
        $errors = [];

        foreach ($form['fields'] as $field) {
            $name = $field['name'];

            // Условная логика (задача 135): скрытое условием поле не валидируем.
            if (!$this->fieldActive($field)) {
                continue;
            }

            // Поле-файл: реальная проверка MIME и сохранение через Uploader.
            if (($field['type'] ?? '') === 'file') {
                $file = $_FILES[$name] ?? null;
                $uploaded = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
                if (!empty($field['required']) && !$uploaded) {
                    $errors[$name] = 'Приложите файл к полю «' . $field['label'] . '».';
                } elseif ($uploaded) {
                    try {
                        // Вложения публичных форм могут содержать персональные
                        // данные и никогда не публикуются напрямую в webroot.
                        $stored = \App\Core\Uploader::store(
                            $file,
                            'protected',
                            null,
                            ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png']
                        );
                        $data[$name] = \App\Models\FileEntry::protectedUrl($stored);
                    } catch (\Throwable $e) {
                        $errors[$name] = $e->getMessage();
                    }
                }
                continue;
            }

            if (is_array($_POST[$name] ?? null)) {
                $values = array_map('trim', $_POST[$name]);
                $values = array_filter($values, static fn($v) => $v !== '');
                if (!empty($field['required']) && empty($values)) {
                    $errors[$name] = 'Поле «' . $field['label'] . '» обязательно.';
                    continue;
                }
                $data[$name] = implode(', ', $values);
            } else {
                $value = trim((string) ($_POST[$name] ?? ''));
                if (!empty($field['required']) && $value === '') {
                    $errors[$name] = 'Поле «' . $field['label'] . '» обязательно.';
                    continue;
                }
                $isEmailField = ($field['type'] === 'email') || str_contains(mb_strtolower($field['name'] . ' ' . ($field['label'] ?? '')), 'email') || str_contains(mb_strtolower($field['name'] . ' ' . ($field['label'] ?? '')), 'mail') || str_contains(mb_strtolower($field['name'] . ' ' . ($field['label'] ?? '')), 'почта');
                if ($isEmailField && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$name] = 'Некорректный e-mail адрес.';
                    continue;
                }

                $isPhoneField = ($field['type'] === 'tel') || str_contains(mb_strtolower($field['name'] . ' ' . ($field['label'] ?? '')), 'телефон') || str_contains(mb_strtolower($field['name'] . ' ' . ($field['label'] ?? '')), 'telefon') || str_contains(mb_strtolower($field['name'] . ' ' . ($field['label'] ?? '')), 'phone');
                if ($isPhoneField && $value !== '') {
                    $digits = preg_replace('/\D/', '', $value);
                    if (strlen($digits) < 7 || preg_match('/[a-zA-Zа-яА-ЯёЁ]/u', $value)) {
                        $errors[$name] = 'Некорректный номер телефона (не менее 7 цифр, без букв).';
                        continue;
                    }
                }

                if (($field['type'] ?? '') === 'textarea' && mb_strlen($value) > 2000) {
                    $errors[$name] = 'Превышена максимальная длина сообщения (не более 2000 символов).';
                    continue;
                }
                $data[$name] = $value;
            }
        }

        if ($errors !== []) {
            $this->fail('Проверьте правильность заполнения формы.', $errors);
        }

        $submissionId = FormSubmission::create(
            (int) $form['id'],
            $data,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        );

        if (!empty($form['notify_email'])) {
            $this->notify($form, $data);
        }

        // Мгновенное уведомление в Telegram (если настроен бот и получатели).
        \App\Core\FormNotifier::notifySubmission((string) $form['name'], $data);

        // Внутреннее уведомление не содержит поля заявки и персональные данные:
        // они доступны только после авторизации и проверки роли в админке.
        NotificationCenter::roles(
            ['admin', 'editor'],
            'Новая заявка',
            'Получена новая заявка через форму «' . (string) $form['name'] . '».',
            'forms',
            'info',
            '/admin/forms/submissions/' . $submissionId,
            false,
            'form-submission-' . $submissionId
        );

        // Событие для исходящих вебхуков (задача 136).
        \App\Core\WebhookDispatcher::dispatch('form.submitted', [
            'form' => (string) $form['slug'],
            'form_name' => (string) ($form['name'] ?? ''),
            'fields' => $data,
        ]);

        $this->success($successMessage);
    }

    /**
     * Активно ли поле по условной логике (задача 135): без условия — всегда;
     * с условием — только если поле-триггер равно заданному значению.
     */
    private function fieldActive(array $field): bool
    {
        $cond = $field['condition'] ?? null;
        if (!is_array($cond) || empty($cond['field'])) {
            return true;
        }
        $trigger = trim((string) ($_POST[(string) $cond['field']] ?? ''));

        return $trigger === (string) ($cond['value'] ?? '');
    }

    /** AJAX-запрос ли это (fetch с X-Requested-With). */
    private function wantsJson(): bool
    {
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /**
     * @param array<string, string> $errors
     */
    private function fail(string $message, array $errors = [], int $code = 200): never
    {
        if ($this->wantsJson()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'message' => $message, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code !== 200) {
            http_response_code($code);
        }
        Flash::error($message);
        $this->redirectBack();
    }

    private function success(string $message): never
    {
        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
            exit;
        }
        Flash::success($message);
        $this->redirectBack();
    }

    private function notify(array $form, array $data): void
    {
        if (!Mailer::isConfigured()) {
            return; // SMTP не настроен — уведомление просто пропускается
        }

        $subject = 'Новая заявка: ' . $form['name'];
        $lines = [];
        foreach ($data as $key => $value) {
            $lines[] = $key . ': ' . $value;
        }
        $body = implode("\n", $lines);

        // Ставим письмо в очередь — фронтенд отрабатывает мгновенно, отправку
        // выполняет CLI-воркер app/Console/mail_worker.php по Cron.
        \App\Models\MailQueue::enqueue((string) $form['notify_email'], $subject, $body);
    }

    private function redirectBack(): never
    {
        header('Location: ' . self::safeReferer());
        exit;
    }

    private static function safeReferer(): string
    {
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $parts = parse_url($referer);
        if (!is_array($parts)) {
            return '/';
        }

        $refererHost = strtolower((string) ($parts['host'] ?? ''));
        $requestHost = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
        if ($refererHost !== '' && ($requestHost === '' || $refererHost !== $requestHost)) {
            return '/';
        }

        $path = (string) ($parts['path'] ?? '/');
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '/';
        }

        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
