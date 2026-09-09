-- @post-schema
-- Включает новые переиспользуемые варианты блоков на существующей странице
-- «Об Агентстве». Текст редактора не перезаписывается: миграция добавляет
-- только структурированные данные и варианты представления.

UPDATE blocks b
JOIN pages p ON p.id = b.page_id
SET b.data = JSON_SET(
        b.data,
        '$.variant', 'intro',
        '$.aside_title', '',
        '$.items', JSON_ARRAY(
            JSON_OBJECT('icon_svg', 'target', 'title', 'Цель'),
            JSON_OBJECT('icon_svg', 'flag', 'title', 'Действие'),
            JSON_OBJECT('icon_svg', 'chart-bar', 'title', 'Результат')
        ),
        '$.quote', ''
    ),
    b.lock_version = b.lock_version + 1
WHERE p.slug = 'o-nas' AND b.type = 'text' AND b.title = 'Вводная часть';

UPDATE blocks b
JOIN pages p ON p.id = b.page_id
SET b.data = JSON_SET(
        b.data,
        '$.variant', 'intro',
        '$.aside_title', '',
        '$.items', JSON_ARRAY(
            JSON_OBJECT('icon_svg', 'target', 'title', 'Maqsad'),
            JSON_OBJECT('icon_svg', 'flag', 'title', 'Harakat'),
            JSON_OBJECT('icon_svg', 'chart-bar', 'title', 'Natija')
        ),
        '$.quote', ''
    ),
    b.lock_version = b.lock_version + 1
WHERE p.slug = 'o-nas' AND b.type = 'text' AND b.title = 'Kirish qismi';

UPDATE blocks b
JOIN pages p ON p.id = b.page_id
SET b.data = JSON_SET(
        b.data,
        '$.variant', 'intro',
        '$.aside_title', '',
        '$.items', JSON_ARRAY(
            JSON_OBJECT('icon_svg', 'target', 'title', 'Goal'),
            JSON_OBJECT('icon_svg', 'flag', 'title', 'Action'),
            JSON_OBJECT('icon_svg', 'chart-bar', 'title', 'Result')
        ),
        '$.quote', ''
    ),
    b.lock_version = b.lock_version + 1
WHERE p.slug = 'o-nas' AND b.type = 'text' AND b.title = 'Introduction';

UPDATE blocks b
JOIN pages p ON p.id = b.page_id
SET b.data = JSON_SET(b.data, '$.variant', 'section'),
    b.lock_version = b.lock_version + 1
WHERE p.slug = 'o-nas'
  AND b.type = 'text'
  AND b.title IN (
      'Чем занимается Агентство — вступление',
      'Как развивалось Агентство — вступление',
      'Agentlik nima bilan shug‘ullanadi — kirish',
      'Agentlikning rivojlanish tarixi — kirish',
      'What the Agency does — intro',
      'The Agency’s development — intro'
  );

UPDATE blocks b
JOIN pages p ON p.id = b.page_id
SET b.data = JSON_SET(b.data, '$.variant', 'indexed'),
    b.lock_version = b.lock_version + 1
WHERE p.slug = 'o-nas'
  AND b.type = 'advantages'
  AND b.title IN ('Направления работы', 'Faoliyat yo‘nalishlari', 'Areas of work');

UPDATE blocks b
JOIN pages p ON p.id = b.page_id
SET b.data = JSON_SET(
        b.data,
        '$.variant', 'system',
        '$.aside_title', 'Целостная система стратегического управления',
        '$.items', JSON_ARRAY(
            JSON_OBJECT('icon_svg', 'target', 'title', 'Чётко сформулированные цели'),
            JSON_OBJECT('icon_svg', 'network', 'title', 'Согласованные приоритеты'),
            JSON_OBJECT('icon_svg', 'route', 'title', 'Конкретные действия'),
            JSON_OBJECT('icon_svg', 'chart-bar', 'title', 'Измеримые показатели'),
            JSON_OBJECT('icon_svg', 'calendar-check', 'title', 'Регулярный мониторинг'),
            JSON_OBJECT('icon_svg', 'database', 'title', 'Решения на основе данных')
        ),
        '$.quote', ''
    ),
    b.lock_version = b.lock_version + 1
WHERE p.slug = 'o-nas' AND b.type = 'text' AND b.title = 'От стратегии — к реальным изменениям';

