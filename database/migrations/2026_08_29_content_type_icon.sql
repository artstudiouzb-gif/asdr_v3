-- У типа контента появляется своя иконка.
--
-- Разделы «Вакансии», «Мероприятия», «Документы», «Тендеры» — это типы
-- контента, и в боковом меню все они получали одну общую иконку списка:
-- ключ раздела карте иконок неизвестен, а общий запасной вариант один на всех.
-- Отличить разделы взглядом было нельзя.
--
-- Держать соответствие «slug → иконка» в коде нельзя: типы заводит редактор
-- в админке, и новый тип снова остался бы без своего значка. Поэтому иконка —
-- поле записи, а не карта в PHP; пустое значение по-прежнему даёт общий
-- значок списка.
ALTER TABLE content_types
    ADD COLUMN icon VARCHAR(60) NOT NULL DEFAULT '' AFTER description;

-- Стартовым типам государственного сайта проставляем значки сразу: иначе
-- после обновления они остались бы с общим значком до ручной правки.
UPDATE content_types SET icon = 'file-text' WHERE slug = 'documenty' AND icon = '';
UPDATE content_types SET icon = 'briefcase' WHERE slug = 'vakansii'  AND icon = '';
UPDATE content_types SET icon = 'gavel'     WHERE slug = 'tendery'   AND icon = '';
UPDATE content_types SET icon = 'calendar-event' WHERE slug = 'meropriyatiya' AND icon = '';
