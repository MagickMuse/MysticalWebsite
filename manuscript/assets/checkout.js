// The custom payment form (Stripe Checkout, ui_mode "form") on our own page. Fulfillment still happens in the webhook.
(function () {
  var box = document.getElementById('checkout-form');
  if (!box) return;
  function fail() {
    box.innerHTML = '';
    var p = document.createElement('p');
    p.className = 'pay-loading';
    p.textContent = 'The payment form could not load. Please reload the page, or check that nothing is blocking stripe.com.';
    box.appendChild(p);
  }
  var appearance = {
    theme: 'stripe',
    labels: 'auto',
    inputs: 'spaced',
    variables: {
      borderRadius: '4px',
      colorBackground: '#ffffff',
      colorDanger: '#9a494e',
      colorPrimary: '#7a1f2b',
      colorSuccess: '#7a5d22',
      colorText: '#2e1f20',
      fontFamily: 'PT Serif',
      fontSizeBase: '16px',
      spacingUnit: '4px'
    }
  };
  async function start() {
    if (!window.Stripe) return fail();
    try {
      var stripe = window.Stripe(box.getAttribute('data-pk'));
      // The server created the Checkout Session when the buyer pressed Buy (a CSRF-protected form post)
      // and put its client secret on this page.
      var clientSecret = Promise.resolve(box.getAttribute('data-secret'));
      var checkout = stripe.initCheckoutFormSdk({ clientSecret: clientSecret, appearance: appearance });
      box.innerHTML = '';
      var form = checkout.createForm({ layout: 'expanded' });
      form.mount('#checkout-form');
      var loadActionsResult = await checkout.loadActions();
      if (loadActionsResult.type === 'success') {
        form.on('confirm', async function (event) {
          try {
            await loadActionsResult.actions.confirm({ formConfirmEvent: event });
          } catch (error) {
            console.error('Payment confirmation error:', error);
          }
        });
      }
    } catch (error) {
      console.error(error);
      fail();
    }
  }
  if (window.Stripe) start();
  else window.addEventListener('load', start);
})();
