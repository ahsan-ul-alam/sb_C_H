@use('Illuminate\Support\Str')
<x-layout title="New client lot">
    <x-page-header title="New client lot" :crumbs="[['Client Lots', route('sourcing.lots.index')], ['New', null]]">
        <x-slot:meta>Pick the supplier and project, add one or more products with their quantity and unit cost, then enter the client's budget for the whole lot. Delivery is added later, when you put the lot in order.</x-slot:meta>
    </x-page-header>

    <section class="panel">
        <form method="POST" action="{{ route('sourcing.client-lots.store') }}" id="client-lot" class="panel-body" data-products-url="{{ route('sourcing.suppliers.products', '__ID__') }}" data-old-items='@json(array_values((array) old('items', [])))'>@csrf
            <input type="hidden" name="submission_key" value="{{ Str::uuid() }}">
            <div class="form-grid">
                <div class="field"><label for="c-supplier">Supplier</label>
                    <select name="supplier_id" id="c-supplier" required>
                        <option value="">Choose a supplier…</option>
                        @foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected((int) old('supplier_id') === $s->id)>{{ $s->name }}</option>@endforeach
                    </select></div>
                <div class="field"><label for="c-project">Project</label>
                    <select name="project_id" id="c-project" required>
                        <option value="">Choose a project…</option>
                        @foreach ($projects as $p)<option value="{{ $p->id }}" @selected((int) old('project_id') === $p->id)>{{ $p->name }} — {{ $p->client->name }}</option>@endforeach
                    </select></div>
            </div>

            <div class="table-wrap" style="margin-top:.9rem"><table class="table" id="c-items">
                <thead><tr><th>Product</th><th class="num">Quantity</th><th class="num">Unit base cost (BDT)</th><th class="num">Line total</th><th></th></tr></thead>
                <tbody></tbody>
            </table></div>
            <button type="button" class="btn btn-sm" id="c-add" style="margin-top:.6rem" disabled>+ Add another product</button>

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
            <td><select data-field="supplier_product_id" required aria-label="Product"></select></td>
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
        var M = window.LotMoney, supplier = document.getElementById('c-supplier'), budget = document.getElementById('c-budget');
        var body = document.querySelector('#c-items tbody'), template = document.getElementById('c-row'), addButton = document.getElementById('c-add');
        var products = [], prices = {}, nextIndex = 0, loaded = false;

        function rows() { return Array.prototype.slice.call(body.querySelectorAll('tr')); }
        function field(row, name) { return row.querySelector('[data-field="' + name + '"]'); }

        function fillOptions(select, selected) {
            select.innerHTML = '';
            var first = document.createElement('option'); first.value = '';
            first.textContent = !supplier.value ? 'Choose a supplier first' : (!loaded ? 'Loading…' : (products.length ? 'Choose a product…' : 'This supplier has no active products'));
            select.appendChild(first);
            products.forEach(function (p) {
                var o = document.createElement('option'); o.value = p.id; o.textContent = p.name + ' — ' + p.price; o.selected = String(p.id) === String(selected); select.appendChild(o);
            });
            select.disabled = !products.length;
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

        function renumber() {
            var only = rows().length === 1;
            rows().forEach(function (row) { row.querySelector('[data-remove]').hidden = only; });
        }

        function addRow(values) {
            values = values || {};
            var row = template.content.firstElementChild.cloneNode(true), index = nextIndex++;
            ['supplier_product_id', 'quantity', 'unit_cost'].forEach(function (name) { field(row, name).name = 'items[' + index + '][' + name + ']'; });
            field(row, 'quantity').value = values.quantity || '';
            field(row, 'unit_cost').value = values.unit_cost || '';
            var select = field(row, 'supplier_product_id'), cost = field(row, 'unit_cost'), autoCost = '';
            fillOptions(select, values.supplier_product_id);
            select.addEventListener('change', function () {
                // The catalog price is suggested; it is replaced only if the user has not typed their own cost.
                if (prices[select.value] !== undefined && (cost.value === '' || cost.value === autoCost)) {
                    var minor = prices[select.value];
                    autoCost = Math.floor(minor / 100) + '.' + String(minor % 100).padStart(2, '0'); cost.value = autoCost; show();
                }
            });
            row.querySelectorAll('input').forEach(function (el) { el.addEventListener('input', show); });
            row.querySelector('[data-remove]').addEventListener('click', function () { row.remove(); renumber(); show(); });
            body.appendChild(row);
            renumber(); show();
            return row;
        }

        function loadProducts() {
            products = []; prices = {}; loaded = false; addButton.disabled = true;
            rows().forEach(function (row) { fillOptions(field(row, 'supplier_product_id'), ''); });
            if (!supplier.value) { return; }
            fetch(form.dataset.productsUrl.replace('__ID__', supplier.value), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (list) {
                    products = list; loaded = true;
                    list.forEach(function (p) { prices[p.id] = p.price_minor; });
                    rows().forEach(function (row) { var s = field(row, 'supplier_product_id'); fillOptions(s, s.dataset.selected || ''); delete s.dataset.selected; });
                    addButton.disabled = !list.length;
                }).catch(function () {
                    rows().forEach(function (row) { field(row, 'supplier_product_id').innerHTML = '<option value="">Could not load products</option>'; });
                });
        }

        addButton.addEventListener('click', function () { field(addRow(), 'supplier_product_id').focus(); });
        supplier.addEventListener('change', loadProducts);
        budget.addEventListener('input', show);

        // After a failed save, the rows come back as they were entered.
        var old = JSON.parse(form.dataset.oldItems || '[]');
        (old.length ? old : [{}]).forEach(function (values) {
            var row = addRow(values);
            if (values.supplier_product_id) { field(row, 'supplier_product_id').dataset.selected = values.supplier_product_id; }
        });
        if (supplier.value) { loadProducts(); }
        show();
    })();
    </script>
    @endpush
</x-layout>
