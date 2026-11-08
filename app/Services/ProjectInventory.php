<?php

namespace App\Services;

use App\Models\InventoryHandover;
use App\Models\SourcingAllocation;
use App\Models\SourcingLotLine;
use App\Models\StockLocation;
use Illuminate\Support\Collection;

/**
 * Project inventory positions, derived ONLY from the stock ledger (Inventory) and the allocation and handover records; nothing here is stored.
 * A position is one slice of one lot line: the line's own stock (Sellbees-owned on a company lot, the client's on a client lot) or one client
 * custody slice from a handover. A slice is counted once, so a lot shared by several projects shows its physical units on a single "shared lot"
 * row and the per-project allocations beside it, never added up twice.
 *
 * @phpstan-type Position array{key: string, line: SourcingLotLine, handover: ?InventoryHandover, attribution: string, project: ?\App\Models\Project, client: ?\App\Models\Client, shared_projects: list<string>, ownership: string, own_slice: bool, b: array<string, string>, received: ?string, accepted: ?string, rejected: ?string, locations: array<string, string>, unit: string, valuation: string, unit_cost: ?string, asset_value: ?int}
 */
class ProjectInventory
{
    public const OWNERSHIP = ['sellbees' => 'Sellbees-owned', 'client' => 'Client-owned', 'client_custody' => 'Client custody', 'unstated' => 'Owner not stated'];

