<?php

namespace App\Services;

use App\Models\SupplierProduct;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Replacing a product image is failure-safe: the new file is stored first, the product reference is then switched in one transaction, and the previous
 * file is deleted only after that commit. If storing or the update fails, the old file and the old reference are untouched and the new file is removed.
 */
class ProductImages
{
    public static function replace(SupplierProduct $product, UploadedFile $file): void
    {
        $new = $file->store('product-images');
        if (! is_string($new) || $new === '') {
            throw new RuntimeException('The image could not be stored; the existing image was kept.');
        }
        $old = $product->image_path;
        try {
            DB::transaction(fn () => $product->update(['image_path' => $new]));
        } catch (Throwable) {
            Storage::delete($new);
            $product->refresh();

            throw new RuntimeException('The image could not be saved; the existing image was kept.');
        }
        Audit::log('product.image', 'product '.$product->id, 'image replaced');
        if ($old && $old !== $new) {
            try {
                Storage::delete($old);
            } catch (Throwable) {
                // The reference already points at the new file; a leftover old file is harmless and can be cleaned up later.
            }
        }
    }
}
