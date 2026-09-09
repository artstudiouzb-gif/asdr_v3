<?php
/** @var array $data */
// Повторная очистка защищает уже сохранённые блоки, созданные до запрета
// inline-скриптов и style-атрибутов.
echo \App\Core\HtmlSanitizer::sanitize((string) ($data['html'] ?? ''));
