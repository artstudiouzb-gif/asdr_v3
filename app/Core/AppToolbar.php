<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Плавающая панель администратора/редактора на публичном сайте (Admin TopBar).
 */
final class AppToolbar
{
    public static function isVisible(): bool
    {
        return Auth::sessionUser() !== null;
    }

    /**
     * Рендерит плавающую панель администратора на публичных страницах.
     */
    public static function renderHtml(array $context = []): string
    {
        $user = Auth::sessionUser();
        if ($user === null) {
            return '';
        }
        $username = (string) ($user['username'] ?? 'Admin');
        $role = (string) ($user['role'] ?? 'admin');

        // Определяем прямую ссылку на редактирование текущей сущности
        $editUrl = '/admin';
        $editLabel = 'Панель';

        if (!empty($context['page']['id'])) {
            $id = (int) $context['page']['id'];
            $editUrl = "/admin/pages/{$id}/edit";
            $editLabel = 'Редактировать страницу';
        } elseif (!empty($context['news']['id'])) {
            $id = (int) $context['news']['id'];
            $editUrl = "/admin/news/{$id}/edit";
            $editLabel = 'Редактировать новость';
        } elseif (!empty($context['project']['id'])) {
            $id = (int) $context['project']['id'];
            $editUrl = "/admin/projects/{$id}/edit";
            $editLabel = 'Редактировать проект';
        }

        return trim(View::renderPartial('site/components/admin_toolbar', [
            'username' => $username,
            'role' => $role,
            'editUrl' => $editUrl,
            'editLabel' => $editLabel,
            'csrfToken' => Csrf::token(),
        ]));
    }
}
