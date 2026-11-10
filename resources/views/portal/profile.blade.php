@php($initials = collect(preg_split('/\s+/', (string) $p['name'], -1, PREG_SPLIT_NO_EMPTY))->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode(''))
<x-portal-layout title="My Profile" subtitle="The details Sellbees holds for you. To change any of them, please contact Sellbees.">
    <section class="pt-card">
        <div class="pt-card-body" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;border-bottom:1px solid var(--border)">
            <span class="pt-avatar" style="width:56px;height:56px;font-size:1.15rem" aria-hidden="true">{{ $initials }}</span>
            <div><h2 style="font-size:1.2rem;font-weight:700">{{ $p['name'] }}</h2><span class="muted">{{ $p['sb_number'] }}</span></div>
            <span style="margin-left:auto"><span class="pt-chip {{ $p['status'] === 'Active' ? 'ok' : 'plain' }}">{{ $p['status'] }}</span></span>
        </div>
        <div class="pt-card-body"><dl class="pt-dl">
            <dt>Client / business name</dt><dd>{{ $p['name'] }}</dd>
            <dt>SB ID</dt><dd>{{ $p['sb_number'] }}</dd>
            <dt>Email</dt><dd>{{ $p['email'] }} @if ($p['email_verified'])<span class="pt-chip ok" style="margin-left:.4rem">Verified</span>@endif</dd>
            <dt>Phone</dt><dd>{{ $p['phone'] ?: '—' }}</dd>
            <dt>Address</dt><dd>{{ $p['address'] ?: '—' }}@if ($p['country']), {{ $p['country'] }}@endif</dd>
            <dt>Registration status</dt><dd>{{ $p['status'] }}</dd>
        </dl></div>
        <div class="pt-card-foot">For your privacy, identity documents and internal notes are never shown in the portal.</div>
    </section>
</x-portal-layout>
