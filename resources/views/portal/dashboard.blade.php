@use('App\Support\Money')
@php($e = $o['economics'])
@php($w = $o['client_wide'])
@php($pend = fn ($v) => $v === null || ($e['pending'] ?? false) ? null : $v)
<x-portal-layout title="Dashboard">
    <h1 style="margin:0 0 4px">Hello, {{ $o['client']['name'] }}</h1>
    <p class="muted">SB ID {{ $o['client']['sb_number'] }}. Figures come from Sellbees' verified records; anything not yet verified is marked Pending.</p>
    <section class="panel"><div class="panel-head"><h2>Your business at a glance</h2></div><div class="panel-body"><div class="stat-grid">
        <div class="stat"><span class="stat-label">Active projects</span><span class="stat-value">{{ $o['active_projects'] }}</span></div>
        <div class="stat"><span class="stat-label">Products sourced</span><span class="stat-value">{{ $o['sourced_products'] }}</span></div>
        <div class="stat"><span class="stat-label">Available stock (units)</span><span class="stat-value">{{ $o['available_stock'] }}</span></div>
        <div class="stat"><span class="stat-label">Orders</span><span class="stat-value">{{ $o['orders'] }}</span></div>
        <div class="stat"><span class="stat-label">Successful orders</span><span class="stat-value">{{ $o['successful_orders'] }}</span></div>
        <div class="stat"><span class="stat-label">Successful sales (delivered, not returned)</span><span class="stat-value">{{ Money::format($o['successful_sales']) }}</span></div>
    </div></div></section>
    <section class="panel"><div class="panel-head"><h2>Your investment and profit</h2><span class="sub">All projects together</span></div><div class="panel-body"><div class="stat-grid">
        <div class="stat"><span class="stat-label">Your original product investment</span><span class="stat-value">{{ Money::format($e['investment']) }}</span></div>
        <div class="stat"><span class="stat-label">Investment recovered through sales</span><span class="stat-value">{{ $e['pending'] ? 'Pending verification' : Money::format($e['investment_recovered']) }}</span></div>
        <div class="stat"><span class="stat-label">Your net product profit</span><span class="stat-value">{{ $e['pending'] ? 'Pending verification' : Money::format($e['client_profit']) }}</span></div>
        <div class="stat"><span class="stat-label">Your settlement entitlement (earned from sales)</span><span class="stat-value">{{ Money::format($w['entitlement']) }}</span></div>
    </div></div></section>
    <section class="panel"><div class="panel-head"><h2>Settlement balance</h2><span class="sub">Across all your projects — not split by project</span></div><div class="panel-body"><div class="stat-grid">
        <div class="stat"><span class="stat-label">Settled to you so far</span><span class="stat-value">{{ Money::format($w['settled']) }}</span></div>
        <div class="stat"><span class="stat-label">Still owed to you</span><span class="stat-value">{{ Money::format($w['outstanding']) }}</span></div>
        <div class="stat"><span class="stat-label">Money collected and held, ready to settle</span><span class="stat-value">{{ Money::format($w['cash_available']) }}</span></div>
        <div class="stat"><span class="stat-label">Owed but not yet received from couriers</span><span class="stat-value">{{ Money::format($w['pending_remittance']) }}</span></div>
    </div><p class="muted" style="margin-top:10px">Profit is what your sales earned after your product cost; entitlement is what Sellbees owes you from sales; cash is only what has actually been collected.</p></div></section>
</x-portal-layout>