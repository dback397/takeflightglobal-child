// Global toggle password function (called from onclick)
function tfgTogglePassword(buttonElement) {
    // If called without parameter, find the button from event context
    const button = buttonElement || event.target;

    // Find the parent wrapper and then the input field
    const wrapper = button.closest('.tfg-password-wrapper');
    if (!wrapper) return;

    const input = wrapper.querySelector('input[type="password"], input[type="text"]');
    if (!input) return;

    // Toggle visibility
    const isVisible = input.type === 'text';
    input.type = isVisible ? 'password' : 'text';

    // Update icon: Eye closed SVG when hidden, Eye open SVG when visible
    if (isVisible) {
        // Hidden state - show closed eye icon
        button.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
        button.title = 'Show password';
    } else {
        // Visible state - show open eye with slash
        button.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';
        button.title = 'Hide password';
    }
}

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

