<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Locale;
use App\Core\RateLimiter;
use App\Models\Setting;
use App\Models\Subscriber;

/** Публичная подписка на email-дайджест новостей и отписка по токену. */
final class SubscribeController
{
    public function subscribe(): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            Flash::error(t('Сессия устарела, обновите страницу и попробуйте снова.'));
            $this->back();
        }

        // Анти-флуд: не более 5 подписок с одного IP за 10 минут.
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!RateLimiter::throttle('subscribe', $ip, 5, 10)) {
            Flash::error(t('Слишком много попыток. Пожалуйста, попробуйте позже.'));
            $this->back();
        }

        // Honeypot: боту тихо показываем «успех».
        if (Csrf::isSpam()) {
            Flash::success(t('Вы подписаны на дайджест новостей.'));
            $this->back();
        }

        $consent = !empty($_POST['consent']);
        if (Setting::get('form_consent_enabled', '0') === '1' && !$consent) {
            Flash::error(t('Подтвердите согласие на обработку персональных данных.'));
            $this->back();
        }

        $result = Subscriber::subscribe(
            (string) ($_POST['email'] ?? ''),
            Locale::current(),
            (string) ($_POST['source'] ?? 'website'),
            $consent
        );
        match ($result) {
            'ok' => Flash::success(t('Вы подписаны на дайджест новостей.')),
            'exists' => Flash::success(t('Этот адрес уже подписан на дайджест.')),
            default => Flash::error(t('Укажите корректный email.')),
        };
        $this->back();
    }

    public function unsubscribe(): void
    {
        if (Subscriber::unsubscribeByToken((string) ($_GET['token'] ?? ''))) {
            Flash::success(t('Вы отписаны от дайджеста. Спасибо, что были с нами!'));
        } else {
            Flash::error(t('Ссылка отписки недействительна или уже использована.'));
        }
        header('Location: ' . Locale::url('', Locale::current()));
        exit;
    }

    private function back(): never
    {
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $parts = parse_url($referer);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '/') : '/';
        $refererHost = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $requestHost = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
        if (!str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || ($refererHost !== '' && ($requestHost === '' || $refererHost !== $requestHost))) {
            $path = '/';
        } elseif (is_array($parts) && isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        header('Location: ' . $path);
        exit;
    }
}
