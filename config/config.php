<?php
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'barbersure',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4'
    ],
    'app' => [
        // If your project lives at http://localhost/BarberSure adjust like below.
        // If it's really at the root (http://localhost/), revert to http://localhost
        'base_url' => 'http://localhost/barbersure-app', // include subfolder if applicable
        'batangas_center_lat' => 13.7565,
        'batangas_center_lng' => 121.0583,
        'batangas_radius_km' => 60
    ],
    'sms' => [
        // provider: 'log' (default) or 'firebase'
        'provider' => 'firebase',
        // Firebase serverless hook to trigger SMS (optional). This expects you have
        // a Cloud Function/Endpoint you control that accepts JSON {to, message}.
        'firebase' => [
            'function_url' => 'http://127.0.0.1:5001/barbersure-app/us-central1/sendSms', // e.g., https://<region>-<project>.cloudfunctions.net/sendSms
            'api_key'      => '', // optional token
            'auth_header'  => 'Authorization' // or 'X-API-Key'
        ]
    ]
];
