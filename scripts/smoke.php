<?php

declare(strict_types=1);

/**
 * Smoke-обходчик сайта: открывает все публичные страницы (обход по ссылкам от
 * главной + ключевые маршруты) и, при передаче логина, все разделы админки —
 * и проверяет каждую на HTTP-ошибки и PHP-фаталы. Заменяет ручной клик по
 * десяткам страниц одной командой после деплоя.
 *
 * Запуск:
 *   php scripts/smoke.php [BASE_URL] [--admin ЛОГИН:ПАРОЛЬ] [--totp СЕКРЕТ] [--max N]
 *
 * Примеры:
 *   php scripts/smoke.php http://127.0.0.1:8000
 *   php scripts/smoke.php https://artstudio.uz --admin admin:secret
 *
 * Код выхода 0 — всё зелёное; 1 — есть падения (удобно для CI/скриптов).
 */

$base = 'http://127.0.0.1:8000';
$adminCreds = null;
$adminTotp = null;
$maxPages = 200;
$expectedRelease = null;

$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($a === '--admin' && isset($args[$i + 1])) {
        $adminCreds = $args[++$i];
    } elseif ($a === '--totp' && isset($args[$i + 1])) {
        // Секрет приложения-аутентификатора: с ним обход проходит второй
        // фактор сам, иначе вся админка остаётся непроверенной.
        $adminTotp = trim((string) $args[++$i]);
    } elseif ($a === '--expect-release' && isset($args[$i + 1])) {
        $expectedRelease = trim((string) $args[++$i]);
    } elseif ($a === '--max' && isset($args[$i + 1])) {
        $maxPages = max(1, (int) $args[++$i]);
    } elseif (str_starts_with($a, 'http://') || str_starts_with($a, 'https://')) {
        $base = rtrim($a, '/');
    }
}

$host = parse_url($base, PHP_URL_HOST);

// Сигнатуры ошибок PHP/приложения в теле ответа.
$errorNeedles = [
    'Fatal error', 'Parse error', 'Uncaught', 'Stack trace:',
    'Call to undefined', 'Call to a member function', 'Allowed memory size',
    'Maximum execution time', 'Undefined array key', 'Undefined variable',
    'Trying to access array offset', 'must be of type', 'Typed property',
];

$cookieJar = tempnam(sys_get_temp_dir(), 'smoke_ck_');

/**
 * @return array{status:int,body:string,final:string}
 */
function fetch(string $url, string $cookieJar, ?array $post = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_USERAGENT => 'ArtStudio-Smoke/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

    return ['status' => $status, 'body' => $body, 'final' => $final];
}

/** Извлечь внутренние ссылки-страницы из HTML. */
function extractLinks(string $body, string $base, string $host): array
{
    $out = [];
    if (!preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/i', $body, $m)) {
        return $out;
    }
    foreach ($m[1] as $href) {
        $href = trim($href);
        if ($href === '' || $href[0] === '#') {
            continue;
        }
        if (preg_match('#^(mailto:|tel:|javascript:|data:)#i', $href)) {
            continue;
        }
        // Абсолютный чужой хост — пропускаем.
        if (preg_match('#^https?://#i', $href)) {
            if (parse_url($href, PHP_URL_HOST) !== $host) {
                continue;
            }
            $path = (string) parse_url($href, PHP_URL_PATH);
        } elseif ($href[0] === '/') {
            $path = (string) parse_url($href, PHP_URL_PATH);
        } else {
            continue; // относительные без / — пропускаем для простоты
        }
        // Пропускаем ассеты, выходы, загрузки.
        if (preg_match('#\.(css|js|png|jpe?g|gif|svg|webp|ico|zip|pdf|xml|txt|webmanifest|woff2?)$#i', $path)) {
            continue;
        }
        if (preg_match('#^/(logout|admin/logout|captcha|push/|repo/download|news/[^/]+/photos)#i', $path)) {
            continue;
        }
        // Календарь листается бесконечно вперёд/назад (?m=YYYY-MM) — не
        // разворачиваем эти ссылки, чтобы обход не «утонул» в месяцах.
        $query = (string) parse_url($href, PHP_URL_QUERY);
        if (preg_match('/(^|&)m=\d{4}-\d{2}/', $query)) {
            continue;
        }
        $out[$base . $path . ($query !== '' ? '?' . $query : '')] = true;
    }

    return array_keys($out);
}

$checkBody = static function (string $body) use ($errorNeedles): ?string {
    foreach ($errorNeedles as $needle) {
        if (stripos($body, $needle) !== false) {
            return $needle;
        }
    }
    return null;
};

$results = [];
$fail = 0;

// --- Публичный обход (BFS от главной + ключевые маршруты) ---
$seed = [
    $base . '/', $base . '/news', $base . '/projects', $base . '/albums',
    $base . '/search?q=test', $base . '/calendar', $base . '/opendata',
    $base . '/sitemap.xml', $base . '/robots.txt', $base . '/health',
    $base . '/assets/css/system.css',
];
$queue = $seed;
$visited = [];

echo "Обход публичной части: {$base}\n";
while (!empty($queue) && count($visited) < $maxPages) {
    $url = array_shift($queue);
    $key = strtok($url, '#');
    if (isset($visited[$key])) {
        continue;
    }
    $visited[$key] = true;

    $r = fetch($url, $cookieJar);
    $errSig = $r['status'] < 400 ? $checkBody($r['body']) : null;
    if ($expectedRelease !== null
        && (string) parse_url($url, PHP_URL_PATH) === '/health'
        && $r['status'] < 400) {
        $healthData = json_decode($r['body'], true);
        $actualRelease = is_array($healthData) ? (string) ($healthData['release'] ?? '') : '';
        if (!hash_equals($expectedRelease, $actualRelease)) {
            $errSig = 'release mismatch: expected ' . $expectedRelease . ', got ' . ($actualRelease !== '' ? $actualRelease : 'empty');
        }
    }
    $ok = $r['status'] > 0 && $r['status'] < 400 && $errSig === null;
    if (!$ok) {
        $fail++;
    }
    $results[] = [$ok, $r['status'], $url, $errSig];

    // Раскрываем ссылки только с HTML-страниц.
    if ($r['status'] < 400 && stripos($r['body'], '<html') !== false) {
        foreach (extractLinks($r['body'], $base, (string) $host) as $link) {
            if (!isset($visited[strtok($link, '#')])) {
                $queue[] = $link;
            }
        }
    }
}

