<?php

return [
    /*
    | Client Self-Service Portal.
    | test_code_hash: a bcrypt hash (never the code itself) of the owner's admin test confirmation code. Generate it with
    |   php artisan tinker --execute 'echo Hash::make("<code>");'  and put it in CLIENT_PORTAL_TEST_CODE_HASH.
    | The static code is an ADDITIONAL confirmation for an already signed-in administrator who holds portal.preview. It is never honoured in production,
    | and it never opens the public login.
    */
    'idle_minutes' => (int) env('CLIENT_PORTAL_IDLE_MINUTES', 30),
    'otp_ttl_seconds' => 300,
    'otp_max_attempts' => 5,
    'resend_cooldown_seconds' => 60,
    'otp_per_client_per_hour' => 5,
    'preview_minutes' => (int) env('CLIENT_PORTAL_PREVIEW_MINUTES', 15),
    'test_code_hash' => env('CLIENT_PORTAL_TEST_CODE_HASH'),
    'admin_preview_in_production' => (bool) env('CLIENT_PORTAL_ADMIN_PREVIEW_IN_PRODUCTION', false),
];
