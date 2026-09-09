(function () {
    'use strict';

    var forms = document.querySelectorAll('.block-form__form');
    if (!forms.length) { return; }

    forms.forEach(function (form) {
        // --- Живой счётчик символов для полей Сообщение (textarea) ---
        var textareas = form.querySelectorAll('textarea[maxlength]');
        textareas.forEach(function (ta) {
            var fieldBox = ta.closest('.block-form__field');
            var counterEl = fieldBox ? fieldBox.querySelector('.char-count') : null;
            var maxLen = parseInt(ta.getAttribute('maxlength') || '2000', 10);

            var updateCounter = function () {
                var len = ta.value.length;
                if (counterEl) {
                    counterEl.textContent = len;
                    var parent = counterEl.parentElement;
                    if (parent) {
                        parent.classList.toggle('is-near-limit', len >= maxLen * 0.9);
                    }
                }
            };
            ta.addEventListener('input', updateCounter);
            updateCounter();
        });

        // --- Строгая фильтрация формата номера телефона (запрет букв) ---
        var phoneInputs = form.querySelectorAll('input[type="tel"], input[data-input-type="phone"]');
        phoneInputs.forEach(function (inp) {
            // Запрет текстовых символов на лету
            inp.addEventListener('input', function () {
                var cleaned = inp.value.replace(/[^\+0-9\s\-\(\)]/g, '');
                if (inp.value !== cleaned) {
                    inp.value = cleaned;
                }
            });

            // Проверка при выходе из поля (blur)
            inp.addEventListener('blur', function () {
                var val = inp.value.trim();
                var digits = val.replace(/\D/g, '');
                var field = inp.closest('.block-form__field');
                if (!field) { return; }

                field.classList.remove('is-error');
                var oldErr = field.querySelector('.block-form__error');
                if (oldErr) { oldErr.remove(); }

                if (val !== '' && digits.length < 7) {
                    field.classList.add('is-error');
                    var em = document.createElement('span');
                    em.className = 'block-form__error';
                    em.textContent = 'Введите корректный номер телефона (не менее 7 цифр)';
                    field.appendChild(em);
                }
            });
        });

        // --- Условная логика полей (задача 135) ---
        var conditional = Array.prototype.slice.call(form.querySelectorAll('[data-cond-field]'));
        if (conditional.length) {
            var applyConditions = function () {
                conditional.forEach(function (fieldEl) {
                    var trigName = fieldEl.getAttribute('data-cond-field');
                    var trigVal = fieldEl.getAttribute('data-cond-value');
                    var trigger = form.querySelector('[name="' + trigName + '"]');
                    var current = trigger ? String(trigger.value || '') : '';
                    var show = current === trigVal;
                    fieldEl.hidden = !show;
                    // Отключаем скрытые поля, чтобы браузер их не валидировал/не слал.
                    fieldEl.querySelectorAll('input, textarea, select').forEach(function (inp) {
                        inp.disabled = !show;
                    });
                });
            };
            form.addEventListener('change', applyConditions);
            form.addEventListener('input', applyConditions);
            applyConditions();
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            // Снимаем прошлые ошибки.
            form.querySelectorAll('.block-form__field.is-error').forEach(function (f) {
                f.classList.remove('is-error');
                var msg = f.querySelector('.block-form__error');
                if (msg) { msg.remove(); }
            });
            var topMsg = form.querySelector('.block-form__message');
            if (topMsg) { topMsg.remove(); }

            var btn = form.querySelector('button[type="submit"], .block-form__submit');
            var oldLabel = btn ? btn.textContent : '';
            if (btn) { btn.disabled = true; btn.classList.add('is-loading'); btn.textContent = 'Отправка…'; }

            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            })
                .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Ошибка сервера.' }; }); })
                .then(function (res) {
                    if (res.ok) {
                        // Успех: плавно скрываем форму, показываем благодарность.
                        form.classList.add('is-submitted');
                        setTimeout(function () {
                            var done = document.createElement('div');
                            done.className = 'block-form__thanks';
                            done.textContent = res.message || 'Спасибо! Ваша заявка отправлена.';
                            form.parentNode.replaceChild(done, form);
                        }, 400);
                        return;
                    }

                    // Ошибки валидации по полям.
                    if (res.errors) {
                        Object.keys(res.errors).forEach(function (name) {
                            var input = form.querySelector('[name="' + name + '"]');
                            var field = input ? input.closest('.block-form__field') : null;
                            if (field) {
                                field.classList.add('is-error');
                                var em = document.createElement('span');
                                em.className = 'block-form__error';
                                em.textContent = res.errors[name];
                                field.appendChild(em);
                            }
                        });
                    }
                    showTopMessage(form, res.message || 'Проверьте форму.', 'error');
                })
                .catch(function () {
                    showTopMessage(form, 'Сетевая ошибка. Попробуйте ещё раз.', 'error');
                })
                .finally(function () {
                    if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); btn.textContent = oldLabel; }
                });
        });
    });

    function showTopMessage(form, text, type) {
        var m = document.createElement('div');
        m.className = 'block-form__message block-form__message--' + type;
        m.textContent = text;
        form.insertBefore(m, form.firstChild);
    }
})();
