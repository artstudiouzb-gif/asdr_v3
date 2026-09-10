<?php

/** @var string $auditTab */
$auditTab = $auditTab ?? 'actions';
?>
<div class="u-inline-f94566b02a">
    <a class="btn btn--small<?= $auditTab === 'security' ? ' btn--primary' : '' ?>" href="/admin/security">Центр безопасности</a>
    <a class="btn btn--small<?= $auditTab === 'actions' ? ' btn--primary' : '' ?>" href="/admin/audit">Действия администраторов</a>
    <a class="btn btn--small<?= $auditTab === 'errors' ? ' btn--primary' : '' ?>" href="/admin/audit/errors">Ошибки сайта</a>
    <a class="btn btn--small<?= $auditTab === 'system' ? ' btn--primary' : '' ?>" href="/admin/logs">Системные события</a>
</div>
