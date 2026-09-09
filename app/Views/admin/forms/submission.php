<?php

use App\Core\AdminUi;
use App\Core\Csrf;

/** @var array<string,mixed> $submission */
/** @var string $returnQuery */
$submission = $submission ?? [];
$returnQuery = $returnQuery ?? '';
$pageTitle = 'Заявка #' . (int) ($submission['id'] ?? 0);
$activeNav = 'forms';
$backUrl = '/admin/forms/submissions' . ($returnQuery !== '' ? '?' . $returnQuery : '');
$pageActions = '<a href="' . htmlspecialchars($backUrl, ENT_QUOTES) . '" class="btn">'
    . AdminUi::icon('arrow-left') . 'Назад к заявкам</a>';
require __DIR__ . '/../layout/header.php';

$labels = [];
foreach ((array) ($submission['fields'] ?? []) as $field) {
    $name = (string) ($field['name'] ?? '');
    if ($name !== '') {
        $labels[$name] = (string) ($field['label'] ?? $name);
    }
}
$displayValue = static function (mixed $value): string {
    if (is_array($value)) {
        return implode(', ', array_map('strval', $value));
    }
    if (is_bool($value)) {
        return $value ? 'Да' : 'Нет';
    }

    return trim((string) $value);
};
?>

<p class="form-hint admin-section-intro">
    Полная запись, отправленная через форму
    «<?= htmlspecialchars((string) ($submission['form_name'] ?? ''), ENT_QUOTES) ?>».
</p>

<div class="submission-detail-layout">
    <section class="form-card">
        <div class="forms-list-heading">
            <div>
                <h2 class="u-inline-291b7bbb01">Данные заявителя</h2>
                <p class="form-hint">
                    Получена <?= htmlspecialchars(\App\Core\DateFormatter::format((string) ($submission['created_at'] ?? 'now'), 'd.m.Y в H:i'), ENT_QUOTES) ?>
                </p>
            </div>
            <span class="badge badge--success">Просмотрена</span>
        </div>

        <dl class="submission-fields">
            <?php foreach ((array) ($submission['data'] ?? []) as $key => $value): ?>
                <?php $shownValue = $displayValue($value); ?>
                <div>
                    <dt><?= htmlspecialchars($labels[(string) $key] ?? (string) $key, ENT_QUOTES) ?></dt>
                    <dd><?= $shownValue !== '' ? nl2br(htmlspecialchars($shownValue, ENT_QUOTES)) : '—' ?></dd>
                </div>
            <?php endforeach; ?>
            <?php if (empty($submission['data'])): ?>
                <div><dt>Данные</dt><dd>Пустая заявка</dd></div>
            <?php endif; ?>
        </dl>
    </section>

    <aside class="form-card submission-meta">
        <h3 class="u-inline-291b7bbb01">Служебная информация</h3>
        <dl>
            <div><dt>Форма</dt><dd><?= htmlspecialchars((string) ($submission['form_name'] ?? ''), ENT_QUOTES) ?></dd></div>
            <div><dt>ID заявки</dt><dd>#<?= (int) ($submission['id'] ?? 0) ?></dd></div>
            <div><dt>IP-адрес</dt><dd><?= htmlspecialchars((string) ($submission['ip_address'] ?? '—'), ENT_QUOTES) ?></dd></div>
            <div><dt>Браузер</dt><dd><?= htmlspecialchars((string) ($submission['user_agent'] ?? '—'), ENT_QUOTES) ?></dd></div>
        </dl>
        <form method="post" action="/admin/forms/submissions/<?= (int) $submission['id'] ?>/delete"
              data-confirm="Удалить эту заявку без возможности восстановления?">
            <?= Csrf::field() ?>
            <input type="hidden" name="return_query" value="<?= htmlspecialchars($returnQuery, ENT_QUOTES) ?>">
            <button type="submit" class="btn btn--danger">
                <?= AdminUi::icon('trash') ?>Удалить заявку
            </button>
        </form>
    </aside>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
