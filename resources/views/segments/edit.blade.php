@use('App\Support\Money')
@use('App\Support\ListFilters')
@php($received = $segment->receivedAmount())
<x-layout title="Edit segment">
    <x-page-header title="Edit segment"
        :crumbs="[['Projects', ListFilters::url('projects')], [$project->name, route('projects.show', $project)], [$segment->name, null]]">
        <x-slot:meta>Received so far: {{ Money::format($received, $project->currency) }}</x-slot:meta>
    </x-page-header>

    <form method="POST" action="{{ route('segments.update', $segment) }}" class="panel" style="max-width:720px">
        @csrf @method('PUT')
        <div class="form-section">
            <div class="form-grid">
                <div class="field">
                    <label for="name">Name<span class="req" aria-hidden="true">*</span></label>
                    <input type="text" id="name" name="name" value="{{ old('name', $segment->name) }}" required @class(['is-invalid' => $errors->has('name')])>
                    @error('name') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="amount">Agreed amount ({{ $project->currency }})<span class="req" aria-hidden="true">*</span></label>
                    <input type="text" id="amount" name="amount" value="{{ old('amount', Money::toDecimal($segment->amount)) }}" required inputmode="decimal" class="amount-input @error('amount') is-invalid @enderror">
                    <span class="hint">Cannot be lower than the amount already received.</span>
                    @error('amount') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field full">
                    <label for="description">Description <span class="opt">(optional)</span></label>
                    <textarea id="description" name="description">{{ old('description', $segment->description) }}</textarea>
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><x-icon name="check" /> Save segment</button>
            <a href="{{ route('projects.show', $project) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

    @if ($segment->canBeDeleted())
        <form method="POST" action="{{ route('segments.destroy', $segment) }}" data-confirm="Delete the segment “{{ $segment->name }}”?">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-danger btn-sm"><x-icon name="trash" class="icon-sm" /> Delete segment</button>
        </form>
    @else
        <p class="hint">This segment has payments or invoice lines, so it is kept for history and cannot be deleted.</p>
    @endif
</x-layout>
