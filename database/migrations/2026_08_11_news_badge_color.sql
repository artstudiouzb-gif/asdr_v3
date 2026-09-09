-- Цвет метки новости.
--
-- Метка («Важно», «Анонс») перестала быть рубрикой — это её место заняла
-- категория. Оставшись пометкой, метка должна отличаться на глаз, поэтому у
-- неё появляется свой цвет фона. Цвет текста не хранится: он считается из
-- фона по контрасту (App\Core\AccentContrast::onFill), иначе редактор мог бы
-- выбрать белым по жёлтому и получить нечитаемую плашку.
--
-- Пустое значение = цвет по умолчанию из темы (оттенок акцента), поэтому
-- существующие метки продолжают выводиться без правок.

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'news' AND COLUMN_NAME = 'badge_color'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE news ADD COLUMN badge_color VARCHAR(9) NULL COMMENT ''цвет фона метки (#rrggbb); пусто — цвет темы'' AFTER badge',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;
