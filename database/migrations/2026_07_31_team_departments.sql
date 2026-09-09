-- Подразделение сотрудника: сектор (верхний уровень, задаёт якорь для ссылок
-- из схемы оргструктуры) и отдел/группа внутри сектора.
-- Шаги идемпотентны: колонка добавляется только если её ещё нет.

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'team_members'
      AND COLUMN_NAME = 'department'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE team_members ADD COLUMN department VARCHAR(190) NULL AFTER position',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'team_member_translations'
      AND COLUMN_NAME = 'department'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE team_member_translations ADD COLUMN department VARCHAR(190) NULL AFTER position',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'team_members'
      AND COLUMN_NAME = 'unit'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE team_members ADD COLUMN unit VARCHAR(190) NULL AFTER department',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'team_member_translations'
      AND COLUMN_NAME = 'unit'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE team_member_translations ADD COLUMN unit VARCHAR(190) NULL AFTER department',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

-- Список команды всегда читается как «опубликованные по порядку сортировки».
SET @asdr_has_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'team_members'
      AND INDEX_NAME = 'idx_team_members_listing'
);
SET @asdr_sql := IF(
    @asdr_has_index = 0,
    'ALTER TABLE team_members ADD KEY idx_team_members_listing (status, sort_order)',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;
