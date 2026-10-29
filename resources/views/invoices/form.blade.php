@use('App\Support\Money')
@use('App\Support\ListFilters')
@php($editing = $invoice->exists)
<x-layout :title="$editing ? 'Edit '.$invoice->number : 'New invoice'">
    <x-page-header :title="$editing ? 'Edit invoice '.$invoice->number : 'New invoice'"
        :crumbs="$editing
            ? [['Invoices', ListFilters::url('invoices')], [$invoice->number, route('invoices.show', $invoice)], ['Edit', null]]
            : [['Projects', ListFilters::url('projects')], [$project->name, route('projects.show', $project)], ['New invoice', null]]">
        <x-slot:meta>
            <span>Client: <a href="{{ route('clients.show', $project->client) }}">{{ $project->client->name }}</a></span>
            <span>Project: <a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></span>
            <span>Currency: <strong>{{ $project->currency }}</strong></span>
        </x-slot:meta>
        @if ($editing)
            <x-slot:badges><x-invoice-status :invoice="$invoice" /></x-slot:badges>
        @endif
    </x-page-header>

    <form method="POST" action="{{ $editing ? route('invoices.update', $invoice) : route('projects.invoices.store', $project) }}" id="invoice-form" class="panel">
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        <div class="form-section">
            <h2>Invoice details</h2>
            <div class="form-grid">
                <div class="field">
                    <label for="number">Invoice number<span class="req" aria-hidden="true">*</span></label>
                    <input type="text" id="number" name="number" value="{{ old('number', $invoice->number) }}" required maxlength="32" @class(['is-invalid' => $errors->has('number')])>
                    @error('number') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="issued_on">Issue date<span class="req" aria-hidden="true">*</span></label>
                    <input type="date" id="issued_on" name="issued_on" value="{{ old('issued_on', $invoice->issued_on?->format('Y-m-d')) }}" required>
                </div>
                <div class="field">
                    <label for="due_on">Due date <span class="opt">(optional)</span></label>
                    <input type="date" id="due_on" name="due_on" value="{{ old('due_on', $invoice->due_on?->format('Y-m-d')) }}" @class(['is-invalid' => $errors->has('due_on')])>
                    @error('due_on') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="purpose">Purpose <span class="opt">(shown on the invoice)</span></label>
                    <input type="text" id="purpose" name="purpose" value="{{ old('purpose', $invoice->purpose) }}" maxlength="255" placeholder="{{ $project->name }}">
                </div>
            </div>
        </div>

        <div class="form-section" style="padding-left:0; padding-right:0; padding-bottom:0">
            <div style="padding:0 1.1rem">
                <h2>Items</h2>
                <p class="section-hint">Choose a project segment to link the line to it, or keep “Manual item” and type your own. Invoicing a segment never adds to the project's agreed charges.</p>
                @error('items') <p class="error">{{ $message }}</p> @enderror
                @foreach ($errors->get('items.*') as $messages)
                    <p class="error">{{ $messages[0] }}</p>
                @endforeach
            </div>
            <div class="table-wrap">
                <table class="table items-table" style="min-width:820px">
                    <thead>
                        <tr>
                            <th style="width:30px; padding-left:1.1rem">#</th>
                            <th style="width:180px">Segment</th>
                            <th>Description<span class="req" aria-hidden="true">*</span></th>
                            <th style="width:84px">Qty<span class="req" aria-hidden="true">*</span></th>
                            <th style="width:88px">Unit</th>
                            <th style="width:130px">Unit price<span class="req" aria-hidden="true">*</span></th>
                            <th class="num" style="width:120px">Line total</th>
                            <th style="width:48px"><span class="sr-only">Remove</span></th>
                        </tr>
                    </thead>
                    <tbody id="items"></tbody>
                </table>
            </div>
            <div style="padding:.75rem 1.1rem">
                <button type="button" class="btn" id="add-item"><x-icon name="plus" class="icon-sm" /> Add item</button>
            </div>
            <div class="total-bar">
                <span>Total</span>
                <span><span class="muted" style="font-weight:500">{{ $project->currency }}</span> <span id="grand-total">0.00</span></span>
            </div>
        </div>

        <div class="form-section">
            <div class="field">
                <label for="notes">Notes <span class="opt">(optional, printed on the invoice)</span></label>
                <textarea id="notes" name="notes" placeholder="Payment instructions, delivery terms, thanks…">{{ old('notes', $invoice->notes) }}</textarea>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" name="after" value="edit" class="btn btn-primary"><x-icon name="check" /> {{ $editing ? 'Save' : 'Create invoice' }}</button>
            <button type="submit" name="after" value="preview" class="btn"><x-icon name="eye" class="icon-sm" /> Save &amp; preview</button>
            <span class="spacer"></span>
            <a href="{{ $editing ? route('invoices.show', $invoice) : route('projects.show', $project) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

    @push('scripts')
        <script>
            (function () {
                const segments = @json($segments->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'amount' => Money::toDecimal($s->amount)])->values());
                const initialItems = @json(array_values($items));
                const tbody = document.getElementById('items');
                let counter = 0;

                function toCents(value) {
                    const match = String(value || '').replace(/,/g, '').trim().match(/^(\d+)(?:\.(\d{1,2}))?$/);
                    if (!match) return null;
                    return parseInt(match[1], 10) * 100 + parseInt((match[2] || '').padEnd(2, '0') || '0', 10);
                }
                function format(cents) {
                    return (cents / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
                function escapeHtml(value) {
                    return String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
                }
                function renumber() {
                    tbody.querySelectorAll('tr').forEach((row, index) => row.querySelector('.line-no').textContent = index + 1);
                }
                function recalc() {
                    let total = 0;
                    tbody.querySelectorAll('tr').forEach(function (row) {
                        const qty = parseFloat(row.querySelector('.qty').value) || 0;
                        const price = toCents(row.querySelector('.price').value);
                        row.querySelector('.price').classList.toggle('is-invalid', row.querySelector('.price').value !== '' && price === null);
                        const amount = Math.round(qty * (price || 0));
                        row.querySelector('.amount').textContent = format(amount);
                        total += amount;
                    });
                    document.getElementById('grand-total').textContent = format(total);
                }
                function addRow(item) {
                    const i = counter++;
                    const options = ['<option value="">Manual item</option>'].concat(segments.map(s =>
                        `<option value="${s.id}" ${String(item.segment_id) === String(s.id) ? 'selected' : ''}>${escapeHtml(s.name)}</option>`
                    )).join('');
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td class="line-no muted" style="padding-left:1.1rem; padding-top:1rem"></td>
                        <td><select name="items[${i}][segment_id]" class="segment" aria-label="Segment">${options}</select></td>
                        <td><textarea name="items[${i}][description]" class="description" rows="1" required maxlength="1000" placeholder="What is being charged" aria-label="Description">${escapeHtml(item.description)}</textarea></td>
                        <td><input type="text" name="items[${i}][quantity]" class="qty" value="${escapeHtml(item.quantity || '1')}" required inputmode="decimal" aria-label="Quantity"></td>
                        <td><input type="text" name="items[${i}][unit]" value="${escapeHtml(item.unit)}" maxlength="20" placeholder="pcs" aria-label="Unit"></td>
                        <td><input type="text" name="items[${i}][unit_price]" class="price" value="${escapeHtml(item.unit_price)}" required inputmode="decimal" placeholder="0.00" aria-label="Unit price"></td>
                        <td class="num amount">0.00</td>
                        <td><button type="button" class="btn btn-sm btn-ghost remove" title="Remove item"><svg class="icon icon-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg><span class="sr-only">Remove item</span></button></td>`;
                    tbody.appendChild(row);

                    row.querySelector('.segment').addEventListener('change', function () {
                        const segment = segments.find(s => String(s.id) === this.value);
                        if (segment) {
                            row.querySelector('.description').value = segment.name;
                            row.querySelector('.price').value = segment.amount;
                            row.querySelector('.qty').value = '1';
                            recalc();
                        }
                    });
                    row.querySelector('.remove').addEventListener('click', function () {
                        if (tbody.children.length === 1) {
                            alert('An invoice needs at least one item.');
                            return;
                        }
                        row.remove();
                        renumber();
                        recalc();
                    });
                    row.querySelectorAll('.qty, .price').forEach(input => input.addEventListener('input', recalc));
                    renumber();
                }

                document.getElementById('add-item').addEventListener('click', function () {
                    addRow({ segment_id: '', description: '', quantity: '1', unit: '', unit_price: '' });
                    tbody.lastElementChild.querySelector('.description').focus();
                });

                initialItems.forEach(addRow);
                recalc();
            })();
        </script>
    @endpush
</x-layout>
