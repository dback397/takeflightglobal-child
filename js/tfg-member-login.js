document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('tfg-toggle-password');
    const input = document.getElementById('tfg-password');

    if (toggle && input) {
        toggle.addEventListener('click', function () {
            const isVisible = input.type === 'text';
            input.type = isVisible ? 'password' : 'text';
            toggle.innerText = isVisible ? '👁️' : '🙈';
        });
    }
});

document.addEventListener('DOMContentLoaded', function () {
    const submitBtn = document.getElementById('submit-button');
    const gdprBox   = document.getElementById('gdpr_consent');
    const warning   = document.getElementById('gdpr-warning');

    if (submitBtn && gdprBox) {
        const toggleSubmit = () => {
            const checked = gdprBox.checked;
            submitBtn.disabled = !checked;
            if (warning) warning.style.display = checked ? 'none' : 'block';
        };
        gdprBox.addEventListener('change', toggleSubmit);
        toggleSubmit(); // run on load
    }
});

