@props(['labels', 'series', 'title'])
@use('App\Support\Money')
@php
    // series: list of ['name' => string, 'color' => css colour, 'values' => list<int> minor units, same length as labels]
    $all = collect($series)->flatMap(fn ($s) => $s['values'])->values();
    $max = max(1, (int) $all->max());
    $min = min(0, (int) $all->min());
    $span = $max - $min;
    $W = 720; $H = 220; $padL = 6; $padR = 6; $padT = 12; $padB = 26;
    $plotH = $H - $padT - $padB;
    $n = max(1, count($labels));
    $band = ($W - $padL - $padR) / $n;
    $k = max(1, count($series));
    $barW = max(2, min(24, $band * 0.78 / $k));
    $y = fn (int $v): float => $padT + $plotH * ($max - $v) / $span;
    $zero = $y(0);
    $every = max(1, (int) ceil($n / 12));
@endphp
<figure class="series-chart" style="margin:0">
    <svg viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="{{ $title }}" style="width:100%; height:auto; display:block">
        <line x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $zero }}" y2="{{ $zero }}" stroke="var(--border-strong)" stroke-width="1" />
        @foreach ($labels as $i => $label)
            @foreach ($series as $j => $s)
                @php
                    $v = (int) ($s['values'][$i] ?? 0);
                    $x = $padL + $band * $i + ($band - $k * $barW) / 2 + $j * $barW;
                    $yv = $y($v);
                @endphp
                <rect x="{{ round($x, 2) }}" y="{{ round(min($yv, $zero), 2) }}" width="{{ round($barW - 1, 2) }}" height="{{ round(max(abs($zero - $yv), $v !== 0 ? 1 : 0), 2) }}" rx="2" fill="{{ $s['color'] }}">
                    <title>{{ $label }} · {{ $s['name'] }}: {{ Money::format($v, config('finance.currency')) }}</title>
                </rect>
            @endforeach
            @if ($i % $every === 0)
                <text x="{{ round($padL + $band * ($i + .5), 2) }}" y="{{ $H - 8 }}" text-anchor="middle" font-size="11" fill="var(--muted)">{{ \Carbon\CarbonImmutable::createFromFormat('Y-m', $label)->format('M') }}</text>
            @endif
        @endforeach
    </svg>
    <figcaption style="display:flex; gap:1rem; flex-wrap:wrap; font-size:.8rem; color:var(--muted); margin-top:.4rem">
        @foreach ($series as $s)
            <span><span aria-hidden="true" style="display:inline-block; width:.7rem; height:.7rem; border-radius:2px; background:{{ $s['color'] }}; margin-right:.3rem"></span>{{ $s['name'] }}</span>
        @endforeach
    </figcaption>
    <details style="margin-top:.4rem">
        <summary class="muted" style="cursor:pointer; font-size:.8rem">Show the numbers</summary>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Month</th>@foreach ($series as $s)<th class="num">{{ $s['name'] }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($labels as $i => $label)
                <tr><td>{{ $label }}</td>@foreach ($series as $s)<td class="num">{{ Money::format((int) ($s['values'][$i] ?? 0)) }}</td>@endforeach</tr>
            @endforeach
            </tbody>
        </table></div>
    </details>
</figure>
