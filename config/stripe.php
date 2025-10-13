<?php
// Stripe configuration
// Use environment variables for secrets; keep this file committed without real keys.
return [
    'secret_key' => getenv('STRIPE_SECRET') ?: '',
    'publishable_key' => getenv('STRIPE_PUBLISHABLE') ?: '',
    'currency' => 'usd', // Use 'usd' if PHP is not enabled on your Stripe account
    'success_url' => null, // optional; app will use in-app success handler if null
    'cancel_url' => null,
];
