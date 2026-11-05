<?php

use App\Models\Document;
use App\Models\StockMovement;
use App\Services\Inventory;
use App\Services\Receiving;
use App\Services\StockLocations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../Support/inventory_helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function arrivedLot($test): array
{
    Storage::fake();
    $s = unreceivedLot(SellbeesCompany::boot($test), '100');
    $s['event'] = Receiving::receive($s['line'], '100', '95', '2', '2026-02-10', StockLocations::default()->id, 'GRN-1', null, null, $s['user']);

    return $s;
}

it('attaches receiving evidence once, never replaces it, and never touches stock', function () {
    $s = arrivedLot($this);
    $before = StockMovement::count();
    $file = fn (string $content = 'challan') => UploadedFile::fake()->createWithContent('challan.pdf', '%PDF-1.4 '.$content);
    $s['co']->post('clients', 'stock.evidence.store', ['kind' => 'challan', 'file' => $file()], $s['event'])->assertSessionHasNoErrors();
    $doc = Document::where('subject_type', 'stock_arrival')->firstOrFail();
    expect($doc->kind)->toBe('challan')->and($doc->sha256)->toHaveLength(64)->and($doc->uploaded_by)->toBe($s['user']->id);
    Storage::assertExists($doc->path);

    $s['co']->post('clients', 'stock.evidence.store', ['kind' => 'grn', 'file' => $file()], $s['event'])->assertSessionHas('error');   // the same file again
    $s['co']->post('clients', 'stock.evidence.store', ['kind' => 'grn', 'file' => $file('second')], $s['event'])->assertSessionHasNoErrors();
    expect(Document::where('subject_type', 'stock_arrival')->count())->toBe(2)->and(StockMovement::count())->toBe($before)
        ->and(Inventory::balances($s['line']->refresh())['available'])->toBe('95.00');

    $s['co']->post('clients', 'stock.evidence.store', ['kind' => 'challan', 'file' => UploadedFile::fake()->create('x.exe', 10)], $s['event'])->assertSessionHasErrors('file');
    $s['co']->post('clients', 'stock.evidence.store', ['kind' => 'bogus', 'file' => $file('third')], $s['event'])->assertSessionHasErrors('kind');
    expect(Document::where('subject_type', 'stock_arrival')->count())->toBe(2);
});

it('lets only authorised staff upload and view evidence', function () {
    $s = arrivedLot($this);
    $s['co']->post('clients', 'stock.evidence.store', ['kind' => 'photo', 'file' => UploadedFile::fake()->image('p.jpg')], $s['event'])->assertSessionHasNoErrors();
    $doc = Document::firstOrFail();
    $s['co']->post('auditor', 'stock.evidence.store', ['kind' => 'photo', 'file' => UploadedFile::fake()->image('q.jpg')], $s['event'])->assertForbidden();
    $s['co']->as('auditor')->get(route('stock.evidence.download', $doc))->assertOk();
    $s['co']->as('employee')->get(route('stock.evidence.download', $doc))->assertForbidden();
    $s['co']->as('finance')->get(route('stock.receiving'))->assertOk()->assertSee('Product condition photo');
});

it('stores one validated, private product image and serves it only to authorised staff', function () {
    $s = arrivedLot($this);
    $product = $s['product'];
    $s['co']->as('finance')->get(route('stock.projects'))->assertOk();                                      // fallback initial shows without an image
    $s['co']->post('clients', 'stock.products.image.store', ['image' => UploadedFile::fake()->create('a.pdf', 10)], $product)->assertSessionHasErrors('image');
    $s['co']->post('clients', 'stock.products.image.store', ['image' => UploadedFile::fake()->image('tiny.png', 10, 10)], $product)->assertSessionHasErrors('image');
    $s['co']->post('clients', 'stock.products.image.store', ['image' => UploadedFile::fake()->image('hoodie.png', 300, 300)], $product)->assertSessionHasNoErrors();
    $first = $product->refresh()->image_path;
    Storage::assertExists($first);
    $s['co']->post('clients', 'stock.products.image.store', ['image' => UploadedFile::fake()->image('hoodie2.jpg', 300, 300)], $product)->assertSessionHasNoErrors();
    Storage::assertMissing($first);                                                                       // replaced, not accumulated
    $s['co']->as('finance')->get(route('stock.products.image', $product))->assertOk();
    $s['co']->as('employee')->get(route('stock.products.image', $product))->assertForbidden();
    $s['co']->post('auditor', 'stock.products.image.store', ['image' => UploadedFile::fake()->image('z.png', 300, 300)], $product)->assertForbidden();
    $s['co']->as('finance')->get(route('stock.projects'))->assertOk()->assertSee(route('stock.products.image', $product), false);
});
