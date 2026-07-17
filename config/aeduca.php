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
];