    /**
     * @param  array{project?: ?int, client?: ?int, product?: ?string, supplier?: ?int, lot?: ?int, location?: ?int, ownership?: ?string, status?: ?string, from?: ?string, to?: ?string}  $f
     * @return Collection<int, array<string, mixed>>
     */
    public static function positions(array $f = []): Collection
    {
        $lines = SourcingLotLine::query()->whereNotNull('received_quantity')->with(['lot.project.client', 'supplier', 'product'])
            ->when($f['supplier'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($f['lot'] ?? null, fn ($q, $v) => $q->where('sourcing_lot_id', $v))
            ->when(trim((string) ($f['product'] ?? '')) !== '', fn ($q) => $q->where('description', 'like', '%'.trim((string) $f['product']).'%'))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('arrived_on', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('arrived_on', '<=', $v))
            ->orderBy('arrived_on')->orderBy('id')->get();
        $handovers = InventoryHandover::query()->with(['project.client', 'client'])->where('status', 'approved')->where('custody_mode', 'warehouse')->whereIn('sourcing_lot_line_id', $lines->pluck('id'))->get()->groupBy('sourcing_lot_line_id');
        $allocations = SourcingAllocation::query()->with('project.client')->where('kind', 'sale')->whereIn('sourcing_lot_id', $lines->pluck('sourcing_lot_id')->unique())->get();
        $locationNames = StockLocation::pluck('name', 'id');

        $rows = collect();
        foreach ($lines as $line) {
            $own = $allocations->filter(fn ($a) => $a->sourcing_lot_id === $line->sourcing_lot_id && ($a->sourcing_lot_line_id === null || $a->sourcing_lot_line_id === $line->id))->pluck('project')->unique('id');
            $lotProject = $line->lot->project;
            [$attribution, $project, $shared] = match (true) {
                $lotProject !== null && $own->every(fn ($p) => $p->id === $lotProject->id) => ['lot_project', $lotProject, []],
                $own->count() === 1 => ['allocation', $own->first(), []],
                $own->count() > 1 => ['shared', null, $own->pluck('name')->all()],
                default => ['none', null, []],
            };
            $ownership = match ($line->lot->ownership) {
                'company' => 'sellbees', 'client' => 'client', default => 'unstated',
            };
            $rows->push(self::row($line, null, $attribution, $project, $shared, $ownership, $locationNames));
            foreach ($handovers->get($line->id, collect()) as $h) {
                $rows->push(self::row($line, $h, 'custody', $h->project, [], 'client_custody', $locationNames));
            }
        }

        // A user limited to certain projects sees only the positions of those projects (never a shared or unattributed row that could expose another project's stock).
        $visible = auth()->user()?->accessibleProjectIds();
        if ($visible !== null) {
            $rows = $rows->filter(fn ($r) => $r['project'] !== null && in_array($r['project']->id, $visible, true));
        }

        return $rows->filter(fn ($r) => self::keeps($r, $f))->values();
    }

    /**
     * @param  list<string>  $shared
     * @param  Collection<int, string>  $locationNames
     * @return array<string, mixed>
     */
    private static function row(SourcingLotLine $line, ?InventoryHandover $h, string $attribution, $project, array $shared, string $ownership, Collection $locationNames): array
    {
        $b = Inventory::sliceBalances($line, $h?->id);
        // Physical places are a property of the line's units, whoever holds them, so they are shown once, on the line's own row.
        $locations = collect($h === null ? StockLocations::balances($line) : [])
            ->mapWithKeys(fn ($q, $id) => [$id === '' ? 'Not placed' : ($locationNames[(int) $id] ?? '#'.$id) => $q])->all();
        $valued = $h === null && $ownership === 'sellbees' && InventoryEngine::isValued($line->lot);
        $figures = $valued ? InventoryValuation::lineFigures($line) : null;

        return [
            'key' => $line->id.':'.($h?->id ?? 'own'), 'line' => $line, 'handover' => $h, 'attribution' => $attribution,
            'project' => $project, 'client' => $project?->client ?? $line->lot->project?->client, 'shared_projects' => $shared, 'ownership' => $ownership, 'own_slice' => $h === null,
            'b' => $b,
            'received' => $h === null ? (string) $line->received_quantity : null,
            'accepted' => $h === null ? (string) $line->usable_quantity : null,
            'rejected' => $h === null ? bcsub((string) $line->received_quantity, (string) $line->usable_quantity, 2) : null,
            'locations' => $locations, 'unit' => $line->unit ?? 'pcs',
            'valuation' => $ownership !== 'sellbees' || $h !== null ? 'not_applicable' : ($valued ? InventoryValuation::status(InventoryValuation::acceptedValue($line), $figures['purchase_valued']) : 'not_valued'),
            'unit_cost' => $valued ? InventoryValuation::unitCost($line) : null,
            'asset_value' => $valued ? $figures['purchase'] + $figures['landed'] : null,
        ];
    }

    /** @param array<string, mixed> $r */
    private static function keeps(array $r, array $f): bool
    {
        if (($f['project'] ?? null) && ($r['project']?->id !== (int) $f['project']) && ! ($r['attribution'] === 'shared' && self::sharesProject($r, (int) $f['project']))) {
            return false;
        }
        if (($f['client'] ?? null) && $r['client']?->id !== (int) $f['client']) {
            return false;
        }
        if (($f['ownership'] ?? null) && $r['ownership'] !== $f['ownership']) {
            return false;
        }
        if (($f['location'] ?? null) && (! $r['own_slice'] || bccomp(StockLocations::onHandAt($r['line'], (int) $f['location']), '0', 2) <= 0)) {
            return false;
        }
        $available = $r['b']['available'];

        return match ($f['status'] ?? null) {
            'in_stock' => bccomp($available, '0', 2) > 0,
            'out' => bccomp($available, '0', 2) <= 0,
            'reserved' => bccomp($r['b']['reserved'], '0', 2) > 0,
            'quarantined' => bccomp(bcadd($r['b']['damaged'], $r['b']['returned'], 2), '0', 2) > 0,
            default => true,
        };
    }

    /** @param array<string, mixed> $r */
    private static function sharesProject(array $r, int $projectId): bool
    {
        return SourcingAllocation::where('sourcing_lot_id', $r['line']->sourcing_lot_id)->where('kind', 'sale')->where('project_id', $projectId)->exists();
    }

    /**
     * Quantity totals per unit of measure (units are never added together) and the asset value of Sellbees-owned stock.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{units: array<string, array<string, string>>, asset_value: int, pending_valuation: int}
     */
    public static function totals(Collection $rows): array
    {
        $units = [];
        foreach ($rows as $r) {
            $u = &$units[$r['unit']];
            $u ??= array_fill_keys(['received', 'available', 'reserved', 'dispatched', 'delivered', 'returned', 'damaged', 'rejected'], '0');
            $u['received'] = bcadd($u['received'], $r['own_slice'] ? (string) $r['accepted'] : '0', 2);
            $u['rejected'] = bcadd($u['rejected'], (string) ($r['rejected'] ?? '0'), 2);
            foreach (['available', 'reserved', 'dispatched', 'delivered', 'returned', 'damaged'] as $k) {
                $u[$k] = bcadd($u[$k], $r['b'][$k], 2);
            }
            unset($u);
        }

        return ['units' => $units, 'asset_value' => (int) $rows->sum(fn ($r) => $r['asset_value'] ?? 0),
            'pending_valuation' => $rows->filter(fn ($r) => in_array($r['valuation'], ['pending', 'partial'], true))->count()];
    }
}
