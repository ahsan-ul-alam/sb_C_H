<?php

namespace App\Support;

/**
 * The one vocabulary of invoice categories shown to clients. The ERP has no category column on invoices or lines: its existing explicit signals are the name of the
 * segment a line is billed under and the invoice's free-text purpose. Those are mapped here ONLY when they equal a known label or alias exactly (after trimming and
 * lower-casing); nothing is inferred from product or line descriptions. Anything else stays "Needs classification" until Finance classifies the line.
 */
class InvoiceCategories
{
    public const UNCLASSIFIED = 'unclassified';

    public const PRODUCT = 'product_sourcing';

    /** @var array<string, string> */
    public const LABELS = [
        'product_sourcing' => 'Product sourcing',
        'onboarding' => 'Onboarding',
        'marketing' => 'Marketing',
        'branding' => 'Branding',
        'platform_service' => 'Platform & service fees',
        'transport' => 'Transportation & handling',
        'other' => 'Other services',
    ];

    /** @var array<string, list<string>> exact (normalised) labels staff already type into segment names and invoice purposes */
    public const ALIASES = [
        'product_sourcing' => ['product sourcing', 'product purchase', 'product purchases', 'product purchasing', 'product', 'products'],
        'onboarding' => ['onboarding', 'joining', 'joining fee'],
        'marketing' => ['marketing', 'marketing charges', 'advertising', 'ads'],
        'branding' => ['branding'],
        'platform_service' => ['platform fee', 'platform fees', 'service fee', 'service fees'],
        'transport' => ['transport', 'transportation', 'handling'],
        'other' => ['other', 'other services'],
    ];

    public const NEEDS_CLASSIFICATION = 'Uncategorized / Needs classification';

    public static function label(string $key): string
    {
        return $key === self::UNCLASSIFIED ? self::NEEDS_CLASSIFICATION : (self::LABELS[$key] ?? self::NEEDS_CLASSIFICATION);
    }

    /** The category key an existing explicit text maps to, or null when it is not an exact known label. */
    public static function fromText(?string $text): ?string
    {
        $needle = mb_strtolower(trim((string) $text));
        if ($needle === '') {
            return null;
        }
        foreach (self::ALIASES as $key => $aliases) {
            if ($needle === mb_strtolower(self::LABELS[$key]) || in_array($needle, $aliases, true)) {
                return $key;
            }
        }

        return null;
    }

    public static function isValid(string $key): bool
    {
        return $key === self::UNCLASSIFIED || array_key_exists($key, self::LABELS);
    }
}
