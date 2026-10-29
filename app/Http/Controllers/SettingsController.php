<?php

namespace App\Http\Controllers;

use App\Models\BusinessSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        return view('settings.edit', ['settings' => BusinessSetting::current()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->merge(['default_currency' => strtoupper(trim((string) $request->input('default_currency')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'default_currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
            'watermark' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
            'remove_watermark' => ['nullable', 'boolean'],
            'favicon' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:1024'],
            'remove_favicon' => ['nullable', 'boolean'],
        ]);

        $settings = BusinessSetting::current();
        $settings->fill($data);
        $settings->show_watermark = $request->boolean('show_watermark');

        $this->replaceImage($request, $settings, 'logo', 'logo_path');
        $this->replaceImage($request, $settings, 'watermark', 'watermark_path');
        $this->replaceImage($request, $settings, 'favicon', 'favicon_path');

        $settings->touch();
        $settings->save();

        return redirect()->route('settings.edit')->with('status', 'Settings saved.');
    }

    /**
     * Handle upload/replace/remove of an image stored on the private local disk.
     */
    private function replaceImage(Request $request, BusinessSetting $settings, string $field, string $column): void
    {
        if (! $request->boolean('remove_'.$field) && ! $request->hasFile($field)) {
            return;
        }

        if ($settings->{$column}) {
            Storage::disk('local')->delete($settings->{$column});
        }

        $settings->{$column} = $request->hasFile($field)
            ? $request->file($field)->store('branding', 'local')
            : null;
    }
}
