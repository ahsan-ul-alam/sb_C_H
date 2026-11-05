<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
| Guards for two defects that SQLite hides and MySQL/MariaDB enforces (found by running the concurrency suite on a real MySQL 8.4):
|  - an index name longer than 64 characters (the auto-generated unique name of order_refund_allocations was 69);
|  - a value longer than its varchar column (inventory_value_movements.type was varchar(12) but 'supplier_return' / 'quarantine_release' are written).
*/

it('has no index name longer than the 64 characters MySQL and MariaDB allow', function () {
    $tooLong = [];
    foreach (Schema::getTables() as $table) {
        foreach (Schema::getIndexes($table['name']) as $index) {
            if (strlen((string) $index['name']) > 64) {
                $tooLong[] = $table['name'].'.'.$index['name'].' ('.strlen($index['name']).')';
            }
        }
        foreach (Schema::getForeignKeys($table['name']) as $fk) {
            if (isset($fk['name']) && strlen((string) $fk['name']) > 64) {
                $tooLong[] = $table['name'].' fk '.$fk['name'].' ('.strlen($fk['name']).')';
            }
        }
    }

    expect($tooLong)->toBe([]);
});

it('keeps every inventory value movement type within its column width', function () {
    $migration = file_get_contents(database_path('migrations/2026_12_01_100000_widen_inventory_value_movement_type.php'));
    preg_match("/string\('type', (\d+)\)->change\(\)/", $migration, $m);
    $width = (int) ($m[1] ?? 0);
    expect($width)->toBeGreaterThanOrEqual(24);

    $source = file_get_contents(app_path('Services/InventoryValuation.php'));
    preg_match_all("/'type' => '([a-z_]+)'/", $source, $found);
    $types = array_unique($found[1]);
    expect($types)->toContain('supplier_return')->and($types)->toContain('issue_return');
    foreach ($types as $type) {
        expect(strlen($type))->toBeLessThanOrEqual($width, 'type "'.$type.'" is longer than the column');
    }
    foreach (["'quarantine_release'"] as $literal) { // written through a conditional expression the pattern above cannot see
        expect($source)->toContain($literal)->and(strlen(trim($literal, "'")))->toBeLessThanOrEqual($width);
    }
});
