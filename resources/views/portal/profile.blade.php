<x-portal-layout title="My Profile">
    <section class="panel"><div class="panel-head"><h2>My profile</h2><span class="sub">To change these details, please contact Sellbees.</span></div>
        <div class="panel-body"><dl style="display:grid;grid-template-columns:minmax(120px,200px) 1fr;gap:8px 16px;margin:0">
            <dt class="muted">Client / business name</dt><dd>{{ $p['name'] }}</dd>
            <dt class="muted">SB ID</dt><dd>{{ $p['sb_number'] }}</dd>
            <dt class="muted">Email</dt><dd>{{ $p['email'] }} @if ($p['email_verified'])<span class="muted">(verified)</span>@endif</dd>
            <dt class="muted">Phone</dt><dd>{{ $p['phone'] ?: '—' }}</dd>
            <dt class="muted">Address</dt><dd>{{ $p['address'] ?: '—' }}@if ($p['country']), {{ $p['country'] }}@endif</dd>
            <dt class="muted">Registration status</dt><dd>{{ $p['status'] }}</dd>
        </dl></div></section>
</x-portal-layout>