-- От портала файлового хранилища (/repo) владелец отказался: у него были свой
-- вход, загрузка файлов посетителями и свои учётные записи — то есть открытая
-- дверь, которую никто не сторожит. Код удалён, вместе с ним уходят таблицы и
-- настройки. Файлы на диске (storage/protected_uploads/repo/) миграция не
-- трогает: их удаляют руками, если они не нужны.
DROP TABLE IF EXISTS repo_files;
DROP TABLE IF EXISTS repo_categories;
DROP TABLE IF EXISTS repo_users;
DELETE FROM settings WHERE `key` LIKE 'repo\_%';
