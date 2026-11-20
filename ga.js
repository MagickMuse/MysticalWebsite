// Google Analytics, set up "denied" until the visitor accepts cookies (cookie-consent.js loads Google's tag then).
// Used on the Manuscript's visitor pages, whose security policy allows no inline scripts.
window.dataLayer = window.dataLayer || [];
window.gtag = function () { window.dataLayer.push(arguments); };
window.gtag('consent', 'default', { analytics_storage: 'denied', ad_storage: 'denied' });
window.gtag('js', new Date());
window.gtag('config', 'G-0DV5GLQV05');
