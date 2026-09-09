<?php

declare(strict_types=1);

/*
 * Реальный контент Агентства: страницы «Об Агентстве», «Руководство»,
 * профили руководителей и записи раздела «Команда».
 *
 * Применяется скриптом `php database/seed_agency_content.php` — он заменяет
 * демо-содержимое страниц с теми же slug, что использует демо-меню
 * (`o-nas`, `rukovodstvo`, `direktor`), поэтому ссылки в меню не ломаются.
 *
 * Формат: slug => ['group' => …, '<lang>' => ['title','lead','meta_*','blocks']].
 * Блок — ['type', 'title' (внутреннее имя для админки), 'data'].
 * Ключи внутри data обязаны совпадать с BlockTypeRegistry::defaults(), иначе
 * первое же сохранение блока в админке их потеряет (стережёт тест 227).
 */

return [
    // Родительские страницы: URL остаются плоскими, иерархия нужна хлебным
    // крошкам (Page::ancestorTrail). Родитель ищется на том же языке.
    'hierarchy' => [
        'rukovodstvo' => 'o-nas',
        'direktor' => 'rukovodstvo',
        'pervyy-zamestitel-direktora' => 'rukovodstvo',
    ],

    'pages' => [
        // -------------------------------------------------------------------
        // Об Агентстве
        // -------------------------------------------------------------------
        'o-nas' => [
            'ru' => [
                'title' => 'Об Агентстве',
                'lead' => 'Стратегия, которая приводит к результату',
                'meta_title' => 'Об Агентстве — Агентство стратегического развития и реформ',
                'meta_description' => 'Агентство стратегического развития и реформ при Президенте Республики Узбекистан — уполномоченный государственный орган в сфере стратегического планирования и развития.',
                'blocks' => [
                    ['text', 'Вводная часть', [
                        'variant' => 'intro',
                        'title' => '',
                        'content' => '<p><strong>Агентство стратегического развития и реформ при Президенте Республики Узбекистан</strong> — уполномоченный государственный орган в сфере стратегического планирования и развития.</p>'
                            . '<p>Агентство помогает выстраивать единую систему, в которой долгосрочные цели страны последовательно преобразуются в конкретные действия, измеримые показатели и практические результаты.</p>'
                            . '<p>Его задача — содействовать тому, чтобы государственные стратегии и программы были взаимосвязаны, основывались на качественном анализе, имели понятные цели и механизмы реализации, а их выполнение регулярно оценивалось.</p>',
                        'aside_title' => '',
                        'items' => [
                            ['icon_svg' => 'target', 'title' => 'Цель'],
                            ['icon_svg' => 'flag', 'title' => 'Действие'],
                            ['icon_svg' => 'chart-bar', 'title' => 'Результат'],
                        ],
                        'quote' => '',
                    ]],
                    ['advantages', 'Направления работы', [
                        '_reveal' => ['enabled' => true, 'type' => 'stagger'],
                        'variant' => 'indexed',
                        'title' => 'Чем занимается Агентство',
                        'description' => '<p>Агентство участвует во всём цикле стратегического планирования — от анализа и подготовки инициатив до мониторинга их реализации и оценки достигнутых результатов.</p>',
                        'items' => [
                            ['icon_svg' => 'target', 'title' => 'Стратегическое планирование', 'text' => 'Агентство формирует методологические подходы к разработке стратегических документов и содействует созданию единой системы стратегического планирования на республиканском, отраслевом и региональном уровнях.'],
                            ['icon_svg' => 'chart-line', 'title' => 'Анализ и разработка инициатив', 'text' => 'Изучаются социально-экономические процессы, актуальные проблемы развития, международный опыт, исследования и рекомендации экспертного сообщества. На основе анализа разрабатываются предложения по новым направлениям развития и системным реформам.'],
                            ['icon_svg' => 'network', 'title' => 'Координация', 'text' => 'Агентство координирует деятельность подразделений стратегического планирования министерств и ведомств, а также соответствующих информационно-аналитических групп в регионах. Это позволяет увязывать отраслевые и территориальные стратегии с общенациональными приоритетами.'],
                            ['icon_svg' => 'clipboard-check', 'title' => 'Качество стратегических документов', 'text' => 'Проекты стратегий и других документов стратегического планирования проходят предусмотренные системой этапы анализа и проверки качества. Оцениваются обоснованность поставленных целей, взаимосвязь мероприятий и ожидаемых результатов, наличие измеримых показателей и соответствие документов установленным требованиям.'],
                            ['icon_svg' => 'chart-bar', 'title' => 'Мониторинг и оценка', 'text' => 'После утверждения стратегии работа не заканчивается. Агентство участвует в мониторинге достижения целевых показателей, анализирует результаты реализации стратегических документов и при необходимости готовит предложения по совершенствованию механизмов их исполнения.'],
                        ],
                    ]],
                    ['text', 'От стратегии — к реальным изменениям', [
                        'variant' => 'system',
                        'title' => 'От стратегии — к реальным изменениям',
                        'content' => '<p>Агентство не подменяет министерства, ведомства и органы власти на местах, которые непосредственно отвечают за реализацию государственной политики в своих сферах.</p>'
                            . '<p>Его роль заключается в другом — создать целостную систему стратегического управления.</p>'
                            . '<p>Такой подход помогает связать долгосрочное видение развития страны с конкретной ежедневной работой государственных органов.</p>',
                        'aside_title' => 'Целостная система стратегического управления',
                        'items' => [
                            ['icon_svg' => 'target', 'title' => 'Чётко сформулированные цели'],
                            ['icon_svg' => 'network', 'title' => 'Согласованные приоритеты'],
                            ['icon_svg' => 'route', 'title' => 'Конкретные действия'],
                            ['icon_svg' => 'chart-bar', 'title' => 'Измеримые показатели'],
                            ['icon_svg' => 'calendar-check', 'title' => 'Регулярный мониторинг'],
                            ['icon_svg' => 'database', 'title' => 'Решения на основе данных'],
                        ],
                        'quote' => '',
                    ]],
                    ['stages', 'Этапы развития', [
                        '_reveal' => ['enabled' => true, 'type' => 'stagger'],
                        'variant' => 'history',
                        'title' => 'Как развивалось Агентство',
                        'description' => '<p>Современная модель Агентства сформировалась в результате последовательного развития системы стратегического управления в Узбекистане.</p>',
                        'all_text' => '',
                        'all_url' => '',
                        'items' => [
                            ['year' => '2021', 'stage' => '', 'status' => 'done', 'status_text' => '', 'title' => 'Создание Агентства стратегического развития', 'text' => '19 июля 2021 года было создано Агентство стратегического развития Республики Узбекистан. На первоначальном этапе основное внимание уделялось инвестиционной привлекательности страны, конкурентоспособности отраслей и регионов, определению перспективных направлений инвестиций и сопровождению стратегически важных проектов.'],
                            ['year' => '2022', 'stage' => '', 'status' => 'done', 'status_text' => '', 'title' => 'Переход к комплексным реформам', 'text' => 'На базе Агентства стратегического развития было создано Агентство стратегических реформ при Президенте Республики Узбекистан. Его полномочия существенно расширились: разработка комплексных реформ, формирование экспертных и проектных групп, подготовка «дорожных карт», координация разработки необходимых решений, мониторинг и оценка результатов реформ.'],
                            ['year' => '2023', 'stage' => '', 'status' => 'done', 'status_text' => '', 'title' => 'Усиление аналитической функции', 'text' => 'Усилена роль Агентства как центра стратегического анализа и разработки перспективных инициатив. Особое внимание стало уделяться изучению системных проблем, международного опыта, исследований научных учреждений и рекомендаций международных организаций.'],
                            ['year' => '2025', 'stage' => '', 'status' => 'active', 'status_text' => '', 'title' => 'Единая система стратегического планирования', 'text' => '30 октября 2025 года в Узбекистане была создана единая система стратегического планирования и развития, основанная на принципе «цель — действие — результат». Агентство получило нынешнее название и статус уполномоченного государственного органа, а также стало рабочим органом Координационного совета по стратегическому планированию и развитию. В декабре 2025 года определены практические механизмы работы новой системы.'],
                        ],
                    ]],
                    ['text', 'Агентство сегодня', [
                        'variant' => 'spotlight',
                        'title' => 'Агентство сегодня',
                        'content' => '<p>Сегодня Агентство объединяет <strong>стратегическое планирование, анализ, координацию и оценку результатов</strong> в единую систему.</p>'
                            . '<p>Оно взаимодействует с министерствами и ведомствами, органами власти на местах, аналитическими и научно-исследовательскими организациями, экспертным сообществом и международными институтами.</p>'
                            . '<p>Одним из важных направлений деятельности является мониторинг реализации стратегических целей страны, включая показатели стратегии «Узбекистан — 2030».</p>'
                            . '<p>Главный ориентир этой работы — не количество разработанных документов, а их практическая результативность: насколько поставленные цели превращаются в последовательные действия и приводят к ощутимым изменениям.</p>',
                        'aside_title' => '',
                        'items' => [],
                        'quote' => 'Стратегическое планирование — это путь от долгосрочной цели к конкретному результату. Агентство помогает сделать этот путь системным, измеримым и согласованным.',
                    ]],
                    ['docs_list', 'Нормативно-правовые документы', [
                        '_reveal' => ['enabled' => true, 'type' => 'stagger'],
                        'variant' => 'acts-editorial',
                        'title' => 'Основные нормативно-правовые документы',
                        'all_text' => '',
                        'all_url' => '',
                        'columns' => 5,
                        'search_enabled' => false,
                        'items' => [
                            ['title' => 'Создание Агентства стратегического развития Республики Узбекистан', 'meta' => '', 'url' => 'https://lex.uz/uz/docs/5520880', 'number' => 'Указ Президента № ПФ-6264', 'date' => '19 июля 2021 года'],
                            ['title' => 'Создание Агентства стратегических реформ при Президенте Республики Узбекистан и расширение его функций в сфере разработки и сопровождения реформ', 'meta' => '', 'url' => 'https://lex.uz/uz/docs/6188707', 'number' => 'Указ Президента № ПФ-216', 'date' => '8 сентября 2022 года'],
                            ['title' => 'Дальнейшее совершенствование деятельности Агентства и усиление его аналитической и экспертной роли', 'meta' => '', 'url' => 'https://lex.uz/uz/docs/6656978', 'number' => 'Указ Президента № ПФ-190', 'date' => '8 ноября 2023 года'],
                            ['title' => 'Создание единой системы стратегического планирования и развития и формирование современной модели Агентства', 'meta' => '', 'url' => 'https://lex.uz/uz/docs/7806484', 'number' => 'Указ Президента № ПФ-201', 'date' => '30 октября 2025 года'],
                            ['title' => 'Определение механизмов разработки, реализации, мониторинга и оценки стратегических документов', 'meta' => '', 'url' => '', 'number' => 'Постановление Президента № ПҚ-394', 'date' => '29 декабря 2025 года'],
                        ],
                    ]],
                ],
            ],
            'uz' => [
                'title' => 'Agentlik haqida',
                'lead' => 'Strategiyadan — natijaga',
                'meta_title' => 'Agentlik haqida — Strategik rivojlanish va islohotlar agentligi',
                'meta_description' => 'O‘zbekiston Respublikasi Prezidenti huzuridagi Strategik rivojlanish va islohotlar agentligi — strategik rejalashtirish va rivojlanish sohasidagi vakolatli davlat organi.',
                'blocks' => [
                    ['text', 'Kirish qismi', [
                        'variant' => 'intro',
                        'title' => '',
                        'content' => '<p><strong>O‘zbekiston Respublikasi Prezidenti huzuridagi Strategik rivojlanish va islohotlar agentligi</strong> — strategik rejalashtirish va rivojlanish sohasidagi vakolatli davlat organi.</p>'
                            . '<p>Agentlik mamlakatning uzoq muddatli maqsadlarini aniq vazifalar, o‘lchanadigan ko‘rsatkichlar va amaliy natijalar bilan bog‘laydigan yagona strategik rejalashtirish tizimini shakllantirishga xizmat qiladi.</p>'
                            . '<p>Agentlikning asosiy vazifasi — davlat strategiyalari va dasturlarining o‘zaro uyg‘unligini ta’minlash, ularni sifatli tahlil asosida ishlab chiqishga ko‘maklashish, aniq maqsad va amalga oshirish mexanizmlarini belgilash hamda erishilgan natijalarni muntazam baholab borishdan iborat.</p>',
                        'aside_title' => '',
                        'items' => [
                            ['icon_svg' => 'target', 'title' => 'Maqsad'],
                            ['icon_svg' => 'flag', 'title' => 'Harakat'],
                            ['icon_svg' => 'chart-bar', 'title' => 'Natija'],
                        ],
                        'quote' => '',
                    ]],
                    ['advantages', 'Faoliyat yo‘nalishlari', [
                        '_reveal' => ['enabled' => true, 'type' => 'stagger'],
                        'variant' => 'indexed',
                        'title' => 'Agentlik nima bilan shug‘ullanadi?',
                        'description' => '<p>Agentlik strategik rejalashtirishning barcha bosqichlarida — vaziyatni tahlil qilish va tashabbuslarni ishlab chiqishdan tortib, ularning amalga oshirilishini monitoring qilish va natijadorligini baholashgacha ishtirok etadi.</p>',
                        'items' => [
                            ['icon_svg' => 'target', 'title' => 'Strategik rejalashtirish', 'text' => 'Agentlik strategik hujjatlarni ishlab chiqish bo‘yicha metodologik yondashuvlarni shakllantiradi hamda respublika, tarmoq va hududiy darajalarda yagona strategik rejalashtirish tizimini rivojlantirishga ko‘maklashadi.'],
                            ['icon_svg' => 'chart-line', 'title' => 'Tahlil va yangi tashabbuslar', 'text' => 'Ijtimoiy-iqtisodiy jarayonlar, rivojlanishdagi dolzarb masalalar, xalqaro tajriba, ilmiy tadqiqotlar va ekspertlar tavsiyalari o‘rganiladi. Ushbu tahlil asosida mamlakatni rivojlantirishning yangi yo‘nalishlari va tizimli islohotlar bo‘yicha takliflar ishlab chiqiladi.'],
                            ['icon_svg' => 'network', 'title' => 'Muvofiqlashtirish', 'text' => 'Agentlik vazirlik va idoralardagi strategik rejalashtirish bo‘linmalari, shuningdek, hududlardagi tegishli axborot-tahlil guruhlari faoliyatini muvofiqlashtiradi. Bu tarmoq va hududiy strategiyalarni mamlakatning umumiy ustuvor maqsadlari bilan uyg‘unlashtirish imkonini beradi.'],
                            ['icon_svg' => 'clipboard-check', 'title' => 'Strategik hujjatlar sifati', 'text' => 'Strategiyalar va boshqa strategik rejalashtirish hujjatlari loyihalari belgilangan tartibda sifat tekshiruvidan o‘tkaziladi. Bunda maqsadlarning asoslanganligi, chora-tadbirlar va kutilayotgan natijalar o‘rtasidagi bog‘liqlik, o‘lchanadigan ko‘rsatkichlarning mavjudligi hamda hujjatlarning belgilangan talablarga muvofiqligi baholanadi.'],
                            ['icon_svg' => 'chart-bar', 'title' => 'Monitoring va baholash', 'text' => 'Strategik hujjat tasdiqlanishi bilan ish yakunlanmaydi. Agentlik maqsadli ko‘rsatkichlarga erishishni monitoring qilishda ishtirok etadi, strategik hujjatlarning amalga oshirilishi natijalarini tahlil qiladi hamda zarur hollarda ularning ijro mexanizmlarini takomillashtirish bo‘yicha takliflar ishlab chiqadi.'],
                        ],
                    ]],
                    ['text', 'Strategiyadan — amaliy o‘zgarishlarga', [
                        'variant' => 'system',
                        'title' => 'Strategiyadan — amaliy o‘zgarishlarga',
                        'content' => '<p>Agentlik davlat siyosatini bevosita amalga oshirish uchun mas’ul bo‘lgan vazirliklar, idoralar va mahalliy ijro etuvchi hokimiyat organlarining vazifalarini o‘z zimmasiga olmaydi.</p>'
                            . '<p>Agentlikning roli — yaxlit strategik boshqaruv tizimini shakllantirish.</p>'
                            . '<p>Bu yondashuv mamlakatning uzoq muddatli rivojlanish maqsadlarini davlat organlarining kundalik faoliyati va aniq natijalar bilan bog‘lashga xizmat qiladi.</p>',
                        'aside_title' => 'Yaxlit strategik boshqaruv tizimi',
                        'items' => [
                            ['icon_svg' => 'target', 'title' => 'Aniq belgilangan maqsadlar'],
                            ['icon_svg' => 'network', 'title' => 'Muvofiqlashtirilgan ustuvor yo‘nalishlar'],
                            ['icon_svg' => 'route', 'title' => 'Aniq harakatlar'],
                            ['icon_svg' => 'chart-bar', 'title' => 'O‘lchanadigan ko‘rsatkichlar'],
                            ['icon_svg' => 'calendar-check', 'title' => 'Muntazam monitoring'],
                            ['icon_svg' => 'database', 'title' => 'Ma’lumotlarga asoslangan qarorlar'],
                        ],
                        'quote' => '',
                    ]],
                    ['stages', 'Rivojlanish bosqichlari', [
                        '_reveal' => ['enabled' => true, 'type' => 'stagger'],
                        'variant' => 'history',
                        'title' => 'Agentlikning rivojlanish tarixi',
                        'description' => '<p>Agentlikning bugungi modeli O‘zbekistonda strategik boshqaruv tizimini izchil takomillashtirish natijasida shakllandi.</p>',
                        'all_text' => '',
                        'all_url' => '',
                        'items' => [
                            ['year' => '2021', 'stage' => '', 'status' => 'done', 'status_text' => '', 'title' => 'Strategik rivojlanish agentligining tashkil etilishi', 'text' => '2021-yil 19-iyulda O‘zbekiston Respublikasi Strategik rivojlanish agentligi tashkil etildi. Dastlabki bosqichda asosiy e’tibor mamlakatning investitsiyaviy jozibadorligini oshirish, tarmoq va hududlarning raqobatbardoshligini tahlil qilish, istiqbolli investitsiya yo‘nalishlarini aniqlash hamda strategik ahamiyatga ega loyihalarni qo‘llab-quvvatlashga qaratildi.'],
                            ['year' => '2022', 'stage' => '', 'status' => 'done', 'status_text' => '', 'title' => 'Kompleks islohotlarga o‘tish', 'text' => 'Strategik rivojlanish agentligi negizida O‘zbekiston Respublikasi Prezidenti huzuridagi Strategik islohotlar agentligi tashkil etildi. Agentlikning vakolatlari sezilarli darajada kengaydi: kompleks islohotlarni ishlab chiqish, ekspert va loyiha guruhlarini shakllantirish, “yo‘l xaritalari”ni tayyorlash, qarorlar ishlab chiqilishini muvofiqlashtirish, islohotlar natijadorligini monitoring qilish.'],
                            ['year' => '2023', 'stage' => '', 'status' => 'done', 'status_text' => '', 'title' => 'Tahliliy salohiyatning kuchayishi', 'text' => 'Agentlikning strategik tahlil va istiqbolli tashabbuslarni ishlab chiqish markazi sifatidagi roli kuchaytirildi. Tizimli muammolar, xorijiy tajriba, ilmiy tadqiqotlar hamda xalqaro tashkilotlar va ekspertlar tavsiyalarini o‘rganishga alohida e’tibor qaratildi.'],
                            ['year' => '2025', 'stage' => '', 'status' => 'active', 'status_text' => '', 'title' => 'Yagona strategik rejalashtirish tizimi', 'text' => '2025-yil 30-oktabrda O‘zbekistonda “maqsad — harakat — natija” tamoyiliga asoslangan yagona strategik rejalashtirish va rivojlanish tizimi joriy etildi. Agentlik hozirgi nomini hamda strategik rejalashtirish tizimini tashkil etish bo‘yicha vakolatli davlat organi maqomini oldi va Muvofiqlashtiruvchi kengashning ishchi organi etib belgilandi. Dekabr oyida yangi tizimning amaliy mexanizmlari belgilandi.'],
                        ],
                    ]],
                    ['text', 'Agentlik bugun', [
                        'variant' => 'spotlight',
                        'title' => 'Agentlik bugun',
                        'content' => '<p>Bugungi kunda Agentlik <strong>strategik rejalashtirish, tahlil, muvofiqlashtirish va natijalarni baholashni</strong> yagona tizimda birlashtiradi.</p>'
                            . '<p>Agentlik vazirlik va idoralar, mahalliy ijro etuvchi hokimiyat organlari, tahliliy va ilmiy-tadqiqot tashkilotlari, ekspertlar hamjamiyati hamda xalqaro institutlar bilan hamkorlik qiladi.</p>'
                            . '<p>Faoliyatning muhim yo‘nalishlaridan biri mamlakat strategik maqsadlari, jumladan, “O‘zbekiston — 2030” strategiyasi ko‘rsatkichlarining amalga oshirilishini monitoring qilishdan iborat.</p>'
                            . '<p>Agentlik faoliyatining asosiy mezoni ishlab chiqilgan hujjatlar soni emas, balki ularning amaliy natijadorligidir — belgilangan maqsadlar qay darajada aniq harakatlarga, harakatlar esa fuqarolar va mamlakat taraqqiyoti uchun sezilarli natijalarga olib kelishi.</p>',
                        'aside_title' => '',
                        'items' => [],
                        'quote' => 'Strategik rejalashtirish — uzoq muddatli maqsaddan aniq natijaga olib boruvchi yo‘ldir. Agentlik ushbu yo‘lning tizimli, o‘lchanadigan va o‘zaro muvofiqlashtirilgan bo‘lishiga xizmat qiladi.',
                    ]],
                    ['docs_list', 'Normativ-huquqiy hujjatlar', [
                        '_reveal' => ['enabled' => true, 'type' => 'stagger'],
                        'variant' => 'acts-editorial',
                        'title' => 'Asosiy normativ-huquqiy hujjatlar',
                        'all_text' => '',
                        'all_url' => '',
                        'columns' => 5,
                        'search_enabled' => false,
                        'items' => [
                            ['title' => 'O‘zbekiston Respublikasi Strategik rivojlanish agentligining tashkil etilishi', 'meta' => '', 'url' => 'https://lex.uz/uz/docs/5520880', 'number' => 'PF-6264-son Farmon', 'date' => '2021-yil 19-iyul'],
                            ['title' => 'Strategik islohotlar agentligining tashkil etilishi hamda islohotlarni ishlab chiqish va muvofiqlashtirish bo‘yicha vakolatlarning kengaytirilishi', 'meta' => '', 'url' => 'https://lex.uz/uz/docs/6188707', 'number' => 'PF-216-son Farmon', 'date' => '2022-yil 8-sentabr'],
                            ['title' => 'Agentlik faoliyatini yanada takomillashtirish, uning tahliliy va ekspertlik salohiyatini kuchaytirish', 'meta' => '', 'url' => 'https://lex.uz/uz/docs/6656978', 'number' => 'PF-190-son Farmon', 'date' => '2023-yil 8-noyabr'],
                            ['title' => 'Yagona strategik rejalashtirish va rivojlanish tizimining joriy etilishi va Agentlikning zamonaviy modelini shakllantirish', 'meta' => '', 'url' => 'https://lex.uz/uz/docs/7806484', 'number' => 'PF-201-son Farmon', 'date' => '2025-yil 30-oktabr'],
                            ['title' => 'Strategik hujjatlarni ishlab chiqish, amalga oshirish, monitoring qilish va baholash mexanizmlarini belgilash', 'meta' => '', 'url' => '', 'number' => 'PQ-394-son qaror', 'date' => '2025-yil 29-dekabr'],
                        ],
                    ]],
                ],
            ],
            'en' => [
                'title' => 'About the Agency',
                'lead' => 'From Strategy to Results',
                'meta_title' => 'About the Agency — Agency for Strategic Development and Reforms',
                'meta_description' => 'The Agency for Strategic Development and Reforms under the President of the Republic of Uzbekistan is the authorized government body responsible for strategic planning and development.',
                'blocks' => [
                    ['text', 'Introduction', [
                        'variant' => 'intro',
                        'title' => '',
                        'content' => '<p>The <strong>Agency for Strategic Development and Reforms under the President of the Republic of Uzbekistan</strong> is the authorized government body responsible for strategic planning and development.</p>'
                            . '<p>The Agency contributes to building an integrated system that translates the country’s long-term priorities into concrete actions, measurable indicators and tangible results.</p>'
                            . '<p>Its core mission is to help ensure that national strategies and programmes are aligned, based on sound analysis, supported by clear objectives and implementation mechanisms, and regularly assessed against the results achieved.</p>',
                        'aside_title' => '',
                        'items' => [
                            ['icon_svg' => 'target', 'title' => 'Goal'],
                            ['icon_svg' => 'flag', 'title' => 'Action'],
                            ['icon_svg' => 'chart-bar', 'title' => 'Result'],
                        ],
                        'quote' => '',
                    ]],
                    ['advantages', 'Areas of work', [
                        '_reveal' => ['enabled' => true, 'type' => 'stagger'],
                        'variant' => 'indexed',
                        'title' => 'What the Agency Does',
                        'description' => '<p>The Agency is involved throughout the strategic planning cycle — from analysis and the development of new initiatives to implementation monitoring and evaluation of results.</p>',
                        'items' => [
                            ['icon_svg' => 'target', 'title' => 'Strategic Planning', 'text' => 'The Agency develops methodological approaches for the preparation of strategic planning documents and supports the establishment of an integrated strategic planning system at national, sectoral and regional levels.'],
                            ['icon_svg' => 'chart-line', 'title' => 'Analysis and Development of Initiatives', 'text' => 'The Agency studies socio-economic developments, key development challenges, international experience, research findings and recommendations from the expert community. Based on this analysis, proposals are developed for new development priorities and systemic reforms.'],
                            ['icon_svg' => 'network', 'title' => 'Coordination', 'text' => 'The Agency coordinates the activities of strategic planning units within ministries and government agencies, as well as the relevant information and analytical groups in the regions. This helps align sectoral and regional strategies with the country’s overarching national priorities.'],
                            ['icon_svg' => 'clipboard-check', 'title' => 'Quality of Strategic Documents', 'text' => 'Draft strategies and other strategic planning documents undergo the quality review procedures established within the strategic planning system. The review considers whether objectives are well-founded, whether planned actions are linked to expected outcomes, whether measurable indicators have been established, and whether the documents comply with the applicable requirements.'],
                            ['icon_svg' => 'chart-bar', 'title' => 'Monitoring and Evaluation', 'text' => 'The strategic planning process does not end once a strategy has been approved. The Agency participates in monitoring progress towards target indicators, analyses the results of strategic document implementation and, where necessary, develops proposals to improve implementation mechanisms.'],
                        ],
                    ]],
                    ['text', 'Turning strategies into real change', [
                        'variant' => 'system',
                        'title' => 'Turning Strategies into Real Change',
                        'content' => '<p>The Agency does not replace ministries, government agencies or local executive authorities that are directly responsible for implementing public policy in their respective areas.</p>'
                            . '<p>Its role is to help build an integrated system of strategic governance.</p>'
                            . '<p>This approach connects the country’s long-term development vision with the day-to-day activities of public authorities and measurable outcomes.</p>',
                        'aside_title' => 'An Integrated System of Strategic Governance',
                        'items' => [
                            ['icon_svg' => 'target', 'title' => 'Clearly defined goals'],
                            ['icon_svg' => 'network', 'title' => 'Aligned priorities'],
                            ['icon_svg' => 'route', 'title' => 'Concrete actions'],
                            ['icon_svg' => 'chart-bar', 'title' => 'Measurable indicators'],
                            ['icon_svg' => 'calendar-check', 'title' => 'Regular monitoring'],
                            ['icon_svg' => 'database', 'title' => 'Data-informed decisions'],
                        ],
                        'quote' => '',
                    ]],
                    ['stages', 'Development stages', [
                        '_reveal' => ['enabled' => true, 'type' => 'stagger'],
                        'variant' => 'history',
                        'title' => 'The Agency’s Development',
                        'description' => '<p>The Agency’s present-day model is the result of the gradual development of Uzbekistan’s strategic governance system.</p>',
                        'all_text' => '',
                        'all_url' => '',
                        'items' => [
                            ['year' => '2021', 'stage' => '', 'status' => 'done', 'status_text' => '', 'title' => 'Establishment of the Strategic Development Agency', 'text' => 'On 19 July 2021, the Strategic Development Agency of the Republic of Uzbekistan was established. At the initial stage, its work focused primarily on improving the country’s investment attractiveness, assessing the competitiveness of sectors and regions, identifying promising investment opportunities and supporting strategically important projects.'],
                            ['year' => '2022', 'stage' => '', 'status' => 'done', 'status_text' => '', 'title' => 'Transition to Comprehensive Reforms', 'text' => 'The Agency for Strategic Reforms under the President of the Republic of Uzbekistan was established on the basis of the Strategic Development Agency. Its mandate was significantly expanded: designing comprehensive reforms, establishing expert and project groups, preparing roadmaps, coordinating the development of relevant decisions, and monitoring and evaluating reform implementation.'],
                            ['year' => '2023', 'stage' => '', 'status' => 'done', 'status_text' => '', 'title' => 'Strengthening the Analytical Function', 'text' => 'The next stage strengthened the Agency’s role as a centre for strategic analysis and the development of forward-looking initiatives. Greater emphasis was placed on identifying systemic challenges, studying international reform experience, analysing research findings and considering recommendations from international organisations and experts.'],
                            ['year' => '2025', 'stage' => '', 'status' => 'active', 'status_text' => '', 'title' => 'An Integrated Strategic Planning System', 'text' => 'On 30 October 2025, Uzbekistan introduced an integrated strategic planning and development system based on the principle of “Goal — Action — Result.” The Agency received its current name and became the authorized government body responsible for organizing the strategic planning system, as well as the working body of the Coordination Council for Strategic Planning and Development. In December 2025, the practical mechanisms of the new system were established.'],
                        ],
                    ]],
                    ['text', 'The Agency today', [
                        'variant' => 'spotlight',
                        'title' => 'The Agency Today',
                        'content' => '<p>Today, the Agency brings together <strong>strategic planning, analysis, coordination and results evaluation</strong> within a single system.</p>'
                            . '<p>It works with ministries and government agencies, local executive authorities, analytical and research institutions, the expert community and international organisations.</p>'
                            . '<p>One of its important areas of work is monitoring the implementation of the country’s strategic objectives, including the indicators of the Uzbekistan — 2030 Strategy.</p>'
                            . '<p>The ultimate measure of the Agency’s work is not the number of documents produced, but their practical impact — the extent to which strategic objectives are translated into concrete actions and those actions lead to tangible outcomes for the country and its people.</p>',
                        'aside_title' => '',
                        'items' => [],
                        'quote' => 'Strategic planning is the path from a long-term objective to a concrete result. The Agency helps make this path systematic, measurable and coordinated.',
                    ]],
                    ['docs_list', 'Legal documents', [
                        '_reveal' => ['enabled' => true, 'type' => 'stagger'],
                        'variant' => 'acts-editorial',
                        'title' => 'Key Legal and Regulatory Documents',
                        'all_text' => '',
                        'all_url' => '',
                        'columns' => 5,
                        'search_enabled' => false,
                        'items' => [
                            ['title' => 'Establishment of the Strategic Development Agency of the Republic of Uzbekistan', 'meta' => '', 'url' => 'https://lex.uz/uz/docs/5520880', 'number' => 'Presidential Decree No. PF-6264', 'date' => '19 July 2021'],
                            ['title' => 'Establishment of the Agency for Strategic Reforms under the President of the Republic of Uzbekistan and expansion of its mandate in the development and coordination of reforms', 'meta' => '', 'url' => 'https://lex.uz/uz/docs/6188707', 'number' => 'Presidential Decree No. PF-216', 'date' => '8 September 2022'],
                            ['title' => 'Further improvement of the Agency’s activities and strengthening of its analytical and expert functions', 'meta' => '', 'url' => 'https://lex.uz/uz/docs/6656978', 'number' => 'Presidential Decree No. PF-190', 'date' => '8 November 2023'],
                            ['title' => 'Introduction of the integrated strategic planning and development system and establishment of the Agency’s current institutional model', 'meta' => '', 'url' => 'https://lex.uz/uz/docs/7806484', 'number' => 'Presidential Decree No. PF-201', 'date' => '30 October 2025'],
                            ['title' => 'Establishment of mechanisms for the preparation, implementation, monitoring and evaluation of strategic planning documents', 'meta' => '', 'url' => '', 'number' => 'Presidential Resolution No. PQ-394', 'date' => '29 December 2025'],
                        ],
                    ]],
                ],
            ],
        ],

        // -------------------------------------------------------------------
        // Руководство — карточки с переходом на профили
        // -------------------------------------------------------------------
        'rukovodstvo' => [
            'ru' => [
                'title' => 'Руководство',
                'lead' => 'Руководство Агентства стратегического развития и реформ',
                'meta_title' => 'Руководство — Агентство стратегического развития и реформ',
                'meta_description' => 'Директор и первый заместитель директора Агентства стратегического развития и реформ при Президенте Республики Узбекистан.',
                'blocks' => [
                    ['person_cards', 'Карточки руководителей', [
                        '_reveal' => ['enabled' => true, 'type' => 'stagger'],
                        'title' => '',
                        'all_text' => '',
                        'all_url' => '',
                        'items' => [
                            ['photo' => '', 'name' => 'Умурзаков Сардор Уктамович', 'role' => 'Директор Агентства', 'url' => '/direktor'],
                            ['photo' => '', 'name' => 'Абдукодиров Абдулла Мамасаатович', 'role' => 'Первый заместитель директора Агентства', 'url' => '/pervyy-zamestitel-direktora'],
                            // Карточка без имени и фото выводится как вакансия
                            // (пунктирная рамка и подпись «Вакантно»).
                            ['photo' => '', 'name' => '', 'role' => 'Заместитель директора Агентства', 'url' => ''],
                            ['photo' => '', 'name' => '', 'role' => 'Заместитель директора Агентства', 'url' => ''],
                        ],
                    ]],
                ],
            ],
            'uz' => [
                'title' => 'Rahbariyat',
                'lead' => 'Strategik rivojlanish va islohotlar agentligi rahbariyati',
                'meta_title' => 'Rahbariyat — Strategik rivojlanish va islohotlar agentligi',
                'meta_description' => 'O‘zbekiston Respublikasi Prezidenti huzuridagi Strategik rivojlanish va islohotlar agentligi direktori va direktorining birinchi o‘rinbosari.',
                'blocks' => [
                    ['person_cards', 'Rahbarlar kartochkalari', [
                        '_reveal' => ['enabled' => true, 'type' => 'stagger'],
                        'title' => '',
                        'all_text' => '',
                        'all_url' => '',
                        'items' => [
                            ['photo' => '', 'name' => 'Umurzoqov Sardor O‘ktamovich', 'role' => 'Agentlik direktori', 'url' => '/uz/direktor'],
                            ['photo' => '', 'name' => 'Abduqodirov Abdulla Mamasaatovich', 'role' => 'Agentlik direktorining birinchi o‘rinbosari', 'url' => '/uz/pervyy-zamestitel-direktora'],
                            ['photo' => '', 'name' => '', 'role' => 'Agentlik direktorining o‘rinbosari', 'url' => ''],
                            ['photo' => '', 'name' => '', 'role' => 'Agentlik direktorining o‘rinbosari', 'url' => ''],
                        ],
                    ]],
                ],
            ],
        ],

        // -------------------------------------------------------------------
        // Директор
        // -------------------------------------------------------------------
        'direktor' => [
            'ru' => [
                'title' => 'Директор',
                'lead' => '',
                'meta_title' => 'Директор Агентства — Умурзаков Сардор Уктамович',
                'meta_description' => 'Умурзаков Сардор Уктамович — директор Агентства стратегического развития и реформ при Президенте Республики Узбекистан.',
                'blocks' => [
                    ['person_profile', 'Профиль директора', [
                        'photo' => '',
                        'name' => 'Умурзаков Сардор Уктамович',
                        'position' => 'Директор Агентства стратегического развития и реформ при Президенте Республики Узбекистан',
                        'text' => 'Обладает многолетним опытом работы в сфере государственного управления, инвестиций, международных финансов, внешнеэкономических связей и стратегического развития.',
                        'phone' => '',
                        'phone_label' => 'Приёмная:',
                        'email' => '',
                        'email_label' => 'E-mail:',
                        'button_text' => '',
                        'button_url' => '',
                    ]],
                    ['bio_education', 'Биография и образование', [
                        'bio_title' => 'Биография',
                        'bio_text' => "Родился 23 ноября 1977 года в городе Ташкенте.\n\n"
                            . "Окончил Ташкентский государственный экономический университет, получив степень бакалавра, а также магистратуру Университета Рединга в Великобритании.\n\n"
                            . "Профессиональную деятельность начал в системе Национального банка внешнеэкономической деятельности Республики Узбекистан. В последующие годы работал в Европейском банке реконструкции и развития в Лондоне, занимал руководящие должности в системе Казначейства Министерства финансов Республики Узбекистан.\n\n"
                            . "Работал в различных сферах государственного управления, инвестиционной и внешнеэкономической политики. В разные годы возглавлял ассоциацию «Узчармсаноат», занимал должности министра инвестиций и внешней торговли, заместителя Премьер-министра Республики Узбекистан, руководителя Администрации Президента Республики Узбекистан, советника Президента по особым поручениям и советника Президента по стратегическому развитию.\n\n"
                            . 'С 10 июля 2026 года — директор Агентства стратегического развития и реформ при Президенте Республики Узбекистан.',
                        'career_title' => 'Профессиональный путь',
                        // Колонка «Годы» узкая (110px) — точные даты живут в
                        // тексте должности, иначе строка выезжает на неё.
                        'career' => [
                            ['years' => '2000–2003', 'text' => 'Национальный банк внешнеэкономической деятельности Республики Узбекистан — головной офис банка и Самаркандское управление.'],
                            ['years' => '2003–2007', 'text' => 'Европейский банк реконструкции и развития, Лондон (с ноября 2003 года).'],
                            ['years' => '2007–2013', 'text' => 'Казначейство Министерства финансов Республики Узбекистан — заместитель руководителя, первый заместитель руководителя, начальник Ташкентского городского управления Казначейства.'],
                            ['years' => '2014–2017', 'text' => 'Советник председателя ассоциации «Узбекчармпойабзали».'],
                            ['years' => '2017–2018', 'text' => 'Заместитель председателя ассоциации «Узбекчармпойабзали».'],
                            ['years' => '2018–2019', 'text' => 'Председатель ассоциации «Узчармсаноат».'],
                            ['years' => 'с 2019', 'text' => 'Министр инвестиций и внешней торговли Республики Узбекистан (с 28 января 2019 года).'],
                            ['years' => 'с 2020', 'text' => 'Заместитель Премьер-министра Республики Узбекистан по вопросам инвестиций и внешнеэкономических связей — министр инвестиций и внешней торговли Республики Узбекистан (с 10 марта 2020 года).'],
                            ['years' => 'с 2022', 'text' => 'Руководитель Администрации Президента Республики Узбекистан (с 16 июля 2022 года).'],
                            ['years' => 'с 2023', 'text' => 'Советник Президента Республики Узбекистан по особым поручениям (с 17 августа 2023 года).'],
                            ['years' => 'с 2025', 'text' => 'Советник Президента Республики Узбекистан по стратегическому развитию (с 14 июля 2025 года).'],
                            ['years' => '2026 — н.в.', 'text' => 'Директор Агентства стратегического развития и реформ при Президенте Республики Узбекистан (с 10 июля 2026 года).'],
                        ],
                        'edu_title' => 'Образование',
                        'edu_items' => [
                            ['years' => '', 'title' => 'Бакалавриат', 'org' => 'Ташкентский государственный экономический университет'],
                            ['years' => '', 'title' => 'Магистратура', 'org' => 'Университет Рединга, Великобритания'],
                        ],
                        'extra_title' => '',
                        'extra_text' => '',
                        'quote_text' => '',
                        'quote_author' => '',
                    ]],
                ],
            ],
            'uz' => [
                'title' => 'Direktor',
                'lead' => '',
                'meta_title' => 'Agentlik direktori — Umurzoqov Sardor O‘ktamovich',
                'meta_description' => 'Umurzoqov Sardor O‘ktamovich — O‘zbekiston Respublikasi Prezidenti huzuridagi Strategik rivojlanish va islohotlar agentligi direktori.',
                'blocks' => [
                    ['person_profile', 'Direktor profili', [
                        'photo' => '',
                        'name' => 'Umurzoqov Sardor O‘ktamovich',
                        'position' => 'O‘zbekiston Respublikasi Prezidenti huzuridagi Strategik rivojlanish va islohotlar agentligi direktori',
                        'text' => 'Davlat boshqaruvi, investitsiyalar, xalqaro moliya, tashqi iqtisodiy aloqalar va strategik rivojlanish sohalarida ko‘p yillik tajribaga ega.',
                        'phone' => '',
                        'phone_label' => 'Qabulxona:',
                        'email' => '',
                        'email_label' => 'E-pochta:',
                        'button_text' => '',
                        'button_url' => '',
                    ]],
                    ['bio_education', 'Tarjimai hol va ta’lim', [
                        'bio_title' => 'Tarjimai hol',
                        'bio_text' => "1977-yil 23-noyabrda Toshkent shahrida tug‘ilgan.\n\n"
                            . "Toshkent davlat iqtisodiyot universitetini bakalavr darajasi bilan tamomlagan. Shuningdek, Buyuk Britaniyaning Reading universitetida magistr darajasini olgan.\n\n"
                            . "Mehnat faoliyatini O‘zbekiston Respublikasi Tashqi iqtisodiy faoliyat milliy banki tizimida boshlagan. Keyingi yillarda Londondagi Yevropa tiklanish va taraqqiyot bankida ishlagan, O‘zbekiston Respublikasi Moliya vazirligi G‘aznachiligi tizimida rahbarlik lavozimlarida faoliyat yuritgan.\n\n"
                            . "Turli yillarda “O‘zcharmsanoat” uyushmasi raisi, investitsiyalar va tashqi savdo vaziri, Bosh vazirning o‘rinbosari, Prezident Administratsiyasi rahbari, Prezidentning maxsus topshiriqlar bo‘yicha maslahatchisi hamda strategik rivojlanish bo‘yicha maslahatchisi lavozimlarida faoliyat yuritgan.\n\n"
                            . '2026-yil 10-iyuldan O‘zbekiston Respublikasi Prezidenti huzuridagi Strategik rivojlanish va islohotlar agentligi direktori.',
                        'career_title' => 'Kasbiy faoliyati',
                        'career' => [
                            ['years' => '2000–2003', 'text' => 'O‘zbekiston Respublikasi Tashqi iqtisodiy faoliyat milliy banki — bosh ofis va Samarqand hududiy bo‘linmasi.'],
                            ['years' => '2003–2007', 'text' => 'Londondagi Yevropa tiklanish va taraqqiyot banki (2003-yil noyabridan).'],
                            ['years' => '2007–2013', 'text' => 'Moliya vazirligi G‘aznachiligi — rahbar o‘rinbosari, birinchi o‘rinbosar, Toshkent shahar G‘aznachilik boshqarmasi boshlig‘i.'],
                            ['years' => '2014–2017', 'text' => '“O‘zbekcharmpoyabzali” uyushmasi raisining maslahatchisi.'],
                            ['years' => '2017–2018', 'text' => '“O‘zbekcharmpoyabzali” uyushmasi raisining o‘rinbosari.'],
                            ['years' => '2018–2019', 'text' => '“O‘zcharmsanoat” uyushmasi raisi.'],
                            ['years' => '2019-yildan', 'text' => 'O‘zbekiston Respublikasi investitsiyalar va tashqi savdo vaziri (28-yanvardan).'],
                            ['years' => '2020-yildan', 'text' => 'Bosh vazirning investitsiyalar va tashqi iqtisodiy aloqalar masalalari bo‘yicha o‘rinbosari — investitsiyalar va tashqi savdo vaziri (10-martdan).'],
                            ['years' => '2022-yildan', 'text' => 'O‘zbekiston Respublikasi Prezidenti Administratsiyasi rahbari (16-iyuldan).'],
                            ['years' => '2023-yildan', 'text' => 'O‘zbekiston Respublikasi Prezidentining maxsus topshiriqlar bo‘yicha maslahatchisi (17-avgustdan).'],
                            ['years' => '2025-yildan', 'text' => 'O‘zbekiston Respublikasi Prezidentining strategik rivojlanish bo‘yicha maslahatchisi (14-iyuldan).'],
                            ['years' => '2026-yildan', 'text' => 'O‘zbekiston Respublikasi Prezidenti huzuridagi Strategik rivojlanish va islohotlar agentligi direktori (10-iyuldan — hozirgi vaqtga qadar).'],
                        ],
                        'edu_title' => 'Ta’lim',
                        'edu_items' => [
                            ['years' => '', 'title' => 'Bakalavr', 'org' => 'Toshkent davlat iqtisodiyot universiteti'],
                            ['years' => '', 'title' => 'Magistr', 'org' => 'Reading universiteti, Buyuk Britaniya'],
                        ],
                        'extra_title' => '',
                        'extra_text' => '',
                        'quote_text' => '',
                        'quote_author' => '',
                    ]],
                ],
            ],
        ],

        // -------------------------------------------------------------------
        // Первый заместитель директора
        // -------------------------------------------------------------------
        'pervyy-zamestitel-direktora' => [
            'ru' => [
                'title' => 'Первый заместитель директора',
                'lead' => '',
                'meta_title' => 'Первый заместитель директора — Абдукодиров Абдулла Мамасаатович',
                'meta_description' => 'Абдукодиров Абдулла Мамасаатович — первый заместитель директора Агентства стратегического развития и реформ при Президенте Республики Узбекистан.',
                'blocks' => [
                    ['person_profile', 'Профиль первого заместителя', [
                        'photo' => '',
                        'name' => 'Абдукодиров Абдулла Мамасаатович',
                        'position' => 'Первый заместитель директора Агентства стратегического развития и реформ при Президенте Республики Узбекистан',
                        'text' => 'Экономист, доктор экономических наук. Имеет многолетний опыт работы в сфере государственного управления, государственных финансов, развития финансового рынка, банковского сектора, инвестиций и стратегических реформ.',
                        'phone' => '',
                        'phone_label' => 'Приёмная:',
                        'email' => '',
                        'email_label' => 'E-mail:',
                        'button_text' => '',
                        'button_url' => '',
                    ]],
                    ['bio_education', 'Биография и образование', [
                        'bio_title' => 'Биография',
                        'bio_text' => "Родился 19 февраля 1961 года в городе Ташкенте.\n\n"
                            . "В 1982 году окончил Ташкентский институт народного хозяйства.\n\n"
                            . "Трудовую деятельность начал в Институте экономики Академии наук Узбекистана. В последующие годы занимал руководящие должности в Министерстве финансов Республики Узбекистан, пройдя путь от заместителя начальника отдела до первого заместителя министра.\n\n"
                            . "Работал в системе управления государственным имуществом и развития рынка ценных бумаг, банковском и частном секторах. Занимал руководящие должности в узбекском подразделении ABN AMRO Bank N.V., возглавлял компании Smart Heating Systems и Double A Solutions, входил в Наблюдательный совет АКБ «Infinbank».\n\n"
                            . "В 2020–2022 годах являлся советником министра инвестиций и внешней торговли Республики Узбекистан.\n\n"
                            . "В декабре 2022 года был назначен первым заместителем генерального директора Агентства стратегических реформ при Президенте Республики Узбекистан. 12 мая 2026 года назначен исполняющим обязанности директора Агентства стратегического развития и реформ, 10 июля 2026 года освобождён от этой должности в связи с переходом на другую работу.\n\n"
                            . 'С 16 июля 2026 года — первый заместитель директора Агентства стратегического развития и реформ при Президенте Республики Узбекистан.',
                        'career' => [
                            ['years' => '1987–1990', 'text' => 'Научный сотрудник Института экономики Академии наук Узбекистана.'],
                            ['years' => '1990–1997', 'text' => 'Министерство финансов Республики Узбекистан — заместитель начальника отдела, начальник управления, заместитель министра, первый заместитель министра.'],
                            ['years' => '1996–2000', 'text' => 'Первый заместитель председателя Государственного комитета Республики Узбекистан по управлению государственным имуществом; генеральный директор Центра по развитию и совершенствованию рынка ценных бумаг.'],
                            ['years' => '2000–2004', 'text' => 'Филиал ABN AMRO Bank N.V. Netherlands в Узбекистане — начальник казначейства, заместитель председателя правления.'],
                            ['years' => '2005–2013', 'text' => 'Генеральный директор ООО «Smart Heating Systems».'],
                            ['years' => '2008–2013', 'text' => 'Член Наблюдательного совета АКБ «Infinbank».'],
                            ['years' => '2015–2022', 'text' => 'Генеральный директор ООО «Double A Solutions», Ташкент.'],
                            ['years' => '2020–2022', 'text' => 'Советник министра инвестиций и внешней торговли Республики Узбекистан.'],
                            ['years' => 'с 2022', 'text' => 'Первый заместитель генерального директора Агентства стратегических реформ при Президенте Республики Узбекистан (с 24 декабря 2022 года).'],
                            ['years' => '2026', 'text' => 'Исполняющий обязанности директора Агентства стратегического развития и реформ при Президенте Республики Узбекистан (12 мая — 10 июля 2026 года).'],
                            ['years' => '2026 — н.в.', 'text' => 'Первый заместитель директора Агентства стратегического развития и реформ при Президенте Республики Узбекистан (с 16 июля 2026 года).'],
                        ],
                        'edu_title' => 'Образование',
                        'edu_items' => [
                            ['years' => '1982', 'title' => 'Высшее образование', 'org' => 'Ташкентский институт народного хозяйства'],
                        ],
                        'extra_title' => 'Учёная степень',
                        'extra_text' => 'Доктор экономических наук',
                        'quote_text' => '',
                        'quote_author' => '',
                    ]],
                ],
            ],
            'uz' => [
                'title' => 'Direktorning birinchi o‘rinbosari',
                'lead' => '',
                'meta_title' => 'Direktorning birinchi o‘rinbosari — Abduqodirov Abdulla Mamasaatovich',
                'meta_description' => 'Abduqodirov Abdulla Mamasaatovich — O‘zbekiston Respublikasi Prezidenti huzuridagi Strategik rivojlanish va islohotlar agentligi direktorining birinchi o‘rinbosari.',
                'blocks' => [
                    ['person_profile', 'Birinchi o‘rinbosar profili', [
                        'photo' => '',
                        'name' => 'Abduqodirov Abdulla Mamasaatovich',
                        'position' => 'O‘zbekiston Respublikasi Prezidenti huzuridagi Strategik rivojlanish va islohotlar agentligi direktorining birinchi o‘rinbosari',
                        'text' => 'Iqtisodchi, iqtisodiyot fanlari doktori. Davlat boshqaruvi, davlat moliyasi, moliya bozori, bank sektori, investitsiyalar va strategik islohotlar sohalarida ko‘p yillik tajribaga ega.',
                        'phone' => '',
                        'phone_label' => 'Qabulxona:',
                        'email' => '',
                        'email_label' => 'E-pochta:',
                        'button_text' => '',
                        'button_url' => '',
                    ]],
                    ['bio_education', 'Tarjimai hol va ta’lim', [
                        'bio_title' => 'Tarjimai hol',
                        'bio_text' => "1961-yil 19-fevralda Toshkent shahrida tug‘ilgan.\n\n"
                            . "1982-yilda Toshkent Xalq xo‘jaligi institutini tamomlagan.\n\n"
                            . "Mehnat faoliyatini O‘zbekiston Fanlar akademiyasi Iqtisod institutida ilmiy xodim sifatida boshlagan. Keyingi yillarda Moliya vazirligida bo‘lim boshlig‘i o‘rinbosaridan vazirning birinchi o‘rinbosari lavozimigacha bo‘lgan mehnat yo‘lini bosib o‘tgan.\n\n"
                            . "Davlat mulkini boshqarish va qimmatli qog‘ozlar bozorini rivojlantirish tizimida, shuningdek, bank va xususiy sektorda rahbarlik lavozimlarida ishlagan. ABN AMRO Bank N.V.ning O‘zbekistondagi filialida, “Smart Heating Systems”, “Double A Solutions” kompaniyalarida faoliyat yuritgan hamda “Infinbank” kuzatuv kengashi a’zosi bo‘lgan.\n\n"
                            . "2020–2022-yillarda investitsiyalar va tashqi savdo vazirining maslahatchisi lavozimida faoliyat yuritgan.\n\n"
                            . "2022-yil dekabrida Strategik islohotlar agentligi bosh direktorining birinchi o‘rinbosari etib tayinlangan. 2026-yil 12-mayda Strategik rivojlanish va islohotlar agentligi direktori vazifasini bajaruvchi etib tayinlangan, 2026-yil 10-iyulda boshqa ishga o‘tishi munosabati bilan ushbu lavozimdan ozod etilgan.\n\n"
                            . '2026-yil 16-iyuldan O‘zbekiston Respublikasi Prezidenti huzuridagi Strategik rivojlanish va islohotlar agentligi direktorining birinchi o‘rinbosari.',
                        'career' => [
                            ['years' => '1987–1990', 'text' => 'O‘zbekiston Fanlar akademiyasi Iqtisod instituti — ilmiy xodim.'],
                            ['years' => '1990–1997', 'text' => 'O‘zbekiston Respublikasi Moliya vazirligi — bo‘lim boshlig‘i o‘rinbosari, boshqarma boshlig‘i, vazir o‘rinbosari, vazirning birinchi o‘rinbosari.'],
                            ['years' => '1996–2000', 'text' => 'Davlat mulk qo‘mitasi raisining birinchi o‘rinbosari; Qimmatli qog‘ozlar bozorini rivojlantirish va takomillashtirish markazi bosh direktori.'],
                            ['years' => '2000–2004', 'text' => 'ABN AMRO Bank N.V. Netherlands bankining O‘zbekistondagi filiali — g‘aznachilik boshlig‘i, boshqaruv raisi o‘rinbosari.'],
                            ['years' => '2005–2013', 'text' => '“Smart Heating Systems” MCHJ bosh direktori.'],
                            ['years' => '2008–2013', 'text' => '“Infinbank” kuzatuv kengashi a’zosi.'],
                            ['years' => '2015–2022', 'text' => '“Double A Solutions” MCHJ bosh direktori, Toshkent.'],
                            ['years' => '2020–2022', 'text' => 'O‘zbekiston Respublikasi investitsiyalar va tashqi savdo vazirining maslahatchisi.'],
                            ['years' => '2022-yildan', 'text' => 'Strategik islohotlar agentligi bosh direktorining birinchi o‘rinbosari (24-dekabrdan).'],
                            ['years' => '2026', 'text' => 'Strategik rivojlanish va islohotlar agentligi direktori vazifasini bajaruvchi (12-may — 10-iyul).'],
                            ['years' => '2026-yildan', 'text' => 'Strategik rivojlanish va islohotlar agentligi direktorining birinchi o‘rinbosari (16-iyuldan — hozirgi vaqtga qadar).'],
                        ],
                        'edu_title' => 'Ta’lim',
                        'edu_items' => [
                            ['years' => '1982', 'title' => 'Oliy ma’lumot', 'org' => 'Toshkent Xalq xo‘jaligi instituti'],
                        ],
                        'extra_title' => 'Ilmiy darajasi',
                        'extra_text' => 'Iqtisodiyot fanlari doktori',
                        'quote_text' => '',
                        'quote_author' => '',
                    ]],
                ],
            ],
            'en' => [
                'title' => 'First Deputy Director',
                'lead' => '',
                'meta_title' => 'First Deputy Director — Abdulla Abduqodirov',
                'meta_description' => 'Abdulla Abduqodirov is the First Deputy Director of the Agency for Strategic Development and Reforms under the President of the Republic of Uzbekistan.',
                'blocks' => [
                    ['person_profile', 'First Deputy Director profile', [
                        'photo' => '',
                        'name' => 'Abdulla Abduqodirov',
                        'position' => 'First Deputy Director of the Agency for Strategic Development and Reforms under the President of the Republic of Uzbekistan',
                        'text' => 'An economist and Doctor of Economics with extensive professional experience in public administration, public finance, financial markets, banking, investment and strategic reforms.',
                        'phone' => '',
                        'phone_label' => 'Reception:',
                        'email' => '',
                        'email_label' => 'E-mail:',
                        'button_text' => '',
                        'button_url' => '',
                    ]],
                    ['bio_education', 'Biography and education', [
                        'bio_title' => 'Biography',
                        'bio_text' => "Abdulla Mamasaatovich Abduqodirov was born on 19 February 1961 in Tashkent.\n\n"
                            . "He graduated from the Tashkent Institute of National Economy in 1982.\n\n"
                            . "He began his professional career as a researcher at the Institute of Economics of the Academy of Sciences of Uzbekistan. He subsequently held a number of senior positions at the Ministry of Finance of the Republic of Uzbekistan, progressing from Deputy Head of Department to First Deputy Minister.\n\n"
                            . "His professional experience also includes senior positions in state property management, securities market development, banking and the private sector. He worked at the Uzbekistan branch of ABN AMRO Bank N.V., served as General Director of Smart Heating Systems and Double A Solutions, and was a member of the Supervisory Board of Infinbank.\n\n"
                            . "From 2020 to 2022, he served as Adviser to the Minister of Investments and Foreign Trade of the Republic of Uzbekistan.\n\n"
                            . "In December 2022, he was appointed First Deputy Director General of the Agency for Strategic Reforms under the President of the Republic of Uzbekistan. On 12 May 2026 he was appointed Acting Director of the Agency for Strategic Development and Reforms and left that position on 10 July 2026 following a transfer to another position.\n\n"
                            . 'Since 16 July 2026, he has served as First Deputy Director of the Agency for Strategic Development and Reforms under the President of the Republic of Uzbekistan.',
                        'career' => [
                            ['years' => '1987–1990', 'text' => 'Researcher, Institute of Economics, Academy of Sciences of Uzbekistan.'],
                            ['years' => '1990–1997', 'text' => 'Ministry of Finance of the Republic of Uzbekistan — Deputy Head of Department, Head of Department, Deputy Minister and First Deputy Minister.'],
                            ['years' => '1996–2000', 'text' => 'First Deputy Chairman of the State Property Committee of the Republic of Uzbekistan; General Director of the Center for the Development and Improvement of the Securities Market.'],
                            ['years' => '2000–2004', 'text' => 'Uzbekistan branch of ABN AMRO Bank N.V. Netherlands — Head of Treasury and Deputy Chairman of the Management Board.'],
                            ['years' => '2005–2013', 'text' => 'General Director, Smart Heating Systems LLC.'],
                            ['years' => '2008–2013', 'text' => 'Member of the Supervisory Board, Infinbank.'],
                            ['years' => '2015–2022', 'text' => 'General Director, Double A Solutions LLC, Tashkent.'],
                            ['years' => '2020–2022', 'text' => 'Adviser to the Minister of Investments and Foreign Trade of the Republic of Uzbekistan.'],
                            ['years' => 'from 2022', 'text' => 'First Deputy Director General of the Agency for Strategic Reforms under the President of the Republic of Uzbekistan (from 24 December 2022).'],
                            ['years' => '2026', 'text' => 'Acting Director of the Agency for Strategic Development and Reforms under the President of the Republic of Uzbekistan (12 May – 10 July 2026).'],
                            ['years' => '2026 — present', 'text' => 'First Deputy Director of the Agency for Strategic Development and Reforms under the President of the Republic of Uzbekistan (from 16 July 2026).'],
                        ],
                        'edu_title' => 'Education',
                        'edu_items' => [
                            ['years' => '1982', 'title' => 'Higher education', 'org' => 'Tashkent Institute of National Economy'],
                        ],
                        'extra_title' => 'Academic degree',
                        'extra_text' => 'Doctor of Economics',
                        'quote_text' => '',
                        'quote_author' => '',
                    ]],
                ],
            ],
        ],
    ],

    // -----------------------------------------------------------------------
    // Раздел «Команда»: те же руководители как записи БД — их находит поиск по
    // сайту и блок team_list.
    // -----------------------------------------------------------------------
    'team' => [
        [
            'name' => 'Умурзаков Сардор Уктамович',
            'position' => 'Директор Агентства стратегического развития и реформ при Президенте Республики Узбекистан',
            'department' => 'Руководство',
            'unit' => '',
            'sort_order' => 10,
            'translations' => [
                'uz' => [
                    'name' => 'Umurzoqov Sardor O‘ktamovich',
                    'position' => 'O‘zbekiston Respublikasi Prezidenti huzuridagi Strategik rivojlanish va islohotlar agentligi direktori',
                    'department' => 'Rahbariyat',
                    'unit' => '',
                ],
            ],
        ],
        [
            'name' => 'Абдукодиров Абдулла Мамасаатович',
            'position' => 'Первый заместитель директора Агентства стратегического развития и реформ при Президенте Республики Узбекистан',
            'department' => 'Руководство',
            'unit' => '',
            'sort_order' => 20,
            'translations' => [
                'uz' => [
                    'name' => 'Abduqodirov Abdulla Mamasaatovich',
                    'position' => 'O‘zbekiston Respublikasi Prezidenti huzuridagi Strategik rivojlanish va islohotlar agentligi direktorining birinchi o‘rinbosari',
                    'department' => 'Rahbariyat',
                    'unit' => '',
                ],
                'en' => [
                    'name' => 'Abdulla Abduqodirov',
                    'position' => 'First Deputy Director of the Agency for Strategic Development and Reforms under the President of the Republic of Uzbekistan',
                    'department' => 'Leadership',
                    'unit' => '',
                ],
            ],
        ],
    ],
];
