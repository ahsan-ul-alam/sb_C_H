<?php

use App\Models\Document;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| Release gate: private evidence and product images must be reachable only through authorised application routes, never as a public URL,
| and no financial or administrative route may be reachable without signing in.
*/

it('keeps the default disk private and the public link pointing only at the public disk', function () {
    expect(config('filesystems.default'))->toBe('local')->and(config('filesystems.disks.local.root'))->toBe(storage_path('app/private'))
        ->and(config('filesystems.disks.public.root'))->toBe(storage_path('app/public'));
    if (is_link(public_path('storage'))) {
        expect(realpath(public_path('storage')))->toBe(realpath(storage_path('app/public')));
    }
    expect(file_get_contents(config_path('app.php')))->toContain("env('APP_DEBUG', false)");
});

it('cannot fetch a private evidence file or product image through a public storage URL', function () {
    $co = SellbeesCompany::boot($this);
    $path = 'receiving-evidence/gate-test-'.uniqid().'.pdf';
    $image = 'product-images/gate-test-'.uniqid().'.png';
    Storage::put($path, '%PDF-1.4 private');
    Storage::put($image, 'png-bytes');
    try {
        foreach ([$path, $image] as $file) {
            $this->get('/storage/'.$file)->assertStatus(403);                       // guest: unsigned local-disk URLs are refused
            $co->as('finance')->get('/storage/'.$file)->assertStatus(403);           // even a signed-in user cannot use the raw URL
            $this->get('/'.$file)->assertNotFound();
            expect(file_exists(public_path($file)))->toBeFalse();
        }
    } finally {
        Storage::delete([$path, $image]);
    }
});

it('serves evidence and product images only to signed-in users with the permission', function () {
    $co = SellbeesCompany::boot($this);
    $doc = Document::create(['subject_type' => 'stock_arrival', 'subject_id' => 1, 'name' => 'GRN', 'path' => 'receiving-evidence/x.pdf', 'uploaded_by' => $co->users['finance']->id]);
    $product = SupplierProduct::create(['supplier_id' => Supplier::create(['name' => 'S'])->id, 'name' => 'P', 'unit' => 'pcs', 'reference_price' => 100]);
    $this->get(route('stock.evidence.download', $doc))->assertRedirect(route('login'));
    $this->get(route('stock.products.image', $product))->assertRedirect(route('login'));
    $co->as('employee')->get(route('stock.evidence.download', $doc))->assertForbidden();
    $co->as('employee')->get(route('stock.products.image', $product))->assertForbidden();
    expect($co->as('finance')->get(route('stock.evidence.download', $doc))->status())->toBe(404);   // permitted, but the file is not there
});

it('exposes no application route without authentication except sign-in and the brand favicon', function () {
    $open = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => ! str_starts_with($r->uri(), '_') && ! str_starts_with($r->uri(), 'storage/') && $r->uri() !== 'up')
        ->filter(fn ($r) => ! collect($r->gatherMiddleware())->contains(fn ($m) => is_string($m) && (str_starts_with($m, 'auth') || str_contains($m, 'Authenticate') || $m === 'client.portal')))
        ->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())->values()->all();

    sort($open);
    // Besides the staff login, only the Client Portal's own sign-in steps are public; its private pages sit behind the client.portal session middleware.
    expect($open)->toBe(['GET|HEAD brand/favicon', 'GET|HEAD client-portal', 'GET|HEAD client-portal/verify', 'GET|HEAD login', 'POST client-portal/code', 'POST client-portal/resend', 'POST client-portal/verify', 'POST login']);
});
