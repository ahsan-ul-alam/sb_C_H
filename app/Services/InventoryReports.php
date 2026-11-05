<?php

namespace App\Services;

use App\Models\ClientOrder;
use App\Models\ClientOrderItem;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Support\Money;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Tabular reports. Each is built from the same sources as the screens (ProjectInventory, Inventory::balances, the order tables), so a figure on a
 * dashboard and the same figure in an export cannot differ. Money columns are in major units; quantities are never added across units of measure.
 *
 * @phpstan-type Report array{title: string, headers: list<string>, rows: list<list<string|int|float|null>>}
 */
class InventoryReports
{
    public const TYPES = [
        'project-stock' => 'Project-wise stock', 'product-stock' => 'Product-wise stock', 'movements' => 'Stock movements', 'lot-remaining' => 'Lot-wise remaining quantity',
        'valuation' => 'Inventory valuation', 'sales-by-project' => 'Sales by project', 'sales-by-product' => 'Sales by product', 'orders-by-status' => 'Orders by status',
        'returns-damage' => 'Returns and damage', 'reconciliation' => 'Stock versus sales reconciliation',
        'location-stock' => 'Location-wise stock', 'location-reconciliation' => 'Global stock versus locations',
        'sales-by-channel' => 'Sales by channel', 'discounts' => 'Discounts given',
    ];

    /**
     * @param  array<string, mixed>  $f
     * @return Report
     */
    public static function build(string $type, array $f = []): array
    {
        return match ($type) {
            'project-stock' => self::projectStock($f), 'product-stock' => self::productStock($f), 'movements' => self::movements($f), 'lot-remaining' => self::lotRemaining($f),
            'valuation' => self::valuation($f), 'sales-by-project' => self::salesByProject($f), 'sales-by-product' => self::salesByProduct($f), 'orders-by-status' => self::ordersByStatus($f),
            'returns-damage' => self::returnsDamage($f), 'reconciliation' => self::reconciliation($f),
            'location-stock' => self::locationStock($f), 'location-reconciliation' => self::locationReconciliation($f),
            'sales-by-channel' => self::salesByChannel($f), 'discounts' => self::discounts($f),
            default => throw new InvalidArgumentException('Unknown report.'),
        };
    }

    private static function major(?int $minor): string
    {
        return $minor === null ? '' : bcdiv((string) $minor, '100', 2);
    }

    private static function who($r): string
    {
        return $r['project']?->name ?? ($r['attribution'] === 'shared' ? 'Shared: '.implode(', ', $r['shared_projects']) : 'Unattributed');
    }

    /** @return Report */
    private static function projectStock(array $f): array
    {
        $rows = ProjectInventory::positions($f)->map(fn ($r) => [
            self::who($r), $r['client']?->name, $r['line']->description, $r['line']->supplier->name, $r['line']->lot->number, ProjectInventory::OWNERSHIP[$r['ownership']], $r['unit'],
            $r['accepted'], $r['rejected'], $r['b']['available'], $r['b']['reserved'], $r['b']['dispatched'], $r['b']['delivered'], $r['b']['returned'], $r['b']['damaged'], implode('; ', array_map(fn ($k, $v) => $k.': '.$v, array_keys($r['locations']), $r['locations'])),
        ])->all();

        return ['title' => self::TYPES['project-stock'], 'headers' => ['Project', 'Client', 'Product', 'Supplier', 'Lot', 'Ownership', 'Unit', 'Accepted', 'Rejected', 'Available', 'Reserved', 'Dispatched', 'Delivered', 'Returned (awaiting inspection)', 'Damaged', 'Locations'], 'rows' => $rows];
    }

    /** @return Report */
    private static function productStock(array $f): array
    {
        $rows = ProjectInventory::positions($f)->groupBy(fn ($r) => $r['line']->supplier_product_id.'|'.$r['unit'])->map(function ($g) {
            $t = ProjectInventory::totals($g)['units'][$g->first()['unit']];

            return [$g->first()['line']->description, $g->first()['unit'], $t['received'], $t['available'], $t['reserved'], $t['dispatched'], $t['delivered'], $t['returned'], $t['damaged']];
        })->values()->all();

        return ['title' => self::TYPES['product-stock'], 'headers' => ['Product', 'Unit', 'Accepted', 'Available', 'Reserved', 'Dispatched', 'Delivered', 'Returned', 'Damaged'], 'rows' => $rows];
    }