// --- Админка (по желанию) ---
if ($adminCreds !== null) {
    [$user, $pass] = array_pad(explode(':', $adminCreds, 2), 2, '');
    echo "\nВход в админку под «{$user}»…\n";
    $login = fetch($base . '/admin/login', $cookieJar);
    $csrf = '';
    if (preg_match('/name=["\']csrf_token["\']\s+value=["\']([^"\']+)["\']/i', $login['body'], $mm)) {
        $csrf = $mm[1];
    }
    $post = ['username' => $user, 'password' => $pass];
    if ($csrf !== '') {
        $post['csrf_token'] = $csrf;
    }
    $auth = fetch($base . '/admin/login', $cookieJar, $post);

    if (stripos($auth['final'], '/admin/login/2fa') !== false && $adminTotp !== null) {
        require_once __DIR__ . '/../app/Core/TOTP.php';
        $csrf2 = '';
        if (preg_match('/name=["\']csrf_token["\']\s+value=["\']([^"\']+)["\']/i', $auth['body'], $mm2)) {
            $csrf2 = $mm2[1];
        }
        $auth = fetch($base . '/admin/login/2fa', $cookieJar, [
            'code' => \App\Core\TOTP::code($adminTotp),
            'csrf_token' => $csrf2,
        ]);
    }

    if (stripos($auth['final'], '/admin/login/2fa') !== false) {
        echo "  ⚠ Нужен код второго фактора — обход админки пропущен. Передайте --totp СЕКРЕТ.\n";
    } elseif (stripos($auth['final'], '/admin/login') !== false) {
        echo "  ✗ Не удалось войти (проверьте логин/пароль).\n";
        $fail++;
    } elseif (stripos($auth['final'], '/admin/profile') !== false) {
        // Сессия без подключённого второго фактора пускает только в профиль:
        // все остальные адреса молча редиректятся туда же. Раньше обход
        // засчитывал такие редиректы как успех и «проверял» один профиль.
        echo "  ✗ Вход ограничен настройкой второго фактора — админка не проверена.\n";
        echo "    Подключите Telegram выбранному пользователю или укажите другого админа.\n";
        $fail++;
    } else {
        $adminRoutes = [
            '/admin', '/admin/news', '/admin/news-categories', '/admin/pages', '/admin/heroes', '/admin/projects', '/admin/albums',
            '/admin/videos', '/admin/team', '/admin/forms', '/admin/languages', '/admin/menu', '/admin/header',
            '/admin/footer', '/admin/performance', '/admin/widgets', '/admin/trash',
            '/admin/audit', '/admin/audit/errors', '/admin/subscribers', '/admin/redirects', '/admin/users',
            '/admin/design', '/admin/settings', '/admin/social', '/admin/webhooks',
            '/admin/files', '/admin/repository', '/admin/profile',
            // Формы создания: именно здесь редактор проводит время, и именно
            // они падают от опечатки во вьюхе — списки такую ошибку не видят.
            '/admin/news/create', '/admin/pages/create', '/admin/projects/create',
            '/admin/team/create', '/admin/forms/create', '/admin/widgets/create',
        ];
        echo "Обход админки:\n";
        foreach ($adminRoutes as $path) {
            $r = fetch($base . $path, $cookieJar);
            // Редирект на логин — сессия слетела; редирект на профиль с другого
            // адреса означает ограниченную сессию, и страница не проверена.
            $bounced = stripos($r['final'], '/admin/login') !== false;
            $bouncedToProfile = !$bounced
                && $path !== '/admin/profile'
                && stripos($r['final'], '/admin/profile') !== false;
            if ($bounced || $bouncedToProfile) {
                $errSig = $bounced ? 'сессия/доступ' : 'редирект на профиль: доступ ограничен';
            } elseif ($path === '/admin/audit/errors') {
                // Журнал ошибок показывает тексты перехваченных ошибок — там
                // «Uncaught» и «Stack trace» лежат в данных, а не в поломке
                // страницы. Проверяем только код ответа.
                $errSig = null;
            } else {
                $errSig = $r['status'] < 400 ? $checkBody($r['body']) : null;
            }
            $ok = !$bounced && !$bouncedToProfile && $r['status'] > 0 && $r['status'] < 400 && $errSig === null;
            if (!$ok) {
                $fail++;
            }
            $results[] = [$ok, $r['status'], $base . $path, $errSig];
        }
    }
}

@unlink($cookieJar);

// --- Отчёт ---
echo "\n" . str_repeat('─', 72) . "\n";
foreach ($results as [$ok, $status, $url, $errSig]) {
    $mark = $ok ? '✓' : '✗';
    $line = sprintf('%s  %3d  %s', $mark, $status, $url);
    if ($errSig !== null) {
        $line .= "   [{$errSig}]";
    }
    echo $line . "\n";
}
echo str_repeat('─', 72) . "\n";
$total = count($results);
$passed = $total - $fail;
echo "Проверено страниц: {$total}   ✓ {$passed}   ✗ {$fail}\n";

exit($fail > 0 ? 1 : 0);
