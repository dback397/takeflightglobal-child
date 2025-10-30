/**
 * TFG GDPR Consent Manager
 * Handles all contexts: newsletter, magic-token, membership
 */

document.addEventListener('DOMContentLoaded', function () {
  const checkboxes = document.querySelectorAll('input[type="checkbox"][data-context]');
  if (!checkboxes.length) {
      console.log('[TFG] No GDPR checkboxes found on this page.');
      return;
  }

  checkboxes.forEach(checkbox => {
      checkbox.addEventListener('change', async function () {
          const context = checkbox.dataset.context;
          if (!checkbox.checked) return;

          console.log(`[TFG] ✅ GDPR checked in ${context} context`);

          // Skip newsletter verification if already subscribed
          if (context === 'newsletter' && document.cookie.includes('is_subscribed=1')) {
              console.log('[TFG] 🍪 Already subscribed — skipping verification token request');
              return;
          }

          try {
              switch (context) {
                  case 'newsletter':
                      await handleNewsletterConsent();
                      break;

                  case 'magic-token':
                      await handleMagicTokenConsent();
                      break;

                  case 'membership':
                      await handleMembershipConsent();
                      break;

                  default:
                      console.warn('[TFG] Unknown GDPR context:', context);
              }
          } catch (err) {
              console.error(`[TFG] ❌ Error handling ${context} consent:`, err);
          }
      });
  });

  // -----------------------------
  // Context Handlers
  // -----------------------------

  async function handleNewsletterConsent() {
      console.log('[TFG] Fetching newsletter verification code...');
      const hiddenField = document.querySelector('#verification_code_news');
      if (!hiddenField) return console.warn('[TFG] Missing hidden verification_code_news field');

      const response = await fetch('/wp-json/custom-api/v1/get-verification-code', {
          method: 'GET',
          headers: { 'X-TFG-Token': 'dback-9a4t2g1e5z' }
      });
      const data = await response.json();

      if (response.ok && data.code) {
          hiddenField.value = data.code;
          console.log('[TFG] 🪄 Newsletter verification code set:', data.code);
      } else {
          console.warn('[TFG] ⚠️ Newsletter token fetch failed:', data);
      }
  }

  async function handleMagicTokenConsent() {
      console.log('[TFG] Fetching magic token...');
      const hiddenField = document.querySelector('#magic_token_code');
      if (!hiddenField) return console.warn('[TFG] Missing hidden magic_token_code field');

      const response = await fetch('/wp-json/custom-api/v1/create-magic-token', {
          method: 'POST',
          headers: {
              'Content-Type': 'application/json',
              'X-TFG-Token': 'dback-9a4t2g1e5z'
          },
          body: JSON.stringify({ purpose: 'homepage-magic' })
      });
      const data = await response.json();

      if (response.ok && data.token) {
          hiddenField.value = data.token;
          console.log('[TFG] 🪄 Magic token created:', data.token);
      } else {
          console.warn('[TFG] ⚠️ Magic token creation failed:', data);
      }
  }

  async function handleMembershipConsent() {
      console.log('[TFG] ✅ GDPR consent acknowledged for membership form (handled server-side)');
  }
});
