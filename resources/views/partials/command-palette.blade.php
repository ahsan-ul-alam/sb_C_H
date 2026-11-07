{{-- Global command palette (Ctrl+K / Cmd+K). Markup only; behaviour is in public/js/command-palette.js. Results come from route('search', [], false), already filtered by the user's permissions. --}}
<dialog class="cmdk" id="cmdk" aria-label="Search the ERP" data-url="{{ route('search') }}">
    <div class="cmdk-box">
        <div class="cmdk-input">
            <x-icon name="search" />
            <input type="text" id="cmdk-input" role="combobox" aria-expanded="true" aria-controls="cmdk-list" aria-autocomplete="list" aria-label="Search" autocomplete="off" spellcheck="false" placeholder="Search clients, projects, invoices, lots, pages…">
            <kbd>Esc</kbd>
        </div>
        <div id="cmdk-list" class="cmdk-list" role="listbox" aria-label="Search results"></div>
        <div class="cmdk-foot"><span><kbd>↑</kbd> <kbd>↓</kbd> move</span><span><kbd>Enter</kbd> open</span><span><kbd>Esc</kbd> close</span></div>
        <p class="sr-only" id="cmdk-status" aria-live="polite"></p>
    </div>
</dialog>
