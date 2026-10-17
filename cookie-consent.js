(function () {
  var CONSENT_KEY = 'tmm_cookie_consent';
  var stored = null;
  try { stored = localStorage.getItem(CONSENT_KEY); } catch (e) {}

  if (stored === 'granted') {
    grantConsent();
    return;
  }
  if (stored === 'denied') return;

  var banner = document.createElement('div');
  banner.className = 'consent';
  banner.setAttribute('role', 'region');
  banner.setAttribute('aria-label', 'Cookie consent');
  banner.innerHTML = '<p>We use cookies to understand how visitors use this site (Google Analytics). ' +
    'No personal data is sold. <a href="/privacy-policy.html">Privacy Policy</a></p>' +
    '<div class="actions">' +
    '<button class="btn ghost" type="button" data-choice="denied">Decline</button>' +
    '<button class="btn" type="button" data-choice="granted">Accept</button>' +
    '</div>';
  document.body.appendChild(banner);

  banner.addEventListener('click', function (e) {
    var choice = e.target.getAttribute('data-choice');
    if (!choice) return;
    try { localStorage.setItem(CONSENT_KEY, choice); } catch (err) {}
    if (choice === 'granted') grantConsent();
    banner.remove();
  });

  function grantConsent() {
    if (typeof gtag === 'function') {
      gtag('consent', 'update', { analytics_storage: 'granted', ad_storage: 'denied' });
    }
  }
})();
