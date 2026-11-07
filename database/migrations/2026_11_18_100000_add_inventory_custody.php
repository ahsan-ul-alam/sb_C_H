<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ownership versus physical custody, Phase 2D-0. Additive only: every new column is NULL or has a harmless default.
 *
 * stock_movements.inventory_handover_id   the custody slice a movement belongs to. NULL = the line's own stock (Sellbees-owned on a company lot, the whole
 *                                         lot on a client-owned lot). A handover's slice holds the units that were handed over and still stand in the warehouse.
 * client_order_items.inventory_handover_id the custody slice an order line was reserved from, so dispatch, delivery, failure and return follow the same units.
 * inventory_handovers.custody_mode        warehouse = the goods stay in our warehouse as the client's custody stock; released = they physically left at handover.
 *
 * Resumable: MariaDB/MySQL DDL is not transactional, so a run that failed half way leaves the new column without its key or index. Each piece (column, foreign
 * key, index) is therefore checked on its own and only the missing ones are added; nothing is skipped silently and nothing is created twice. It refuses to run
 * while inventory_handovers is not InnoDB, because a foreign key to a MyISAM table cannot be created (and MyISAM would silently ignore it).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->requireInnoDb('inventory_handovers');

        $this->handoverReference('stock_movements', ['inventory_handover_id', 'sourcing_lot_line_id']);
        $this->handoverReference('client_order_items');

        if (! Schema::hasColumn('inventory_handovers', 'custody_mode')) {
            Schema::table('inventory_handovers', function (Blueprint $table) {
                $table->string('custody_mode', 10)->default('warehouse');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }

    /** @param  list<string>|null  $index  an extra index to have on the table, created only when absent */
    private function handoverReference(string $tableName, ?array $index = null): void
    {
        if (! Schema::hasColumn($tableName, 'inventory_handover_id')) {
            Schema::table($tableName, function (Blueprint $table) use ($index) {
                $table->foreignId('inventory_handover_id')->nullable()->constrained('inventory_handovers')->restrictOnDelete();
                if ($index) {
                    $table->index($index);
                }
            });

            return;
        }

        // The column exists from an interrupted run: add exactly what is still missing.
        $hasForeignKey = collect(Schema::getForeignKeys($tableName))->contains(fn (array $fk) => $fk['columns'] === ['inventory_handover_id'] && $fk['foreign_table'] === 'inventory_handovers');
        $missingIndex = $index !== null && ! Schema::hasIndex($tableName, $index);
        if ($hasForeignKey && ! $missingIndex) {
            return;
        }
        Schema::table($tableName, function (Blueprint $table) use ($hasForeignKey, $missingIndex, $index) {
            if (! $hasForeignKey) {
                $table->foreign('inventory_handover_id')->references('id')->on('inventory_handovers')->restrictOnDelete();
            }
            if ($missingIndex) {
                $table->index($index);
            }
        });
    }

    private function requireInnoDb(string $tableName): void
    {
        $engine = collect(Schema::getTables())->firstWhere('name', $tableName)['engine'] ?? null;
        if (is_string($engine) && strcasecmp($engine, 'InnoDB') !== 0) {
            throw new RuntimeException("Table {$tableName} uses the {$engine} engine, so foreign keys to it cannot be created. Run database/sql/repair_myisam_inventory_tables.sql first (see the repair instructions), then run the migration again.");
        }
    }
};
