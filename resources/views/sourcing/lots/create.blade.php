@use('Illuminate\Support\Str')
<x-layout title="New client lot">
    <x-page-header title="New client lot" :crumbs="[['Client Lots', route('sourcing.lots.index')], ['New', null]]">
        <x-slot:meta>Pick the supplier, product and project, then enter the quantity, the unit cost and the client's budget for the whole lot. Delivery is added later, when you put the lot in order.</x-slot:meta>
    </x-page-header>

    <section class="panel">
        <form method="POST" action="{{ route('sourcing.client-lots.store') }}" id="client-lot" class="panel-body" data-products-url="{{ route('sourcing.suppliers.products', '__ID__') }}" data-old-product="{{ old('supplier_product_id') }}">@csrf
            <input type="hidden" name="submission_key" value="{{ Str::uuid() }}">
            <div class="form-grid">
                <div class="field"><label for="c-supplier">Supplier</label>
                    <select name="supplier_id" id="c-supplier" required>
                        <option value="">Choose a supplier…</option>
                        @foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected((int) old('supplier_id') === $s->id)>{{ $s->name }}</option>@endforeach
                    </select></div>
                <div class="field"><label for="c-product">Product</label>
                    <select name="supplier_product_id" id="c-product" required disabled><option value="">Choose a supplier first</option></select></div>
                <div class="field"><label for="c-project">Project</label>
                    <select name="project_id" id="c-project" required>
                        <option value="">Choose a project…</option>
                        @foreach ($projects as $p)<option value="{{ $p->id }}" @selected((int) old('project_id') === $p->id)>{{ $p->name }} — {{ $p->client->name }}</option>@endforeach
                    </select></div>
                <div class="field"><label for="c-quantity">Quantity</label><input name="quantity" id="c-quantity" inputmode="numeric" pattern="[1-9][0-9]*" value="{{ old('quantity') }}" required placeholder="e.g. 100"></div>
                <div class="field"><label for="c-cost">Unit base cost (BDT)</label><input name="unit_cost" id="c-cost" inputmode="decimal" value="{{ old('unit_cost') }}" required placeholder="Purchase cost per unit"></div>
                <div class="field"><label for="c-budget">Client lot budget (BDT)</label><input name="client_budget" id="c-budget" inputmode="decimal" value="{{ old('client_budget') }}" required placeholder="Total agreed with the client"></div>
            </div>

            <div class="lot-preview" aria-live="polite">
                <div><div class="label">Total base cost</div><div class="value" id="c-base">—</div></div>
                <div><div class="label">Client lot budget</div><div class="value" id="c-budget-out">—</div></div>
                <div><div class="label" id="c-profit-label">Estimated profit</div><div class="value" id="c-profit">—</div></div>
            </div>
            <p class="muted" style="margin-top:.6rem">Total base cost = quantity × unit base cost. Estimated profit = client budget − total base cost (delivery is added when the lot is put in order).
                The budget is what the client agreed to pay for the lot — it is <strong>not</strong> recorded as money received.</p>

            <button class="btn btn-primary" style="margin-top:.9rem">Create lot</button>
        </form>
    </section>

    @push('scripts')
    @include('sourcing.lots.partials.money-js')
    <script>
    (function () {
        var form = document.getElementById('client-lot');
        if (!form) { return; }
        var M = window.LotMoney, supplier = document.getElementById('c-supplier'), product = document.getElementById('c-product');
        var qty = document.getElementById('c-quantity'), cost = document.getElementById('c-cost'), budget = document.getElementById('c-budget');
        var prices = {}, autoCost = '';

        function show() {
            var q = /^[1-9]\d{0,9}$/.test(qty.value.trim()) ? parseInt(qty.value.trim(), 10) : null;
            var c = M.toMinor(cost.value), b = M.toMinor(budget.value);
            var base = q !== null && c !== null ? q * c : null;
            document.getElementById('c-base').textContent = base === null ? '—' : M.format(base);
            document.getElementById('c-budget-out').textContent = b === null ? '—' : M.format(b);
            var out = document.getElementById('c-profit'), label = document.getElementById('c-profit-label');
            if (base === null || b === null) { out.textContent = '—'; out.classList.remove('is-loss'); label.textContent = 'Estimated profit'; return; }
            var profit = b - base;
            out.textContent = M.format(profit); out.classList.toggle('is-loss', profit < 0);
            label.textContent = profit < 0 ? 'Estimated loss' : 'Estimated profit';
        }

        function loadProducts(selected) {
            prices = {};
            product.innerHTML = ''; product.disabled = true;
            if (!supplier.value) { product.innerHTML = '<option value="">Choose a supplier first</option>'; return; }
            product.innerHTML = '<option value="">Loading…</option>';
            fetch(form.dataset.productsUrl.replace('__ID__', supplier.value), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (list) {
                    product.innerHTML = '';
                    var first = document.createElement('option'); first.value = ''; first.textContent = list.length ? 'Choose a product…' : 'This supplier has no active products'; product.appendChild(first);
                    list.forEach(function (p) {
                        prices[p.id] = p.price_minor;
                        var o = document.createElement('option'); o.value = p.id; o.textContent = p.name + ' — ' + p.price; o.selected = String(p.id) === String(selected); product.appendChild(o);
                    });
                    product.disabled = !list.length;
                }).catch(function () { product.innerHTML = '<option value="">Could not load products</option>'; });
        }

        supplier.addEventListener('change', function () { loadProducts(''); });
        product.addEventListener('change', function () {
            // The catalog price is suggested; it is replaced only if the user has not typed their own cost.
            if (prices[product.value] !== undefined && (cost.value === '' || cost.value === autoCost)) {
                var minor = prices[product.value];
                autoCost = Math.floor(minor / 100) + '.' + String(minor % 100).padStart(2, '0'); cost.value = autoCost; show();
            }
        });
        [qty, cost, budget].forEach(function (el) { el.addEventListener('input', show); });
        if (supplier.value) { loadProducts(form.dataset.oldProduct); }
        show();
    })();
    </script>
    @endpush
</x-layout>
