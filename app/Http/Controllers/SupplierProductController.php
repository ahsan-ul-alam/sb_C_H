<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Audit;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SupplierProductController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));

        $products = SupplierProduct::query()->with('supplier')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%")->orWhere('category', 'like', "%{$q}%")))
            ->when($request->integer('supplier'), fn ($query, $id) => $query->where('supplier_id', $id))
            ->when($request->query('status', 'active') === 'active', fn ($query) => $query->where('is_active', true))
            ->orderBy('name')->paginate(30)->withQueryString();

        return view('sourcing.products.index', ['products' => $products, 'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data, $request) {
            $product = SupplierProduct::create($data);
            $product->prices()->create(['price' => $data['reference_price'], 'effective_on' => today(), 'changed_by' => $request->user()->id]);
            Audit::log('sourcing.product.created', 'product '.$product->id, $product->name);
        });

        return back()->with('status', 'Product added to the catalog.');
    }

    public function edit(SupplierProduct $product): View
    {
        return view('sourcing.products.edit', ['product' => $product->load(['supplier', 'prices'])]);
    }

    /**
     * A new reference price is appended to the history and only affects FUTURE lots; existing lot lines keep their snapshot.
     */
    public function update(Request $request, SupplierProduct $product): RedirectResponse
    {
        $data = $this->validated($request);
        unset($data['supplier_id']);
        DB::transaction(function () use ($data, $product, $request) {
            $changed = $data['reference_price'] !== $product->reference_price;
            $product->update($data + ['is_active' => $request->boolean('is_active')]);
            if ($changed) {
                $product->prices()->create(['price' => $data['reference_price'], 'effective_on' => today(), 'changed_by' => $request->user()->id]);
            }
            Audit::log('sourcing.product.updated', 'product '.$product->id, $changed ? 'price '.$data['reference_price'] : null);
        });

        return redirect()->route('sourcing.products.index')->with('status', 'Product saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'], 'name' => ['required', 'string', 'max:255'], 'sku' => ['nullable', 'string', 'max:60'],
            'category' => ['nullable', 'string', 'max:80'], 'variant' => ['nullable', 'string', 'max:120'], 'unit' => ['required', 'string', 'max:20'],
            'reference_price' => ['required', 'regex:'.Money::AMOUNT_REGEX], 'min_order_qty' => ['nullable', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['reference_price'] = Money::toMinor($data['reference_price']);

        return $data;
    }
}
