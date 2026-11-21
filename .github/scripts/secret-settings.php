<?php
// Adds the keys kept in their own GitHub secrets to the settings file the upload just wrote (TARGET).
// Each is used only if its secret exists; spaces and line breaks from pasting are dropped. Run by write_settings.
$map = [
    'STRIPE_SECRET_KEY'     => ['stripe_secret_key', '/^(rk|sk)_live_[A-Za-z0-9]+$/', 'a live Stripe key starting rk_live_ or sk_live_'],
    'STRIPE_WEBHOOK_SECRET' => ['stripe_webhook_secret', '/^whsec_[A-Za-z0-9]+$/', 'the webhook signing secret, starting whsec_'],
    'SMTP_PASSWORD'         => ['smtp_pass', '/^.+$/', 'the email password'],
    'NOTION_TOKEN'          => ['notion_token', '/^(ntn|secret)_[A-Za-z0-9]+$/', 'a Notion integration token, starting ntn_'],
];
$target = getenv('TARGET');
$cfg = require $target;
$changed = false;
foreach ($map as $env => [$key, $pattern, $what]) {
    $value = (string) getenv($env);
    $value = trim($value);
    // Keys have no spaces, so any are from pasting. A Google app password is shown as "abcd efgh ijkl mnop" and works without them.
    if ($env !== 'SMTP_PASSWORD' || preg_match('/^[a-z]{4}( [a-z]{4}){3}$/', $value)) $value = preg_replace('/\s+/', '', $value);
    if ($value === '') continue;
    if (!preg_match($pattern, $value)) {
        fwrite(STDERR, "::error::The GitHub secret $env should be $what, with nothing else around it.\n");
        exit(1);
    }
    $cfg[$key] = $value;
    $changed = true;
}
if ($changed) file_put_contents($target, "<?php\n// Written by the GitHub upload from the settings secret and the key secrets. Do not edit.\nreturn " . var_export($cfg, true) . ";\n");
