<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'address', 'phone', 'email', 'website', 'show_watermark', 'default_currency', 'commission_bps'])]
class BusinessSetting extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'show_watermark' => 'boolean',
        ];
    }

    /**
     * The single settings row, created on first use.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], ['name' => config('app.name'), 'default_currency' => 'BDT']);
    }

    /**
     * Favicon URL, versioned so browsers pick up branding changes immediately.
     */
    public function faviconUrl(): string
    {
        return route('favicon', ['v' => $this->updated_at?->timestamp ?? 0]);
    }

    /**
     * Logo as an inline data URI so it renders in both HTML and PDF without a public symlink.
     */
    public function logoDataUri(): ?string
    {
        return $this->logoImage(1, 1)['src'] ?? null;
    }

    /**
     * Logo fitted inside the given box, keeping its proportions.
     *
     * @return array{src: string, width: int, height: int}|null
     */
    public function logoImage(int $maxWidth, int $maxHeight): ?array
    {
        return $this->fittedImage($this->logo_path, $maxWidth, $maxHeight);
    }

    /**
     * Watermark image: the dedicated watermark if uploaded, otherwise the logo. Null when disabled.
     *
     * @return array{src: string, width: int, height: int}|null
     */
    public function watermarkImage(int $maxWidth, int $maxHeight): ?array
    {
        if (! $this->show_watermark) {
            return null;
        }

        return $this->fittedImage($this->watermark_path, $maxWidth, $maxHeight)
            ?? $this->fittedImage($this->logo_path, $maxWidth, $maxHeight);
    }

    /**
     * @return array{src: string, width: int, height: int}|null
     */
    private function fittedImage(?string $path, int $maxWidth, int $maxHeight): ?array
    {
        $disk = Storage::disk('local');

        if (! $path || ! $disk->exists($path)) {
            return null;
        }

        $contents = $disk->get($path);
        $size = @getimagesizefromstring($contents);

        if (! $size || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        $scale = min($maxWidth / $size[0], $maxHeight / $size[1]);

        return [
            'src' => 'data:'.$size['mime'].';base64,'.base64_encode($contents),
            'width' => max(1, (int) round($size[0] * $scale)),
            'height' => max(1, (int) round($size[1] * $scale)),
        ];
    }
}
