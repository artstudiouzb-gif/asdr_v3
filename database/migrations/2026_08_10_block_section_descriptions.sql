-- @post-schema
-- Переносит вступления старой демо-страницы «Об Агентстве» в собственные
-- заголовок и описание блоков преимуществ/этапов. Пользовательский текст
-- сохраняется как есть; исходный text-блок удаляется только после проверки,
-- что оба значения уже записаны в целевой блок.

UPDATE blocks target
JOIN pages p ON p.id = target.page_id
JOIN blocks intro
  ON intro.page_id = target.page_id
 AND intro.lang <=> target.lang
 AND intro.type = 'text'
 AND intro.title = CASE target.title
     WHEN 'Направления работы' THEN 'Чем занимается Агентство — вступление'
     WHEN 'Этапы развития' THEN 'Как развивалось Агентство — вступление'
     WHEN 'Faoliyat yo‘nalishlari' THEN 'Agentlik nima bilan shug‘ullanadi — kirish'
     WHEN 'Rivojlanish bosqichlari' THEN 'Agentlikning rivojlanish tarixi — kirish'
     WHEN 'Areas of work' THEN 'What the Agency does — intro'
     WHEN 'Development stages' THEN 'The Agency’s development — intro'
 END
SET target.data = JSON_SET(
        target.data,
        '$.title', JSON_UNQUOTE(JSON_EXTRACT(intro.data, '$.title')),
        '$.description', JSON_UNQUOTE(JSON_EXTRACT(intro.data, '$.content'))
    ),
    target.lock_version = target.lock_version + 1
WHERE p.slug = 'o-nas'
  AND target.type IN ('advantages', 'stages')
  AND target.title IN (
      'Направления работы', 'Этапы развития',
      'Faoliyat yo‘nalishlari', 'Rivojlanish bosqichlari',
      'Areas of work', 'Development stages'
  )
  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(target.data, '$.description')), '') = '';

DELETE intro
FROM blocks intro
JOIN pages p ON p.id = intro.page_id
JOIN blocks target
  ON target.page_id = intro.page_id
 AND target.lang <=> intro.lang
 AND target.title = CASE intro.title
     WHEN 'Чем занимается Агентство — вступление' THEN 'Направления работы'
     WHEN 'Как развивалось Агентство — вступление' THEN 'Этапы развития'
     WHEN 'Agentlik nima bilan shug‘ullanadi — kirish' THEN 'Faoliyat yo‘nalishlari'
     WHEN 'Agentlikning rivojlanish tarixi — kirish' THEN 'Rivojlanish bosqichlari'
     WHEN 'What the Agency does — intro' THEN 'Areas of work'
     WHEN 'The Agency’s development — intro' THEN 'Development stages'
 END
WHERE p.slug = 'o-nas'
  AND intro.type = 'text'
  AND intro.title IN (
      'Чем занимается Агентство — вступление',
      'Как развивалось Агентство — вступление',
      'Agentlik nima bilan shug‘ullanadi — kirish',
      'Agentlikning rivojlanish tarixi — kirish',
      'What the Agency does — intro',
      'The Agency’s development — intro'
  )
  AND JSON_UNQUOTE(JSON_EXTRACT(target.data, '$.title'))
      = JSON_UNQUOTE(JSON_EXTRACT(intro.data, '$.title'))
  AND JSON_UNQUOTE(JSON_EXTRACT(target.data, '$.description'))
      = JSON_UNQUOTE(JSON_EXTRACT(intro.data, '$.content'));
