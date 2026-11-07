<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sends the baseline security headers, and HSTS only over HTTPS', function () {
    $this->get('/login')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')->assertHeaderMissing('Strict-Transport-Security');
    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security');
});

it('redirects plain HTTP to HTTPS only when FORCE_HTTPS is on', function () {
    $this->get('http://localhost/login')->assertOk();
    config(['app.force_https' => true]);
    $this->get('http://localhost/login')->assertStatus(301)->assertRedirect('https://localhost/login');
    $this->get('https://localhost/login')->assertOk();
});

it('keeps dotfiles and dumps out of the web root rules', function () {
    $rules = file_get_contents(public_path('.htaccess'));
    expect($rules)->toContain('well-known')->and($rules)->toContain('sql|bak|log|env');
    expect(array_filter(scandir(public_path()), fn ($f) => preg_match('/^\.env|\.sql$|\.log$/i', $f)))->toBe([]);
});
