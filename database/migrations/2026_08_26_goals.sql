-- «Цели» (Maqsadlar) — тип контента, у которого нет текста наружу: цель это
-- набор снимков, и на сайте она показывается только каруселью в виджете.
--
-- Имя цели служебное: оно нужно, чтобы отличать записи в списке из сотен
-- штук, поэтому не переводится и в разметку не попадает. Отдельной страницы
-- и slug'а у цели нет — публичного адреса, который надо было бы держать
-- стабильным, не существует.
CREATE TABLE IF NOT EXISTS goals (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(255) NOT NULL COMMENT 'служебное имя: только список админки, наружу не выходит',
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_goals_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Снимки цели. Порядок задаётся редактором; случайным бывает выбор самой
-- цели, а не кадров внутри неё — иначе история, рассказанная слайдами, каждый
-- раз рассыпалась бы.
CREATE TABLE IF NOT EXISTS goal_images (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    goal_id    INT NOT NULL,
    image      VARCHAR(500) NOT NULL,
    alt        VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'описание для диктора; на экране не видно',
    sort_order INT NOT NULL DEFAULT 0,
    INDEX idx_goal_images_goal (goal_id, sort_order),
    CONSTRAINT fk_goal_images_goal FOREIGN KEY (goal_id) REFERENCES goals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
