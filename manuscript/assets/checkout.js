// Mounts Stripe's embedded Checkout in our own page. Fulfillment still happens in the webhook.
(function () {
  var box = document.getElementById('checkout');
  if (!box) return;
  function fail() {
    box.innerHTML = '';
    var p = document.createElement('p');
    p.className = 'pay-loading';
    p.textContent = 'The payment form could not load. Please reload the page, or check that nothing is blocking stripe.com.';
    box.appendChild(p);
  }
  function start() {
    if (!window.Stripe) return fail();
    var stripe = window.Stripe(box.getAttribute('data-pk'));
    var secret = box.getAttribute('data-secret');
    stripe.initEmbeddedCheckout({ fetchClientSecret: function () { return Promise.resolve(secret); } })
      .then(function (checkout) { box.innerHTML = ''; checkout.mount(box); })
      .catch(fail);
  }
  if (window.Stripe) start();
  else window.addEventListener('load', start);
})();
