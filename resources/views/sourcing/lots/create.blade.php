@use('Illuminate\Support\Str')
<x-layout title="New client lot">
    <x-page-header title="New client lot" :crumbs="[['Client Lots', route('sourcing.lots.index')], ['New', null]]">
        <x-slot:meta>Pick the project, add one or more products — each from any supplier — with their quantity and unit cost, then enter the client's budget for the whole lot. Delivery is added later, when you put the lot in order.</x-slot:meta>
    </x-page-header>

    <section class="panel">
        <form method="POST" action="{{ route('sourcing.client-lots.store') }}" id="client-lot" class="panel-body" data-products-url="{{ route('sourcing.suppliers.products', '__ID__') }}" data-old-items='@json(array_values((array) old('items', [])))'>@csrf
            <input type="hidden" name="submission_key" value="{{ Str::uuid() }}">
            <div class="form-grid">
                <div class="field"><label for="c-project">Project</label>
                    <select name="project_id" id="c-project" required>
                        <option value="">Choose a project…</option>
                        @foreach ($projects as $p)<option value="{{ $p->id }}" @selected((int) old('project_id') === $p->id)>{{ $p->name }} — {{ $p->client->name }}</option>@endforeach
                    </select></div>
            </div>

            <div class="table-wrap" style="margin-top:.9rem"><table class="table table-min-760" id="c-items">
                <thead><tr><th>Supplier</th><th>Product</th><th class="num">Quantity</th><th class="num">Unit base cost (BDT)</th><th class="num">Line total</th><th></th></tr></thead>
                <tbody></tbody>
            </table></div>
            <button type="button" class="btn btn-sm" id="c-add" style="margin-top:.6rem">+ Add another product</button>

            <div class="form-grid" style="margin-top:.9rem">
                <div class="field"><label for="c-budget">Client lot budget (BDT)</label><input name="client_budget" id="c-budget" inputmode="decimal" value="{{ old('client_budget') }}" required placeholder="Total agreed with the client for all products"></div>
            </div>

            <div class="lot-preview" aria-live="polite">
                <div><div class="label">Total base cost</div><div class="value" id="c-base">—</div></div>
                <div><div class="label">Client lot budget</div><div class="value" id="c-budget-out">—</div></div>
                <div><div class="label" id="c-profit-label">Estimated profit</div><div class="value" id="c-profit">—</div></div>
            </div>
            <p class="muted" style="margin-top:.6rem">Total base cost = the sum of quantity × unit base cost for every product. Estimated profit = client budget − total base cost (delivery is added when the lot is put in order).
                The budget is what the client agreed to pay for the whole lot — it is <strong>not</strong> recorded as money received.</p>

            <button class="btn btn-primary" style="margin-top:.9rem">Create lot</button>
        </form>
    </section>

    <template id="c-row">
        <tr>
            <td><select data-field="supplier_id" required aria-label="Supplier">
                <option value="">Choose a supplier…</option>
                @foreach ($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
            </select></td>
            <td><select data-field="supplier_product_id" required aria-label="Product" disabled><option value="">Choose a supplier first</option></select></td>
            <td class="num"><input data-field="quantity" inputmode="numeric" pattern="[1-9][0-9]*" required placeholder="e.g. 100" size="8" aria-label="Quantity"></td>
            <td class="num"><input data-field="unit_cost" inputmode="decimal" required placeholder="Cost per unit" size="10" aria-label="Unit base cost (BDT)"></td>
            <td class="num" data-total>—</td>
            <td class="num"><button type="button" class="btn btn-sm btn-danger" data-remove aria-label="Remove product">Remove</button></td>
        </tr>
    </template>

    @push('scripts')
    @include('sourcing.lots.partials.money-js')
    <script>
    (function () {
        var form = document.getElementById('client-lot');
        if (!form) { return; }
        var M = window.LotMoney, budget = document.getElementById('c-budget');
        var body = document.querySelector('#c-items tbody'), template = document.getElementById('c-row'), addButton = document.getElementById('c-add');
        var catalog = {}, prices = {}, nextIndex = 0;

        function rows() { return Array.prototype.slice.call(body.querySelectorAll('tr')); }
        function field(row, name) { return row.querySelector('[data-field="' + name + '"]'); }

        /** Each supplier's active products are fetched once and shared by every row that picks that supplier. */
        function productsOf(supplierId) {
            if (!catalog[supplierId]) {
                catalog[supplierId] = fetch(form.dataset.productsUrl.replace('__ID__', supplierId), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (list) { list.forEach(function (p) { prices[p.id] = p.price_minor; }); return list; })
                    .catch(function (e) { delete catalog[supplierId]; throw e; });
            }
            return catalog[supplierId];
        }

        function loadProducts(row, selected) {
            var supplier = field(row, 'supplier_id'), product = field(row, 'supplier_product_id');
            product.disabled = true;
            if (!supplier.value) { product.innerHTML = '<option value="">Choose a supplier first</option>'; show(); return; }
            product.innerHTML = '<option value="">Loading…</option>';
            var wanted = supplier.value;
            productsOf(wanted).then(function (list) {
                if (supplier.value !== wanted) { return; }
                product.innerHTML = '';
                var first = document.createElement('option'); first.value = ''; first.textContent = list.length ? 'Choose a product…' : 'This supplier has no active products'; product.appendChild(first);
                list.forEach(function (p) {
                    var o = document.createElement('option'); o.value = p.id; o.textContent = p.name + ' — ' + p.price; o.selected = String(p.id) === String(selected); product.appendChild(o);
                });
                product.disabled = !list.length;
            }).catch(function () { product.innerHTML = '<option value="">Could not load products</option>'; });
        }

        function show() {
            var base = 0, complete = rows().length > 0;
            rows().forEach(function (row) {
                var qv = field(row, 'quantity').value.trim();
                var q = /^[1-9]\d{0,9}$/.test(qv) ? parseInt(qv, 10) : null, c = M.toMinor(field(row, 'unit_cost').value);
                var line = q !== null && c !== null ? q * c : null;
                row.querySelector('[data-total]').textContent = line === null ? '—' : M.format(line);
                if (line === null) { complete = false; } else { base += line; }
            });
            var b = M.toMinor(budget.value);
            document.getElementById('c-base').textContent = complete ? M.format(base) : '—';
            document.getElementById('c-budget-out').textContent = b === null ? '—' : M.format(b);
            var out = document.getElementById('c-profit'), label = document.getElementById('c-profit-label');
            if (!complete || b === null) { out.textContent = '—'; out.classList.remove('is-loss'); label.textContent = 'Estimated profit'; return; }
            var profit = b - base;
            out.textContent = M.format(profit); out.classList.toggle('is-loss', profit < 0);
            label.textContent = profit < 0 ? 'Estimated loss' : 'Estimated profit';
        }

        function toggleRemove() {
            var only = rows().length === 1;
            rows().forEach(function (row) { row.querySelector('[data-remove]').hidden = only; });
        }

        function addRow(values) {
            values = values || {};
            var row = template.content.firstElementChild.cloneNode(true), index = nextIndex++;
            ['supplier_id', 'supplier_product_id', 'quantity', 'unit_cost'].forEach(function (name) { field(row, name).name = 'items[' + index + '][' + name + ']'; });
            var supplier = field(row, 'supplier_id'), product = field(row, 'supplier_product_id'), cost = field(row, 'unit_cost'), autoCost = '';
            // A new row starts with the previous row's supplier, the common case when several products come from one supplier.
            var last = rows().length ? field(rows()[rows().length - 1], 'supplier_id').value : '';
            supplier.value = values.supplier_id || (values.supplier_product_id ? '' : last);
            field(row, 'quantity').value = values.quantity || '';
            cost.value = values.unit_cost || '';
            supplier.addEventListener('change', function () { loadProducts(row, ''); });
            product.addEventListener('change', function () {
                // The catalog price is suggested; it is replaced only if the user has not typed their own cost.
                if (prices[product.value] !== undefined && (cost.value === '' || cost.value === autoCost)) {
                    var minor = prices[product.value];
                    autoCost = Math.floor(minor / 100) + '.' + String(minor % 100).padStart(2, '0'); cost.value = autoCost; show();
                }
            });
            row.querySelectorAll('input').forEach(function (el) { el.addEventListener('input', show); });
            row.querySelector('[data-remove]').addEventListener('click', function () { row.remove(); toggleRemove(); show(); });
            body.appendChild(row);
            toggleRemove();
            loadProducts(row, values.supplier_product_id || '');
            return row;
        }

        addButton.addEventListener('click', function () { var row = addRow(); field(row, field(row, 'supplier_id').value ? 'supplier_product_id' : 'supplier_id').focus(); });
        budget.addEventListener('input', show);

        // After a failed save, the rows come back as they were entered.
        var old = JSON.parse(form.dataset.oldItems || '[]');
        (old.length ? old : [{}]).forEach(function (values) { addRow(values); });
        show();
    })();
    </script>
    @endpush
</x-layout>
