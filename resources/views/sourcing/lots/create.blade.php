@use('Illuminate\Support\Str')
<x-layout title="New sourcing lot">
    <x-page-header title="New sourcing lot" :crumbs="[['Sourcing Lots', route('sourcing.lots.index')], ['New', null]]">
        <x-slot:meta>Choose the supplier, add its products, enter quantities, the delivery cost and your profit per unit. Costs, the delivery split and client prices are worked out for you.</x-slot:meta>
    </x-page-header>

    <section class="panel">
        <form method="POST" action="{{ route('sourcing.lots.store') }}" id="quick-lot" class="panel-body" data-preview-url="{{ route('sourcing.lots.preview') }}" data-products-url="{{ route('sourcing.suppliers.products', '__ID__') }}">@csrf
            <input type="hidden" name="submission_key" value="{{ Str::uuid() }}">
            <div class="form-grid">
                <div class="field"><label for="q-supplier">1. Supplier</label>
                    <select name="supplier_id" id="q-supplier" required>
                        <option value="">Choose a supplier…</option>
                        @foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected((int) old('supplier_id') === $s->id)>{{ $s->name }}</option>@endforeach
                    </select></div>
                <div class="field"><label for="q-product">2. Add a product</label>
                    <div style="display:flex; gap:.4rem"><select id="q-product" disabled style="flex:1"><option value="">Choose a supplier first</option></select><button type="button" class="btn" id="q-add" disabled>Add</button></div></div>
                <div class="field"><label for="q-delivery">3. Total delivery / transport cost</label><input name="delivery_cost" id="q-delivery" inputmode="decimal" value="{{ old('delivery_cost', '0') }}" placeholder="0"></div>
            </div>

            <div class="table-wrap" style="margin-top:1rem"><table class="table table-min-760" id="q-table">
                <thead><tr><th>Product</th><th class="num">Base cost / unit</th><th class="num">4. Quantity</th><th class="num">Purchase</th><th class="num">Delivery share</th><th class="num">Landed / unit</th>
                    <th class="num">5. Profit / unit</th><th class="num">Client price / unit</th><th class="num">Client total</th><th class="num">Expected profit</th><th></th></tr></thead>
                <tbody id="q-rows"><tr id="q-empty"><td colspan="11" class="muted">No products yet — choose a supplier and add products.</td></tr></tbody>
                <tfoot><tr><td colspan="3">Totals</td><td class="num" id="t-purchase">—</td><td class="num" id="t-delivery">—</td><td class="num" id="t-landed" title="Total landed cost">—</td><td></td><td></td><td class="num strong" id="t-selling">—</td><td class="num strong" id="t-profit">—</td><td></td></tr></tfoot>
            </table></div>
            <p class="muted" id="q-note">Landed cost = purchase + delivery share. Client price = landed cost per unit + your profit, rounded to the paisa and never below cost. The same calculation runs again when you save.</p>
            <p class="text-danger" id="q-error" role="alert"></p>

            <details style="margin-top:.5rem"><summary>Optional details</summary>
                <div class="form-grid" style="margin-top:.7rem">
                    <div class="field"><label>Lot title</label><input name="title" value="{{ old('title') }}" placeholder="Leave blank to name it automatically"></div>
                    <div class="field"><label>Purchase order no.</label><input name="purchase_order" value="{{ old('purchase_order') }}"></div>
                    <div class="field"><label>Client project this is for</label>
                        <select name="project_id"><option value="">Choose later</option>@foreach ($projects as $p)<option value="{{ $p->id }}" @selected((int) old('project_id') === $p->id)>{{ $p->name }} — {{ $p->client->name }}</option>@endforeach</select></div>
                    <div class="field"><label>Notes</label><input name="notes" value="{{ old('notes') }}"></div>
                </div>
            </details>
            <button class="btn btn-primary" id="q-save" style="margin-top:.9rem" disabled>Save lot</button>
        </form>
    </section>

    <details class="panel"><summary class="panel-head" style="cursor:pointer"><h2>Advanced: start an empty lot</h2></summary>
        <form method="POST" action="{{ route('sourcing.lots.store') }}" class="panel-body">@csrf
            <div class="form-grid">
                <div class="field"><label>Title</label><input name="title" required placeholder="e.g. Packaging boxes — October"></div>
                <div class="field"><label>Shared-cost allocation</label><select name="allocation_basis"><option value="value">By purchase value (default)</option><option value="quantity">By quantity</option></select></div>
                <div class="field"><label>Purchase order no.</label><input name="purchase_order"></div>
                <div class="field"><label>Client project</label><select name="project_id"><option value="">Choose later</option>@foreach ($projects as $p)<option value="{{ $p->id }}">{{ $p->name }} — {{ $p->client->name }}</option>@endforeach</select></div>
            </div>
            <div class="field" style="margin-top:.9rem"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
            <button class="btn" style="margin-top:.9rem">Create empty lot</button>
        </form>
    </details>

    @push('scripts')
    <script>
    (function () {
        var form = document.getElementById('quick-lot');
        if (!form) { return; }
        var supplier = document.getElementById('q-supplier'), picker = document.getElementById('q-product'), addBtn = document.getElementById('q-add');
        var rows = document.getElementById('q-rows'), empty = document.getElementById('q-empty'), saveBtn = document.getElementById('q-save'), errBox = document.getElementById('q-error');
        var delivery = document.getElementById('q-delivery'), products = {}, counter = 0, timer = null, seq = 0;
        var keys = ['purchase', 'delivery', 'unit_cost', 'price', 'selling', 'profit'];
        var token = function () { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; };
        var cell = function (tr, key) { return tr.querySelector('[data-c="' + key + '"]'); };

        function loadProducts() {
            products = {}; rows.querySelectorAll('tr[data-id]').forEach(function (r) { r.remove(); }); empty.style.display = '';
            picker.innerHTML = ''; addBtn.disabled = true; recalc();
            if (!supplier.value) { picker.disabled = true; picker.innerHTML = '<option value="">Choose a supplier first</option>'; return; }
            picker.disabled = true; picker.innerHTML = '<option value="">Loading…</option>';
            fetch(form.dataset.productsUrl.replace('__ID__', supplier.value), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (list) {
                    picker.innerHTML = ''; var first = document.createElement('option'); first.value = ''; first.textContent = list.length ? 'Choose a product…' : 'This supplier has no products yet'; picker.appendChild(first);
                    list.forEach(function (p) { products[p.id] = p; var o = document.createElement('option'); o.value = p.id; o.textContent = p.name + ' — ' + p.price; picker.appendChild(o); });
                    picker.disabled = !list.length; addBtn.disabled = !list.length;
                }).catch(function () { picker.innerHTML = '<option value="">Could not load products</option>'; });
        }

        function addRow() {
            var p = products[picker.value];
            if (!p || rows.querySelector('tr[data-id="' + p.id + '"]')) { return; }
            var n = counter++, tr = document.createElement('tr'); tr.dataset.id = p.id;
            tr.innerHTML = '<td></td><td class="num" data-c="base"></td><td class="num"><input type="text" inputmode="decimal" size="6" data-q required></td>'
                + '<td class="num" data-c="purchase">—</td><td class="num" data-c="delivery">—</td><td class="num" data-c="unit_cost">—</td>'
                + '<td class="num"><input type="text" inputmode="decimal" size="6" data-p value="0"></td><td class="num" data-c="price">—</td><td class="num" data-c="selling">—</td><td class="num" data-c="profit">—</td>'
                + '<td class="num"><button type="button" class="btn btn-sm btn-ghost" data-remove aria-label="Remove">✕</button></td>';
            tr.children[0].textContent = p.name;
            cell(tr, 'base').textContent = p.price;
            var hid = document.createElement('input'); hid.type = 'hidden'; hid.name = 'items[' + n + '][product_id]'; hid.value = p.id; tr.children[0].appendChild(hid);
            tr.querySelector('[data-q]').name = 'items[' + n + '][quantity]';
            tr.querySelector('[data-p]').name = 'items[' + n + '][profit]';
            rows.appendChild(tr); empty.style.display = 'none'; tr.querySelector('[data-q]').focus(); recalc();
        }

        function recalc() { clearTimeout(timer); timer = setTimeout(run, 250); }
        function setTotals(t) {
            document.getElementById('t-purchase').textContent = t ? t.purchase : '—'; document.getElementById('t-delivery').textContent = t ? t.delivery : '—';
            document.getElementById('t-landed').textContent = t ? t.landed : '—'; document.getElementById('t-selling').textContent = t ? t.selling : '—'; document.getElementById('t-profit').textContent = t ? t.profit : '—';
        }
        function run() {
            var mine = ++seq, body = { supplier_id: supplier.value, delivery_cost: delivery.value, items: [] };
            rows.querySelectorAll('tr[data-id]').forEach(function (tr) { body.items.push({ product_id: tr.dataset.id, quantity: tr.querySelector('[data-q]').value, profit: tr.querySelector('[data-p]').value }); });
            if (!body.items.length) { setTotals(null); saveBtn.disabled = true; errBox.textContent = ''; return; }
            fetch(form.dataset.previewUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token() }, body: JSON.stringify(body) })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                .then(function (res) {
                    if (mine !== seq) { return; }
                    if (!res.ok) { errBox.textContent = res.j.message || 'Check the numbers you entered.'; saveBtn.disabled = true; return; }
                    errBox.textContent = ''; var done = {};
                    res.j.rows.forEach(function (r) {
                        var tr = rows.querySelector('tr[data-id="' + r.product_id + '"]'); if (!tr) { return; } done[r.product_id] = 1;
                        keys.forEach(function (k) { cell(tr, k).textContent = r[k]; });
                    });
                    rows.querySelectorAll('tr[data-id]').forEach(function (tr) { if (!done[tr.dataset.id]) { keys.forEach(function (k) { cell(tr, k).textContent = '—'; }); } });
                    setTotals(res.j.totals); saveBtn.disabled = res.j.rows.length !== body.items.length;
                }).catch(function () { errBox.textContent = 'Could not calculate right now. Check your connection.'; saveBtn.disabled = true; });
        }

        supplier.addEventListener('change', loadProducts);
        addBtn.addEventListener('click', addRow);
        delivery.addEventListener('input', recalc);
        rows.addEventListener('input', recalc);
        rows.addEventListener('click', function (e) { var b = e.target.closest('[data-remove]'); if (b) { b.closest('tr').remove(); if (!rows.querySelector('tr[data-id]')) { empty.style.display = ''; } recalc(); } });
        if (supplier.value) { loadProducts(); }
    })();
    </script>
    @endpush
</x-layout>
