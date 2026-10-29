<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the login page returns a successful response', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});
