#!/bin/bash
# Подготовка среды разработки для сессий Claude Code в облаке.
#
# Контейнер создаётся заново при каждом запуске, а рабочая копия — это чистый
# клон: нет config/config.php (он в .gitignore), не поднята БД, нет схемы и
# node_modules. Без этого не запускаются ни тесты, ни smoke, ни сборка
# ассетов, и первые ходы сессии уходят на ручную подготовку.
#
# Скрипт идемпотентен: повторный запуск ничего не ломает и не перезаписывает
# уже настроенное.
set -euo pipefail

# Только в облачной среде: на машине владельца своя настроенная копия.
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
    exit 0
fi

cd "${CLAUDE_PROJECT_DIR:-$(pwd)}"

DB_USER=cms
DB_PASS=cms
DB_MAIN=artstudio_cms
DB_TEST=artstudio_test
ENV_LOCAL="storage/.env.local"

say() { printf '  %s\n' "$1"; }

# --- 1. MariaDB -------------------------------------------------------------
if ! mysqladmin --protocol=socket ping >/dev/null 2>&1; then
    say 'Запускаю MariaDB…'
    mariadbd-safe --user=mysql >/tmp/mariadb-start.log 2>&1 &
    for _ in $(seq 1 40); do
        mysqladmin --protocol=socket ping >/dev/null 2>&1 && break
        sleep 1
    done
fi
if ! mysqladmin --protocol=socket ping >/dev/null 2>&1; then
    say 'MariaDB не поднялась — смотрите /tmp/mariadb-start.log'
    exit 0   # не срываем старт сессии: часть работы возможна и без БД
fi
say 'MariaDB отвечает'

# --- 2. Пользователь и базы -------------------------------------------------
mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_MAIN}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS \`${DB_TEST}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_MAIN}\`.* TO '${DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`${DB_MAIN}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_TEST}\`.* TO '${DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`${DB_TEST}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

# Схема — только в пустую базу: чужие данные не трогаем.
for db in "${DB_MAIN}" "${DB_TEST}"; do
    tables=$(mysql -u root -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${db}'")
    if [ "${tables}" = "0" ]; then
        say "Заливаю схему в ${db}…"
        mysql -u root "${db}" < database/schema.sql
    fi
done

# --- 3. Локальная конфигурация ---------------------------------------------
# Ключ шифрования нужен для настроек с токенами; он же должен пережить
# перезапуск контейнера, поэтому храним рядом с базой, а не в переменной.
if [ ! -f "${ENV_LOCAL}" ]; then
    printf 'APP_ENCRYPTION_KEY=%s\n' "$(openssl rand -hex 32)" > "${ENV_LOCAL}"
fi
# shellcheck disable=SC1090
set -a; . "./${ENV_LOCAL}"; set +a

if [ ! -f config/config.php ]; then
    say 'Создаю config/config.php из образца…'
    cp config/config.example.php config/config.php
fi
touch storage/installed.lock

# --- 4. Миграции ------------------------------------------------------------
for db in "${DB_MAIN}" "${DB_TEST}"; do
    DB_DATABASE="${db}" php database/migrate.php >/dev/null 2>&1 || say "Миграции для ${db} пропущены"
done
say 'Схема и миграции на месте'

# --- 5. npm и инструменты ---------------------------------------------------
if [ ! -d node_modules ]; then
    say 'Ставлю npm-зависимости…'
    npm install --no-audit --no-fund >/dev/null 2>&1 || say 'npm install не прошёл'
fi

# PHPStan ставится отдельным phar: composer install в этой среде не проходит
# (нет авторизации GitHub), а анализ в CI блокирующий.
mkdir -p storage/tools
if [ ! -f storage/tools/phpstan.phar ]; then
    curl -sSL -o storage/tools/phpstan.phar \
        https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar 2>/dev/null || true
fi

# --- 6. Переменные для тестов ----------------------------------------------
if [ -n "${CLAUDE_ENV_FILE:-}" ]; then
    {
        printf 'export APP_ENCRYPTION_KEY=%s\n' "${APP_ENCRYPTION_KEY}"
        printf 'export TEST_DB_HOST=127.0.0.1\n'
        printf 'export TEST_DB_DATABASE=%s\n' "${DB_TEST}"
        printf 'export TEST_DB_USERNAME=%s\n' "${DB_USER}"
        printf 'export TEST_DB_PASSWORD=%s\n' "${DB_PASS}"
    } >> "${CLAUDE_ENV_FILE}"
fi

say 'Среда готова: тесты, PHPStan и сборка ассетов запускаются сразу'
