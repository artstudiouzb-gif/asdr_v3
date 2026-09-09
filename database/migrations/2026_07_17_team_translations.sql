-- Переводы сотрудников команды: имя и должность на неосновных языках.
-- База (team_members) хранит основной язык; переводы — здесь, накладываются
-- на публичке с graceful-fallback к основному языку при пустом поле.
CREATE TABLE IF NOT EXISTS team_member_translations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id   INT UNSIGNED NOT NULL,
    lang        VARCHAR(8) NOT NULL,
    name        VARCHAR(190) NULL,
    position    VARCHAR(190) NULL,
    UNIQUE KEY uq_team_member_translations (member_id, lang),
    CONSTRAINT fk_team_member_translations_member FOREIGN KEY (member_id) REFERENCES team_members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
