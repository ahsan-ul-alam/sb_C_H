<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * A named or custom date range read from ?range=…&from=…&to=… query parameters.
 */
class DateRange
{
    public const PRESETS = [
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'last_30_days' => 'Last 30 days',
        'this_quarter' => 'This quarter',
        'this_year' => 'This year',
        'last_year' => 'Last year',
        'custom' => 'Custom range',
    ];

    public function __construct(
        public string $preset,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    public static function fromRequest(Request $request, string $default = 'this_month'): self
    {
        $preset = array_key_exists((string) $request->query('range'), self::PRESETS) ? (string) $request->query('range') : $default;
        $today = CarbonImmutable::today();

        if ($preset === 'custom') {
            $from = self::parse($request->query('from')) ?? $today->startOfMonth();
            $to = self::parse($request->query('to')) ?? $today;

            return $from->gt($to) ? new self('custom', $to, $from) : new self('custom', $from, $to);
        }

        return match ($preset) {
            'last_month' => new self($preset, $today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()),
            'last_30_days' => new self($preset, $today->subDays(29), $today),
            'this_quarter' => new self($preset, $today->startOfQuarter(), $today->endOfQuarter()->startOfDay()),
            'this_year' => new self($preset, $today->startOfYear(), $today->endOfYear()->startOfDay()),
            'last_year' => new self($preset, $today->subYear()->startOfYear(), $today->subYear()->endOfYear()->startOfDay()),
            default => new self('this_month', $today->startOfMonth(), $today->endOfMonth()->startOfDay()),
        };
    }

    public function label(): string
    {
        return $this->from->format('j M Y').' – '.$this->to->format('j M Y');
    }

    /**
     * Query parameters that reproduce this range on another page.
     *
     * @return array{range: string, from?: string, to?: string}
     */
    public function query(): array
    {
        return $this->preset === 'custom'
            ? ['range' => 'custom', 'from' => $this->from->toDateString(), 'to' => $this->to->toDateString()]
            : ['range' => $this->preset];
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
