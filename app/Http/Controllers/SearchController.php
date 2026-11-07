<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\SourcingLot;
use App\Models\SupplierProduct;
use App\Support\Money;
use App\Support\Navigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The data behind the Ctrl+K palette. Each group is added only when the user holds the permission that already guards that screen, every result links to an
 * existing route, each group is capped, and only the few fields the palette shows are returned (no emails, ID numbers, balances or ledger figures).
 */
class SearchController extends Controller
{
    private const LIMIT = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $term = trim(mb_substr((string) $request->query('q', ''), 0, 60));
        $user = $request->user();
        $groups = [];

        $pages = Navigation::visible($user)->flatMap(fn ($items, $group) => $items->map(fn ($i) => ['title' => $i[0], 'subtitle' => $group, 'url' => route($i[1]), 'route' => $i[1]]))->values();
        $strip = fn ($rows) => collect($rows)->map(fn ($p) => ['title' => $p['title'], 'subtitle' => $p['subtitle'], 'url' => $p['url']])->values()->all();
        if ($term === '') {
            $popular = ['dashboard', 'clients.index', 'invoices.index', 'payments.index', 'sourcing.lots.index', 'finance.expenses', 'finance.historical', 'finance.accounts'];
            $shortcuts = $pages->filter(fn ($p) => in_array($p['route'], $popular, true))->take(8);

            return response()->json(['query' => '', 'groups' => $shortcuts->isEmpty() ? [] : [['label' => 'Go to', 'items' => $strip($shortcuts)]]]);
        }
        if (mb_strlen($term) < 2) {
            return response()->json(['query' => $term, 'groups' => []]);
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
        $matches = $pages->filter(fn ($p) => str_contains(mb_strtolower($p['title'].' '.$p['subtitle']), mb_strtolower($term)))->take(6)->values();
        $groups[] = ['label' => 'Pages', 'items' => $strip($matches)];

        if ($user->can('clienthub')) {
            $groups[] = ['label' => 'Clients', 'items' => Client::query()->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('sb_number', 'like', $like)->orWhere('mobile', 'like', $like)->orWhere('mobile_alt', 'like', $like))
                ->orderBy('name')->limit(self::LIMIT)->get(['id', 'name', 'sb_number', 'mobile', 'archived_at'])
                ->map(fn (Client $c) => ['title' => $c->name, 'subtitle' => trim(($c->sb_number ?? '').' · '.$c->mobile.($c->archived_at ? ' · archived' : ''), ' ·'), 'url' => route('clients.show', $c)])->all()];
            $groups[] = ['label' => 'Projects', 'items' => Project::query()->with('client:id,name')->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhereHas('client', fn (Builder $c) => $c->where('name', 'like', $like)))
                ->orderBy('name')->limit(self::LIMIT)->get(['id', 'client_id', 'name', 'currency'])
                ->map(fn (Project $p) => ['title' => $p->name, 'subtitle' => ($p->client?->name ?? '').' · '.$p->currency, 'url' => route('projects.show', $p)])->all()];
            $groups[] = ['label' => 'Invoices', 'items' => Invoice::query()->with('project:id,name')->where(fn (Builder $q) => $q->where('number', 'like', $like)->orWhere('purpose', 'like', $like))
                ->orderByDesc('issued_on')->limit(self::LIMIT)->get(['id', 'project_id', 'number', 'purpose', 'issued_on', 'status'])
                ->map(fn (Invoice $i) => ['title' => $i->number, 'subtitle' => ($i->project?->name ?? '').' · '.$i->issued_on->format('j M Y').' · '.$i->status->label(), 'url' => route('invoices.show', $i)])->all()];
            $groups[] = ['label' => 'Payments', 'items' => Payment::query()->with('project:id,name,currency')->where('reference', 'like', $like)
                ->orderByDesc('paid_on')->limit(self::LIMIT)->get(['id', 'project_id', 'reference', 'amount', 'paid_on'])
                ->map(fn (Payment $p) => ['title' => $p->reference, 'subtitle' => ($p->project?->name ?? '').' · '.Money::format((int) $p->amount, $p->project?->currency).' · '.$p->paid_on->format('j M Y'), 'url' => route('payments.edit', $p)])->all()];
        }
        if ($user->can('sourcing.view')) {
            $groups[] = ['label' => 'Sourcing lots', 'items' => SourcingLot::query()->where(fn (Builder $q) => $q->where('number', 'like', $like)->orWhere('title', 'like', $like))
                ->orderByDesc('id')->limit(self::LIMIT)->get(['id', 'number', 'title', 'status'])
                ->map(fn (SourcingLot $l) => ['title' => $l->number, 'subtitle' => $l->title.' · '.$l->statusLabel(), 'url' => route('sourcing.lots.show', $l)])->all()];
            $groups[] = ['label' => 'Products', 'items' => SupplierProduct::query()->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('sku', 'like', $like))
                ->orderBy('name')->limit(self::LIMIT)->get(['id', 'name', 'sku', 'variant'])
                ->map(fn (SupplierProduct $p) => ['title' => $p->name, 'subtitle' => trim(($p->sku ?? '').' · '.($p->variant ?? ''), ' ·'),
                    'url' => $user->can('sourcing.manage') ? route('sourcing.products.edit', $p) : route('sourcing.products.index')])->all()];
        }

        return response()->json(['query' => $term, 'groups' => array_values(array_filter($groups, fn (array $g) => $g['items'] !== []))]);
    }
}
