document.addEventListener('DOMContentLoaded', function () {
  const gdprCheckbox = document.querySelector('#gdpr_consent');
  const emailInput = document.querySelector('[name="subscriber_email"]');
  const nameInput = document.querySelector('[name="subscriber_name"]');
  const codeInput = document.querySelector('#verification_code_field');

  if (!gdprCheckbox || !emailInput || !nameInput || !codeInput) {
    console.error('[TFG] ❌ One or more required elements not found.');
    return;
  }

  gdprCheckbox.addEventListener('change', function () {
    if (gdprCheckbox.checked) {
      console.log('[TFG] 📤 Sending:', {
      subscriber_email: emailInput.value,
      subscriber_name: nameInput.value,
      gdpr_consent: '1',
      source: 'newsletter_form'
    });
      fetch('/takeflightglobal/wp-json/custom-api/v1/create-verification-token', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    subscriber_email: emailInput.value,
    subscriber_name: nameInput.value,
    gdpr_consent: '1',
    source: 'newsletter_form'
  })
})
        .then(res => res.json())
        .then(data => {
          if (data.verification_code) {
            codeInput.value = data.verification_code;
            console.log('[TFG] ✅ Verification code set:', data.verification_code);
          } else {
            console.error('[TFG] ❌ Failed to set verification code', data);
          }
        })
        .catch(err => {
          console.error('[TFG] ❌ Error contacting endpoint:', err);
        });
    }
  });
});
