function tfgShowErrorModal(code) {
    const data = tfgErrorMessages[code];
    if (!data) return;

    const titleEl = document.getElementById("tfg-error-title");
    const msgEl = document.getElementById("tfg-error-message");
    const modalBody = document.getElementById("tfg-error-modal-body");
    const iconEl = document.getElementById("tfg-error-icon");
    const overlay = document.getElementById("tfg-error-overlay");

    if (!overlay || !modalBody || !titleEl || !msgEl || !iconEl) {
        console.warn("⚠️ Modal elements not fully loaded.");
        return;
    }

    if (titleEl) titleEl.textContent = data.title || 'Error';
    if (msgEl) {
        msgEl.textContent = data.message || '';
        msgEl.style.color = data.text_color || '#000';
    }
    if (modalBody) {
        modalBody.style.backgroundColor = data.body_color || '#fff';
    }
    if (iconEl && data.dashicon) {
        iconEl.className = 'dashicons ' + data.dashicon;
        iconEl.style.color = data.text_color || '#000';
    }

    if (overlay) {
        overlay.style.display = 'flex';
    }
}

// ⏳ Auto-trigger modal on page load if shortcode includes a code
document.addEventListener('DOMContentLoaded', function () {
    const overlay = document.getElementById('tfg-error-overlay');
    const modalBody = document.getElementById('tfg-error-modal-body');
    const closeBtn = document.getElementById('tfg-error-close');

    // Handle ✕ close button
    if (closeBtn && overlay) {
        closeBtn.addEventListener('click', () => {
            overlay.style.display = 'none';
        });
    }

    // Auto-trigger only if modalBody has a valid error code
    if (modalBody && modalBody.hasAttribute('data-error-code')) {
        const code = modalBody.getAttribute('data-error-code');
        if (code && typeof tfgShowErrorModal === 'function') {
            tfgShowErrorModal(code);
        }
    }
});


// ⌨️ Close modal on ESC
document.addEventListener('keydown', function (event) {
    const overlay = document.getElementById('tfg-error-overlay');
    if ((event.key === 'Escape' || event.key === 'Esc') && overlay?.style.display === 'flex') {
        overlay.style.display = 'none';
    }
});

document.addEventListener('DOMContentLoaded', function () {
    if (window.location.search.includes('token=')) {
        const cleanUrl = window.location.origin + window.location.pathname;
        window.history.replaceState({}, document.title, cleanUrl);
    }
});

document.addEventListener("DOMContentLoaded", function() {
    const form = document.querySelector("form.revalidate-form"); // Use your form's actual selector
    if (form) {
        form.reset();  // Clears all fields to default
    }
});