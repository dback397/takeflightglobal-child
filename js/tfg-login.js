document.addEventListener('DOMContentLoaded', function () {
  const isMember = document.cookie.includes('is_member=1');
  const isSubscriber = document.cookie.includes('is_subscribed=1');

  if (isMember || isSubscriber) {
    const loginBtn = document.querySelector('.magic-login-button');
    if (loginBtn) {
      loginBtn.style.backgroundColor = 'green';
      loginBtn.textContent = '✓ Verified';
    }
  }
});
