-- Repair for the six tables that the Namecheap server created as MyISAM (migrations 2026_11_12 .. 2026_11_17).
-- Run ONCE, in phpMyAdmin (SQL tab) on the production database, AFTER the verified backup and BEFORE resuming 'php artisan migrate'.
-- It changes only the storage engine and adds the foreign keys that MyISAM silently dropped. It deletes nothing, rewrites no rows and keeps every existing key.
-- All six tables hold 0 rows, so the foreign keys can be added without any data check. Re-running it fails harmlessly on 'duplicate constraint' and changes nothing.
-- Constraint names and delete rules are exactly the ones Laravel creates on a healthy InnoDB database (they were read from one).

ALTER TABLE `expense_refund_allocations` ENGINE=InnoDB;
ALTER TABLE `inventory_engine_enrolments` ENGINE=InnoDB;
ALTER TABLE `inventory_handovers` ENGINE=InnoDB;
ALTER TABLE `inventory_handover_returns` ENGINE=InnoDB;
ALTER TABLE `inventory_value_movements` ENGINE=InnoDB;
ALTER TABLE `landed_cost_reallocations` ENGINE=InnoDB;

ALTER TABLE `expense_refund_allocations` ADD CONSTRAINT `expense_refund_allocations_expense_payment_id_foreign` FOREIGN KEY (`expense_payment_id`) REFERENCES `expense_payments` (`id`) ON DELETE RESTRICT;
ALTER TABLE `expense_refund_allocations` ADD CONSTRAINT `expense_refund_allocations_financial_account_id_foreign` FOREIGN KEY (`financial_account_id`) REFERENCES `financial_accounts` (`id`) ON DELETE RESTRICT;
ALTER TABLE `expense_refund_allocations` ADD CONSTRAINT `expense_refund_allocations_reversal_expense_id_foreign` FOREIGN KEY (`reversal_expense_id`) REFERENCES `expenses` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_engine_enrolments` ADD CONSTRAINT `inventory_engine_enrolments_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_engine_enrolments` ADD CONSTRAINT `inventory_engine_enrolments_prepared_by_foreign` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_engine_enrolments` ADD CONSTRAINT `inventory_engine_enrolments_sourcing_lot_id_foreign` FOREIGN KEY (`sourcing_lot_id`) REFERENCES `sourcing_lots` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_handover_returns` ADD CONSTRAINT `inventory_handover_returns_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_handover_returns` ADD CONSTRAINT `inventory_handover_returns_inventory_handover_id_foreign` FOREIGN KEY (`inventory_handover_id`) REFERENCES `inventory_handovers` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_handover_returns` ADD CONSTRAINT `inventory_handover_returns_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_handover_returns` ADD CONSTRAINT `inventory_handover_returns_prepared_by_foreign` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_handovers` ADD CONSTRAINT `inventory_handovers_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_handovers` ADD CONSTRAINT `inventory_handovers_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_handovers` ADD CONSTRAINT `inventory_handovers_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_handovers` ADD CONSTRAINT `inventory_handovers_prepared_by_foreign` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_handovers` ADD CONSTRAINT `inventory_handovers_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_handovers` ADD CONSTRAINT `inventory_handovers_sourcing_allocation_id_foreign` FOREIGN KEY (`sourcing_allocation_id`) REFERENCES `sourcing_allocations` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_handovers` ADD CONSTRAINT `inventory_handovers_sourcing_lot_id_foreign` FOREIGN KEY (`sourcing_lot_id`) REFERENCES `sourcing_lots` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_handovers` ADD CONSTRAINT `inventory_handovers_sourcing_lot_line_id_foreign` FOREIGN KEY (`sourcing_lot_line_id`) REFERENCES `sourcing_lot_lines` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_handovers` ADD CONSTRAINT `inventory_handovers_supplier_product_id_foreign` FOREIGN KEY (`supplier_product_id`) REFERENCES `supplier_products` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_value_movements` ADD CONSTRAINT `inventory_value_movements_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_value_movements` ADD CONSTRAINT `inventory_value_movements_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_value_movements` ADD CONSTRAINT `inventory_value_movements_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL;
ALTER TABLE `inventory_value_movements` ADD CONSTRAINT `inventory_value_movements_reverses_id_foreign` FOREIGN KEY (`reverses_id`) REFERENCES `inventory_value_movements` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_value_movements` ADD CONSTRAINT `inventory_value_movements_sourcing_lot_id_foreign` FOREIGN KEY (`sourcing_lot_id`) REFERENCES `sourcing_lots` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_value_movements` ADD CONSTRAINT `inventory_value_movements_sourcing_lot_line_id_foreign` FOREIGN KEY (`sourcing_lot_line_id`) REFERENCES `sourcing_lot_lines` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_value_movements` ADD CONSTRAINT `inventory_value_movements_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_value_movements` ADD CONSTRAINT `inventory_value_movements_supplier_product_id_foreign` FOREIGN KEY (`supplier_product_id`) REFERENCES `supplier_products` (`id`) ON DELETE RESTRICT;
ALTER TABLE `inventory_value_movements` ADD CONSTRAINT `inventory_value_movements_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT;
ALTER TABLE `landed_cost_reallocations` ADD CONSTRAINT `landed_cost_reallocations_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT;
ALTER TABLE `landed_cost_reallocations` ADD CONSTRAINT `landed_cost_reallocations_prepared_by_foreign` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT;
ALTER TABLE `landed_cost_reallocations` ADD CONSTRAINT `landed_cost_reallocations_sourcing_lot_cost_id_foreign` FOREIGN KEY (`sourcing_lot_cost_id`) REFERENCES `sourcing_lot_costs` (`id`) ON DELETE RESTRICT;

-- Verify (expect: 6 rows with ENGINE InnoDB, TABLE_ROWS 0, and 31 foreign keys):
-- SELECT table_name, engine, table_rows FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('expense_refund_allocations','inventory_engine_enrolments','inventory_handovers','inventory_handover_returns','inventory_value_movements','landed_cost_reallocations');
-- SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND constraint_type = 'FOREIGN KEY' AND table_name IN ('expense_refund_allocations','inventory_engine_enrolments','inventory_handovers','inventory_handover_returns','inventory_value_movements','landed_cost_reallocations');