    /** @return Report */
    private static function movements(array $f): array
    {
        $rows = StockMovement::with(['line.lot', 'creator'])->when($f['lot'] ?? null, fn ($q, $v) => $q->whereHas('line', fn ($l) => $l->where('sourcing_lot_id', $v)))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('occurred_on', '>=', $v))->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('occurred_on', '<=', $v))
            ->orderBy('id')->limit(5000)->get()->map(fn ($m) => [$m->occurred_on->toDateString(), $m->line->lot->number, $m->line->description, $m->type, $m->d_available, $m->d_reserved, $m->d_dispatched, $m->d_delivered, $m->d_returned, $m->d_damaged, $m->reason, $m->creator?->name])->all();

        return ['title' => self::TYPES['movements'], 'headers' => ['Date', 'Lot', 'Product', 'Type', 'Δ Available', 'Δ Reserved', 'Δ Dispatched', 'Δ Delivered', 'Δ Returned', 'Δ Damaged', 'Reason', 'By'], 'rows' => $rows];
    }

    /** @return Report */
    private static function lotRemaining(array $f): array
    {
        $rows = ProjectInventory::positions($f)->filter(fn ($r) => $r['own_slice'])->map(fn ($r) => [
            $r['line']->lot->number, $r['line']->description, $r['unit'], (string) $r['line']->quantity, (string) $r['line']->received_quantity, $r['accepted'], Receiving::expected($r['line']), $r['b']['available'], $r['b']['reserved'],
        ])->values()->all();

        return ['title' => self::TYPES['lot-remaining'], 'headers' => ['Lot', 'Product', 'Unit', 'Ordered', 'Received', 'Accepted', 'Still expected', 'Available', 'Reserved'], 'rows' => $rows];
    }

    /** @return Report */
    private static function valuation(array $f): array
    {
        $rows = ProjectInventory::positions($f)->filter(fn ($r) => $r['ownership'] === 'sellbees')->map(fn ($r) => [
            $r['line']->lot->number, $r['line']->description, $r['valuation'], $r['unit_cost'] === null ? '' : bcdiv((string) $r['unit_cost'], '100', 2), self::major($r['asset_value']),
        ])->values()->all();

        return ['title' => self::TYPES['valuation'], 'headers' => ['Lot', 'Product', 'Valuation status', 'Unit landed cost', 'Remaining asset value (Sellbees-owned only)'], 'rows' => $rows];
    }

    /** @return Report */
    private static function salesByProject(array $f): array
    {
        $rows = SalesDashboard::figures($f['from'] ?? null, $f['to'] ?? null)['by_project']->map(fn ($p) => [$p['project']?->name, $p['orders'], $p['delivered_orders'], self::major($p['gross']), self::major($p['delivered']), self::major($p['commission'])])->all();

        return ['title' => self::TYPES['sales-by-project'], 'headers' => ['Project', 'Orders', 'Delivered orders', 'Gross order value (client merchandise)', 'Delivered value', 'Commission (Sellbees revenue)'], 'rows' => $rows];
    }

    /** @return Report */
    private static function salesByProduct(array $f): array
    {
        $rows = ClientOrderItem::with('order')->whereHas('order', fn ($q) => $q->where('fulfilment', 'stock')->whereNotIn('delivery_status', ['cancelled']))->get()->groupBy('supplier_product_id')->map(fn ($g) => [
            $g->first()->description, (string) $g->sum('quantity'), (string) $g->sum('delivered_quantity'), (string) $g->sum('returned_quantity'), self::major((int) $g->sum(fn ($i) => $i->deliveredAmount())),
        ])->values()->all();

        return ['title' => self::TYPES['sales-by-product'], 'headers' => ['Product', 'Ordered', 'Delivered', 'Returned', 'Net delivered value'], 'rows' => $rows];
    }

    /** @return Report */
    private static function ordersByStatus(array $f): array
    {
        $rows = ClientOrder::where('fulfilment', 'stock')->with(['items', 'collections', 'project'])->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('ordered_on', '>=', $v))->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('ordered_on', '<=', $v))
            ->orderBy('id')->get()->map(function ($o) {
                $s = SalesStatus::of($o);

                return [$o->reference, $o->project?->name, $o->ordered_on->toDateString(), $s['order'], $s['fulfilment'], $s['payment'], self::major($o->items->sum(fn ($i) => $i->amount())), self::major($o->deliveredValue()), self::major((int) $o->collections->sum('amount')), self::major((int) $o->commission)];
            })->all();

        return ['title' => self::TYPES['orders-by-status'], 'headers' => ['Order', 'Project', 'Date', 'Order status', 'Fulfilment', 'Payment', 'Gross value', 'Delivered value', 'Collected', 'Commission'], 'rows' => $rows];
    }

    /** @return Collection<int, ClientOrder> */
    private static function stockOrders(array $f)
    {
        return ClientOrder::where('fulfilment', 'stock')->with(['items', 'collections', 'project'])
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('ordered_on', '>=', $v))->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('ordered_on', '<=', $v))->orderBy('id')->get();
    }

    /** @return Report */
    private static function salesByChannel(array $f): array
    {
        $rows = self::stockOrders($f)->groupBy(fn ($o) => $o->sales_channel ?? 'not stated')->map(fn ($g, $channel) => [
            $channel, $g->count(), self::major((int) $g->whereNotIn('delivery_status', ['cancelled'])->sum(fn ($o) => $o->items->sum(fn ($i) => $i->amount()))),
            self::major((int) $g->where('delivery_status', 'delivered')->sum(fn ($o) => $o->deliveredValue())), self::major((int) $g->sum(fn ($o) => $o->collections->sum('amount'))), self::major((int) $g->where('delivery_status', 'delivered')->sum('commission')),
        ])->values()->all();

        return ['title' => self::TYPES['sales-by-channel'], 'headers' => ['Channel', 'Orders', 'Gross order value (client merchandise, net of discount)', 'Delivered value', 'Collected', 'Commission (Sellbees revenue)'], 'rows' => $rows];
    }

    /** @return Report */
    private static function discounts(array $f): array
    {
        $rows = self::stockOrders($f)->filter(fn ($o) => $o->items->contains(fn ($i) => $i->unit_discount > 0))->map(fn ($o) => [
            $o->reference, $o->project?->name, $o->discount_reason, self::major((int) $o->items->sum(fn ($i) => Money::multiply((string) $i->quantity, (int) $i->unit_price))),
            self::major((int) $o->items->sum(fn ($i) => $i->discountAmount())), self::major((int) $o->items->sum(fn ($i) => $i->amount())),
        ])->values()->all();

        return ['title' => self::TYPES['discounts'], 'headers' => ['Order', 'Project', 'Reason', 'List value', 'Discount', 'Net order value'], 'rows' => $rows];
    }

    /** @return Report */
    private static function locationStock(array $f): array
    {
        $names = StockLocation::pluck('name', 'id');
        $rows = [];
        foreach (ProjectInventory::positions($f)->filter(fn ($r) => $r['own_slice']) as $r) {
            foreach (StockLocations::balances($r['line']) as $id => $qty) {
                $rows[] = [$id === '' ? 'Not placed (unverified)' : ($names[(int) $id] ?? '#'.$id), $r['line']->lot->number, $r['line']->description, $r['unit'], $qty];
            }
        }
        usort($rows, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return ['title' => self::TYPES['location-stock'], 'headers' => ['Location', 'Lot', 'Product', 'Unit', 'Units on hand (available + reserved + awaiting inspection)'], 'rows' => $rows];
    }

    /**
     * Global on-hand of each line (from the movement ledger) against the sum of its located balances plus its explicitly unplaced balance. Transfers
     * complete in one step, so nothing is ever in transit between locations. Negative location balances (possible only in data written before location
     * rules existed) are listed in their own column and never corrected silently.
     *
     * @return Report
     */
    private static function locationReconciliation(array $f): array
    {
        $rows = [];
        foreach (ProjectInventory::positions($f)->filter(fn ($r) => $r['own_slice']) as $r) {
            $balances = StockLocations::balances($r['line']);
            $placed = collect($balances)->reject(fn ($q, $id) => $id === '')->reduce(fn ($c, $q) => bcadd($c, $q, 2), '0.00');
            $unplaced = $balances[''] ?? '0.00';
            $global = Inventory::onHand($r['line']);
            $negative = collect($balances)->filter(fn ($q) => bccomp($q, '0', 2) < 0)->count();
            $diff = bcsub($global, bcadd($placed, $unplaced, 2), 2);
            $rows[] = [$r['line']->lot->number, $r['line']->description, $global, $placed, $unplaced, '0.00', $diff, $negative, bccomp($diff, '0', 2) === 0 && $negative === 0 ? 'Reconciled' : 'Investigate'];
        }

        return ['title' => self::TYPES['location-reconciliation'], 'headers' => ['Lot', 'Product', 'Global on-hand (ledger)', 'In verified locations', 'Explicitly unplaced', 'In transit', 'Difference', 'Negative locations', 'Result'], 'rows' => $rows];
    }

    /** @return Report */
    private static function returnsDamage(array $f): array
    {
        $rows = ProjectInventory::positions($f)->filter(fn ($r) => bccomp(bcadd($r['b']['returned'], $r['b']['damaged'], 2), '0', 2) > 0 || bccomp((string) $r['rejected'], '0', 2) > 0)->map(fn ($r) => [
            $r['line']->lot->number, $r['line']->description, (string) ($r['rejected'] ?? ''), $r['b']['returned'], $r['b']['damaged'],
        ])->values()->all();

        return ['title' => self::TYPES['returns-damage'], 'headers' => ['Lot', 'Product', 'Rejected at receiving', 'Returned, awaiting inspection', 'Damaged / quarantined'], 'rows' => $rows];
    }

    /**
     * Per line: what the ledger holds in each bucket against what the orders say. A difference is a defect to investigate, never netted away.
     *
     * @return Report
     */
    private static function reconciliation(array $f): array
    {
        $rows = [];
        foreach (ProjectInventory::positions($f)->filter(fn ($r) => $r['own_slice']) as $r) {
            $line = $r['line'];
            $items = ClientOrderItem::with('order')->where('sourcing_lot_line_id', $line->id)->get();
            $of = fn (string $status) => $items->filter(fn ($i) => $i->order->delivery_status === $status);
            $b = Inventory::balances($line);
            $reserved = (string) $of('confirmed')->sum('quantity');
            $dispatched = (string) $of('dispatched')->sum('quantity');
            $delivered = (string) $of('delivered')->sum(fn ($i) => (float) bcsub((string) $i->delivered_quantity, (string) $i->returned_quantity, 2));
            $diff = bcadd(bcadd(bcsub($b['reserved'], $reserved, 2), bcsub($b['dispatched'], $dispatched, 2), 2), bcsub($b['delivered'], bcadd($delivered, '0', 2), 2), 2);
            $rows[] = [$line->lot->number, $line->description, $b['reserved'], bcadd($reserved, '0', 2), $b['dispatched'], bcadd($dispatched, '0', 2), $b['delivered'], bcadd($delivered, '0', 2), $diff, Inventory::reconciles($line) ? 'Yes' : 'No'];
        }

        return ['title' => self::TYPES['reconciliation'], 'headers' => ['Lot', 'Product', 'Ledger reserved', 'Orders reserved', 'Ledger dispatched', 'Orders dispatched', 'Ledger delivered', 'Orders delivered (net of returns)', 'Net difference', 'Buckets add up'], 'rows' => $rows];
    }
}