UPDATE blocks b
JOIN pages p ON p.id = b.page_id
SET b.data = JSON_SET(
        b.data,
        '$.variant', 'system',
        '$.aside_title', 'Yaxlit strategik boshqaruv tizimi',
        '$.items', JSON_ARRAY(
            JSON_OBJECT('icon_svg', 'target', 'title', 'Aniq belgilangan maqsadlar'),
            JSON_OBJECT('icon_svg', 'network', 'title', 'Muvofiqlashtirilgan ustuvor yo‘nalishlar'),
            JSON_OBJECT('icon_svg', 'route', 'title', 'Aniq harakatlar'),
            JSON_OBJECT('icon_svg', 'chart-bar', 'title', 'O‘lchanadigan ko‘rsatkichlar'),
            JSON_OBJECT('icon_svg', 'calendar-check', 'title', 'Muntazam monitoring'),
            JSON_OBJECT('icon_svg', 'database', 'title', 'Ma’lumotlarga asoslangan qarorlar')
        ),
        '$.quote', ''
    ),
    b.lock_version = b.lock_version + 1
WHERE p.slug = 'o-nas' AND b.type = 'text' AND b.title = 'Strategiyadan — amaliy o‘zgarishlarga';

UPDATE blocks b
JOIN pages p ON p.id = b.page_id
SET b.data = JSON_SET(
        b.data,
        '$.variant', 'system',
        '$.aside_title', 'An Integrated System of Strategic Governance',
        '$.items', JSON_ARRAY(
            JSON_OBJECT('icon_svg', 'target', 'title', 'Clearly defined goals'),
            JSON_OBJECT('icon_svg', 'network', 'title', 'Aligned priorities'),
            JSON_OBJECT('icon_svg', 'route', 'title', 'Concrete actions'),
            JSON_OBJECT('icon_svg', 'chart-bar', 'title', 'Measurable indicators'),
            JSON_OBJECT('icon_svg', 'calendar-check', 'title', 'Regular monitoring'),
            JSON_OBJECT('icon_svg', 'database', 'title', 'Data-informed decisions')
        ),
        '$.quote', ''
    ),
    b.lock_version = b.lock_version + 1
WHERE p.slug = 'o-nas' AND b.type = 'text' AND b.title = 'Turning strategies into real change';

UPDATE blocks b
JOIN pages p ON p.id = b.page_id
SET b.data = JSON_SET(b.data, '$.variant', 'history'),
    b.lock_version = b.lock_version + 1
WHERE p.slug = 'o-nas'
  AND b.type = 'stages'
  AND b.title IN ('Этапы развития', 'Rivojlanish bosqichlari', 'Development stages');

UPDATE blocks b
JOIN pages p ON p.id = b.page_id
SET b.data = JSON_SET(
        b.data,
        '$.variant', 'spotlight',
        '$.quote', 'Стратегическое планирование — это путь от долгосрочной цели к конкретному результату. Агентство помогает сделать этот путь системным, измеримым и согласованным.'
    ),
    b.lock_version = b.lock_version + 1
WHERE p.slug = 'o-nas' AND b.type = 'text' AND b.title = 'Агентство сегодня';

UPDATE blocks b
JOIN pages p ON p.id = b.page_id
SET b.data = JSON_SET(
        b.data,
        '$.variant', 'spotlight',
        '$.quote', 'Strategik rejalashtirish — uzoq muddatli maqsaddan aniq natijaga olib boruvchi yo‘ldir. Agentlik ushbu yo‘lning tizimli, o‘lchanadigan va o‘zaro muvofiqlashtirilgan bo‘lishiga xizmat qiladi.'
    ),
    b.lock_version = b.lock_version + 1
WHERE p.slug = 'o-nas' AND b.type = 'text' AND b.title = 'Agentlik bugun';

UPDATE blocks b
JOIN pages p ON p.id = b.page_id
SET b.data = JSON_SET(
        b.data,
        '$.variant', 'spotlight',
        '$.quote', 'Strategic planning is the path from a long-term objective to a concrete result. The Agency helps make this path systematic, measurable and coordinated.'
    ),
    b.lock_version = b.lock_version + 1
WHERE p.slug = 'o-nas' AND b.type = 'text' AND b.title = 'The Agency today';

UPDATE blocks b
JOIN pages p ON p.id = b.page_id
SET b.data = JSON_SET(b.data, '$.variant', 'acts-editorial'),
    b.lock_version = b.lock_version + 1
WHERE p.slug = 'o-nas'
  AND b.type = 'docs_list'
  AND b.title IN ('Нормативно-правовые документы', 'Normativ-huquqiy hujjatlar', 'Legal documents');

UPDATE blocks b
JOIN pages p ON p.id = b.page_id
SET b.data = JSON_SET(b.data, '$.career_title', 'Профессиональный путь'),
    b.lock_version = b.lock_version + 1
WHERE p.slug = 'direktor' AND p.lang = 'ru' AND b.type = 'bio_education';

UPDATE blocks b
JOIN pages p ON p.id = b.page_id
SET b.data = JSON_SET(b.data, '$.career_title', 'Kasbiy faoliyati'),
    b.lock_version = b.lock_version + 1
WHERE p.slug = 'direktor' AND p.lang = 'uz' AND b.type = 'bio_education';
