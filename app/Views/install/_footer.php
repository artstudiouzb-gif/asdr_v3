    </div><!-- /.install-card -->
</div><!-- /.install-wrapper -->

<script nonce="<?= \App\Core\SecurityHeaders::nonce() ?>">
document.querySelectorAll('.install-card form').forEach(function (form) {
    form.addEventListener('submit', function () {
        var button = form.querySelector('button[type="submit"]');
        if (!button) return;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.textContent = 'Подождите…';
    });
});

// Password Toggle Button Handler
['toggle_pass', 'toggle_db_pass'].forEach(function(btnId) {
    var btn = document.getElementById(btnId);
    if (!btn) return;
    btn.addEventListener('click', function() {
        var input = btn.previousElementSibling;
        if (!input) return;
        var showIcon = btn.querySelector('.auth-eye-icon--show');
        var hideIcon = btn.querySelector('.auth-eye-icon--hide');
        if (input.type === 'password') {
            input.type = 'text';
            if (showIcon) showIcon.style.display = 'none';
            if (hideIcon) hideIcon.style.display = 'block';
        } else {
            input.type = 'password';
            if (showIcon) showIcon.style.display = 'block';
            if (hideIcon) hideIcon.style.display = 'none';
        }
    });
});

// Password Strength Meter Handler
var passInput = document.getElementById('password');
if (passInput) {
    passInput.addEventListener('input', function() {
        var val = passInput.value;
        var bar = document.getElementById('pass_strength_bar');
        var text = document.getElementById('pass_strength_text');
        if (!bar || !text) return;

        var score = 0;
        if (val.length >= 8) score += 25;
        if (val.length >= 12) score += 25;
        if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score += 25;
        if (/[0-9]/.test(val) && /[^A-Za-z0-9]/.test(val)) score += 25;

        bar.style.width = score + '%';
        if (score <= 25) {
            bar.style.background = '#ef4444';
            text.textContent = 'Слабый пароль';
            text.style.color = '#ef4444';
        } else if (score <= 50) {
            bar.style.background = '#f59e0b';
            text.textContent = 'Средний пароль';
            text.style.color = '#f59e0b';
        } else if (score <= 75) {
            bar.style.background = '#3b82f6';
            text.textContent = 'Хороший пароль';
            text.style.color = '#3b82f6';
        } else {
            bar.style.background = '#10b981';
            text.textContent = 'Надёжный пароль';
            text.style.color = '#10b981';
        }
    });
}
</script>
</body>
</html>
