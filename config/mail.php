<?php
// Mail configuration for BarberSure
// Change these values in deployment; never commit real passwords.
return [
    // You can define a single 'driver' OR an ordered array 'drivers' for fallback.
    // Example for real sending with fallback to native mail then log:
    // 'drivers' => ['smtp','mail','log'],
    // For now we default to log only; edit to your SMTP details and uncomment above.
    'driver' => 'smtp', // if 'drivers' not set, this is used
    'from_address' => 'kawaiishikimori.san25@gmail.com',
    'from_name' => 'BarberSure Notifications',

    // SMTP only
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'encryption' => 'tls', // tls|ssl|none
    'username' => 'kawaiishikimori.san25@gmail.com',
    'password' => 'kmstrsknexffwoio',
    'timeout' => 12,
];
