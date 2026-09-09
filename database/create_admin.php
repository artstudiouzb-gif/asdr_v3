<?php

declare(strict_types=1);

// Консольная утилита создания первого администратора.
// Запуск: php database/create_admin.php

require __DIR__ . '/../app/Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../app/Core/bootstrap.php';

use App\Models\User;

fwrite(STDOUT, "Имя пользователя: ");
$username = trim((string) fgets(STDIN));

fwrite(STDOUT, "Email: ");
$email = trim((string) fgets(STDIN));

fwrite(STDOUT, "Пароль (минимум 10 символов): ");
system('stty -echo');
$password = trim((string) fgets(STDIN));
system('stty echo');
fwrite(STDOUT, "\n");

if ($username === '' || $email === '' || strlen($password) < 10) {
    fwrite(STDERR, "Ошибка: заполните все поля, пароль должен быть не короче 10 символов.\n");
    exit(1);
}

if (User::findByUsername($username)) {
    fwrite(STDERR, "Пользователь с таким именем уже существует.\n");
    exit(1);
}

$id = User::create($username, $email, $password, 'admin');
fwrite(STDOUT, "Администратор создан (ID {$id}). Настройка 2FA будет предложена при первом входе в /admin/login.\n");
