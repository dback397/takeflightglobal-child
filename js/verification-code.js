document.addEventListener('DOMContentLoaded', function () {
    const checkbox = document.querySelector('#wpforms-318-field_3_1');
    const hiddenField = document.querySelector('input[name="wpforms[fields][6]"]');

    if (!checkbox || !hiddenField) {
        console.warn('Checkbox or hidden field not found.');
        return;
    }

    checkbox.addEventListener('change', async function () {
        if (document.cookie.includes('is_subscribed=1')) {
            console.log('[TFG] 🍪 Already subscribed via cookie — skipping token request');
            return;
        }

        if (checkbox.checked) {
            console.log("Checkbox checked. Fetching verification code...");

            try {
                const response = await fetch('/wp-json/custom-api/v1/get-verification-code', {
                    method: 'GET',
                    headers: {
                        'X-TFG-Token': 'dback-9a4t2g1e5z'
                    }
                });

                const data = await response.json();

                if (response.ok && data.code && data.code !== 'rest_forbidden') {
                    hiddenField.value = data.code;
                    console.log("✅ Verification code inserted:", data.code);
                } else {
                    console.warn("⚠️ REST error or invalid code:", data);
                }

            } catch (error) {
                console.error("❌ Error fetching verification code:", error);
            }
        }
    });
});


