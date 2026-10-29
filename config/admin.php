<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Initial Admin Account
    |--------------------------------------------------------------------------
    |
    | Used by the database seeder to create (or update) the admin login.
    | Set these in .env; there is no public registration.
    |
    */

    'name' => env('ADMIN_NAME', 'Admin'),
    'email' => env('ADMIN_EMAIL'),
    'password' => env('ADMIN_PASSWORD'),

];
