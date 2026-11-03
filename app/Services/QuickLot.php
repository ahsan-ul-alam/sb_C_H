<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Support\Costing;
use App\Support\Money;
use RuntimeException;

/**
 * The one calculation behind the simple lot form. The live preview and the final save both call it, so what the user
 * sees is exactly what is stored. It reuses the existing exact-money helpers: the delivery cost is spread over the
 * products by purchase value (largest-remainder, so the parts always add up to the entered amount), the landed unit cost is
 * landed ÷ quantity, and the client price is that unit cost plus the desired profit, rounded to the nearest paisa but never
 * below break-even. Totals are exact sums of the rounded line figures, so the lot always reconciles to the paisa.
 */
class QuickLot
{
    /**
     * @param  list<array{product_id: int|string, quantity: string, profit?: int}>  $items  profit = desired BDT per unit, in minor units
     * @return array{rows: list<array<string, mixed>>, purchase: int, delivery: int, landed: int, selling: int, profit: int}
     */
    public static function calculate(Supplier $supplier, array $items, int $delivery): array
    {
        if ($items === []) {
            throw new RuntimeException('Add at least one product.');
        }
        if ($delivery < 0) {
            throw new RuntimeException('Delivery cost cannot be negative.');
        }
        $products = SupplierProduct::where('supplier_id', $supplier->id)->whereIn('id', array_column($items, 'product_id'))->get()->keyBy('id');

        $rows = [];
        $weights = [];
        $seen = [];
        foreach (array_values($items) as $i => $item) {
            $product = $products[(int) $item['product_id']] ?? throw new RuntimeException('Choose products that belong to the selected supplier.');
            if (! $product->is_active) {
                throw new RuntimeException($product->label().' is inactive.');
            }
            if (isset($seen[$product->id])) {
                throw new RuntimeException($product->label().' is listed twice; combine the quantities.');
            }
            $seen[$product->id] = true;
            $quantity = (string) $item['quantity'];
            if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $quantity) || bccomp($quantity, '0', 2) <= 0) {
                throw new RuntimeException('Enter a quantity greater than zero for '.$product->label().'.');
            }
            $purchase = Money::multiply($quantity, (int) $product->reference_price);
            $weights[$i] = $purchase;
            $rows[$i] = ['product' => $product, 'quantity' => $quantity, 'base_price' => (int) $product->reference_price, 'purchase' => $purchase, 'profit' => max(0, (int) ($item['profit'] ?? 0))];
        }

        $shares = Costing::allocateShared($delivery, $weights);
        foreach ($rows as $i => &$row) {
            $row['delivery'] = $shares[$i] ?? 0;
            $row['landed'] = $row['purchase'] + $row['delivery'];
            $row['unit_cost'] = Costing::unitCost($row['landed'], $row['quantity']);
            $row['price'] = max(Costing::price($row['unit_cost'], 'fixed', $row['profit']), Costing::breakEven($row['landed'], $row['quantity']));
            $row['selling'] = Money::multiply($row['quantity'], $row['price']);
            $row['expected_profit'] = $row['selling'] - $row['landed'];
        }
        unset($row);

        $purchase = array_sum(array_column($rows, 'purchase'));
        $landed = array_sum(array_column($rows, 'landed'));
        $selling = array_sum(array_column($rows, 'selling'));

        return ['rows' => array_values($rows), 'purchase' => $purchase, 'delivery' => array_sum(array_column($rows, 'delivery')), 'landed' => $landed, 'selling' => $selling, 'profit' => $selling - $landed];
    }
}
