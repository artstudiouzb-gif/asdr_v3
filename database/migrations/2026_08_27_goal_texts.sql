-- У цели появляются видимые название и описание.
--
-- Раньше имя было служебным: цель показывалась одной каруселью, и её название
-- нужно было только чтобы отличить запись в списке из сотен штук. Но набор
-- снимков без единого слова ничего не сообщает посетителю — что за объект,
-- где он и зачем. Поэтому название выходит наружу, а рядом с ним появляется
-- описание.
--
-- Раз текст виден, он обязан переводиться: сайт двуязычный, и русское
-- название на узбекской странице — это дефект. Механизм А (перевод накладывается
-- на базовую строку), как у альбомов и видео: у цели нет своего адреса,
-- поэтому отдельная запись на язык ей не нужна.
ALTER TABLE goals
    MODIFY name VARCHAR(255) NOT NULL COMMENT 'название цели: видно на сайте и переводится',
    ADD COLUMN description TEXT NULL COMMENT 'описание под названием; необязательно' AFTER name;

CREATE TABLE IF NOT EXISTS goal_translations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    goal_id     INT NOT NULL,
    lang        VARCHAR(5) NOT NULL,
    name        VARCHAR(255) NULL,
    description TEXT NULL,
    UNIQUE KEY uniq_goal_translation (goal_id, lang),
    CONSTRAINT fk_goal_translations_goal FOREIGN KEY (goal_id) REFERENCES goals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
