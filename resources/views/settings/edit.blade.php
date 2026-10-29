<x-layout title="Business settings">
    <x-page-header title="Business settings">
        <x-slot:meta>Used in the sidebar, on every invoice and in the PDF footer.</x-slot:meta>
    </x-page-header>

    <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data" class="panel" style="max-width:900px">
        @csrf @method('PUT')

        <div class="form-section">
            <h2>Business details</h2>
            <p class="section-hint">Empty optional fields are hidden on invoices, together with their icons.</p>
            <div class="form-grid">
                <div class="field">
                    <label for="name">Business / display name<span class="req" aria-hidden="true">*</span></label>
                    <input type="text" id="name" name="name" value="{{ old('name', $settings->name) }}" required @class(['is-invalid' => $errors->has('name')])>
                    @error('name') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="default_currency">Default currency<span class="req" aria-hidden="true">*</span></label>
                    <input type="text" id="default_currency" name="default_currency" value="{{ old('default_currency', $settings->default_currency) }}" required maxlength="3" style="text-transform:uppercase" @class(['is-invalid' => $errors->has('default_currency')])>
                    <span class="hint">Used for new projects and as the dashboard default.</span>
                </div>
                <div class="field">
                    <label for="phone">Phone <span class="opt">(optional)</span></label>
                    <input type="text" id="phone" name="phone" value="{{ old('phone', $settings->phone) }}" inputmode="tel" placeholder="+8801XXXXXXXXX">
                </div>
                <div class="field">
                    <label for="email">Email <span class="opt">(optional)</span></label>
                    <input type="email" id="email" name="email" value="{{ old('email', $settings->email) }}" placeholder="billing@example.com" @class(['is-invalid' => $errors->has('email')])>
                    @error('email') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="website">Website <span class="opt">(optional)</span></label>
                    <input type="text" id="website" name="website" value="{{ old('website', $settings->website) }}" placeholder="www.example.com">
                </div>
                <div class="field full">
                    <label for="address">Address <span class="opt">(optional, shown in the invoice footer)</span></label>
                    <textarea id="address" name="address" rows="2" placeholder="Area, city">{{ old('address', $settings->address) }}</textarea>
                </div>
            </div>
        </div>

        <div class="form-section">
            <h2>Branding</h2>
            <p class="section-hint">PNG or JPG up to 2 MB. Without a logo, the business name is shown instead.</p>
            <div class="form-grid">
                <div class="field">
                    <label for="logo">Logo</label>
                    @if ($logo = $settings->logoImage(220, 64))
                        <div style="display:flex; gap:1rem; align-items:center; padding:.6rem; border:1px solid var(--border); border-radius:var(--radius-sm); margin-bottom:.4rem">
                            <img src="{{ $logo['src'] }}" alt="Current logo" width="{{ $logo['width'] }}" height="{{ $logo['height'] }}">
                        </div>
                        <label class="checkbox"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>
                    @endif
                    <input type="file" id="logo" name="logo" accept="image/png,image/jpeg" @class(['is-invalid' => $errors->has('logo')])>
                    <span class="hint">{{ $settings->logo_path ? 'Choose a file to replace the current logo.' : 'No logo uploaded.' }}</span>
                    @error('logo') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="watermark">Invoice watermark</label>
                    @if ($settings->watermark_path && ($watermark = $settings->watermarkImage(220, 80)))
                        <div style="display:flex; gap:1rem; align-items:center; padding:.6rem; border:1px solid var(--border); border-radius:var(--radius-sm); margin-bottom:.4rem">
                            <img src="{{ $watermark['src'] }}" alt="Current watermark" width="{{ $watermark['width'] }}" height="{{ $watermark['height'] }}">
                        </div>
                        <label class="checkbox"><input type="checkbox" name="remove_watermark" value="1"> Remove watermark image</label>
                    @endif
                    <input type="file" id="watermark" name="watermark" accept="image/png,image/jpeg" @class(['is-invalid' => $errors->has('watermark')])>
                    <span class="hint">Optional. When empty, the logo is used as the watermark.</span>
                    @error('watermark') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="favicon">Browser tab icon (favicon)</label>
                    <div style="display:flex; gap:.75rem; align-items:center; padding:.6rem; border:1px solid var(--border); border-radius:var(--radius-sm); margin-bottom:.4rem">
                        <img src="{{ $settings->faviconUrl() }}" alt="Current favicon" width="32" height="32">
                        <span class="hint">{{ $settings->favicon_path ? 'Custom icon' : ($settings->logo_path ? 'Using your logo' : 'Using the first letter of your business name') }}</span>
                    </div>
                    @if ($settings->favicon_path)
                        <label class="checkbox"><input type="checkbox" name="remove_favicon" value="1"> Remove custom icon</label>
                    @endif
                    <input type="file" id="favicon" name="favicon" accept="image/png,image/jpeg" @class(['is-invalid' => $errors->has('favicon')])>
                    <span class="hint">Optional. A square PNG works best; otherwise the logo is used.</span>
                    @error('favicon') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field full">
                    <input type="hidden" name="show_watermark" value="0">
                    <label class="checkbox"><input type="checkbox" class="switch" role="switch" name="show_watermark" value="1" @checked(old('show_watermark', $settings->show_watermark))> Show a faint watermark on invoices</label>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><x-icon name="check" /> Save settings</button>
        </div>
    </form>
</x-layout>
