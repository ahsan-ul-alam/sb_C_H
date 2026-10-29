<?php

namespace App\Http\Controllers;

use App\Models\BusinessSetting;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class FaviconController extends Controller
{
    private const SIZE = 64;

    /**
     * Serve the favicon from business settings: the uploaded favicon, else the logo fitted
     * into a square, else a lettermark of the business name. URLs are versioned by the
     * settings' updated_at, so the response can be cached.
     */
    public function __invoke(): Response
    {
        $settings = BusinessSetting::current();
        $headers = ['Cache-Control' => 'public, max-age=604800'];

        foreach ([$settings->favicon_path, $settings->logo_path] as $path) {
            if ($png = $this->squarePng($path)) {
                return response($png, 200, $headers + ['Content-Type' => 'image/png']);
            }
        }

        return response($this->lettermark($settings->name ?: config('app.name')), 200, $headers + ['Content-Type' => 'image/svg+xml']);
    }

    /**
     * Fit an uploaded image into a transparent square PNG, keeping its proportions.
     */
    private function squarePng(?string $path): ?string
    {
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $source = @imagecreatefromstring(Storage::disk('local')->get($path));

        if (! $source) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(self::SIZE / $width, self::SIZE / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $source, intdiv(self::SIZE - $targetWidth, 2), intdiv(self::SIZE - $targetHeight, 2), 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagepng($canvas);

        return ob_get_clean() ?: null;
    }

    private function lettermark(string $name): string
    {
        $letter = htmlspecialchars(mb_strtoupper(mb_substr(trim($name), 0, 1)) ?: 'C', ENT_XML1);

        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
                <rect width="64" height="64" rx="14" fill="#f0aa1d"/>
                <text x="32" y="45" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="38" font-weight="700" fill="#23211d">{$letter}</text>
            </svg>
            SVG;
    }
}
