<?php

namespace App\Http\Controllers;

use App\Models\SourcingLot;
use App\Models\Supplier;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $status = (string) $request->query('status', 'active');

        $suppliers = Supplier::query()
            ->withCount(['products', 'lotLines'])
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")
                ->orWhere('company_name', 'like', "%{$q}%")->orWhere('categories', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')->paginate(25)->withQueryString();

        return view('sourcing.suppliers.index', ['suppliers' => $suppliers, 'status' => $status]);
    }

    public function create(): View
    {
        return view('sourcing.suppliers.form', ['supplier' => new Supplier(['is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $supplier = Supplier::create($this->validated($request));
        Audit::log('sourcing.supplier.created', 'supplier '.$supplier->id, $supplier->name);

        return redirect()->route('sourcing.suppliers.show', $supplier)->with('status', 'Supplier created.');
    }

    public function show(Supplier $supplier): View
    {
        $lotIds = $supplier->lotLines()->pluck('sourcing_lot_id')->unique();

        return view('sourcing.suppliers.show', [
            'supplier' => $supplier,
            'products' => $supplier->products()->orderBy('name')->get(),
            'lots' => SourcingLot::whereIn('id', $lotIds)->latest('id')->get(),
            'payable' => $supplier->payable(),
            'payments' => $supplier->payments()->with('lot')->latest('paid_on')->latest('id')->limit(25)->get(),
        ]);
    }

    public function edit(Supplier $supplier): View
    {
        return view('sourcing.suppliers.form', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validated($request, $supplier));
        Audit::log('sourcing.supplier.updated', 'supplier '.$supplier->id);

        return redirect()->route('sourcing.suppliers.show', $supplier)->with('status', 'Supplier saved.');
    }

    /** Suppliers are archived, never deleted, so lots and payments keep their history. */
    public function toggleArchive(Supplier $supplier): RedirectResponse
    {
        $supplier->update(['is_active' => ! $supplier->is_active]);
        Audit::log('sourcing.supplier.'.($supplier->is_active ? 'restored' : 'archived'), 'supplier '.$supplier->id);

        return back()->with('status', $supplier->is_active ? 'Supplier restored.' : 'Supplier archived.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'company_name' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string', 'max:255'],
            'categories' => ['nullable', 'string', 'max:255'], 'payment_info' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        if (! $request->user()->can('sourcing.supplier.secrets')) {
            unset($data['payment_info']); // never overwritten (or readable) without the permission
        }

        return $data;
    }
}
