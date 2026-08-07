<?php

return [
    'auth' => [
        // Non-secret bcrypt hash used to equalize unknown-login password checks.
        'dummy_password_hash' => env(
            'AEDUCA_AUTH_DUMMY_PASSWORD_HASH',
            '$2y$12$BF3NPjMrhEYd.TJlnZMkA.oS4NAtlD6ovpK.wwse0Zj3OGI8v06bK',
        ),
    ],

    'seed_admin' => [
        'login' => env('AEDUCA_SEED_ADMIN_LOGIN'),
        'password' => env('AEDUCA_SEED_ADMIN_PASSWORD'),
    ],

    /** Carrión business calendar and attendance clocks. */
    'business_timezone' => 'America/Lima',

    'attendance' => [
        'history_default_days' => 30,
        'history_max_days' => 93,
    ],

    'employee_attendance' => [
        'early_arrival_minutes' => 60,
    ],
];
