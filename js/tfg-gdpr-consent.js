/**
 * TFG GDPR Consent Handler for Newsletter
 * Creates verification token when checkbox is checked.
 */
document.addEventListener('DOMContentLoaded', () => {
    const checkbox = document.querySelector('#gdpr_consent_news[data-context="newsletter"]');
    const hiddenField = document.querySelector('#verification_code_news');

    if (!checkbox || !hiddenField) {
        console.log('[TFG] Newsletter GDPR elements not found.');
        return;
    }

    checkbox.addEventListener('change', async () => {
        if (!checkbox.checked) return;

        // Skip if already subscribed
        if (document.cookie.includes('is_subscribed=1')) {
            console.log('[TFG] 🍪 Already subscribed — skipping verification code request.');
            return;
        }

        console.log('[TFG] Fetching verification code for newsletter signup…');

        try {
            const response = await fetch('/wp-json/custom-api/v1/get-verification-code', {
                method: 'GET',
                headers: { 'X-TFG-Token': 'dback-9a4t2g1e5z' }
            });

            const data = await response.json();

            if (response.ok && data.code && data.code !== 'rest_forbidden') {
                hiddenField.value = data.code;
                console.log('[TFG] ✅ Verification code set:', data.code);
            } else {
                console.warn('[TFG] ⚠️ Invalid REST response:', data);
            }
        } catch (err) {
            console.error('[TFG] ❌ Error fetching verification code:', err);
        }
    });
});
