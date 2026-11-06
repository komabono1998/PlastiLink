<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        $pdo->prepare('INSERT INTO permissions (slug, name, module) VALUES (?, ?, ?)')->execute(['reports.export', 'Download reports (CSV, Excel, PDF)', 'reports']);
        $link = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.slug = ? AND p.slug = ?'
        );
        foreach (['super_admin', 'project_manager', 'hub_manager'] as $role) {
            $link->execute([$role, 'reports.export']);
        }

        // Indexes that make period reports cheap on larger data
        $pdo->exec('ALTER TABLE financial_ledger ADD KEY idx_ledger_type_date (entry_type, created_at)');
        $pdo->exec('ALTER TABLE purchase_orders ADD KEY idx_po_created (created_at)');
        $pdo->exec('ALTER TABLE stock_dispatches ADD KEY idx_dispatch_delivered (status, delivered_at)');
        $pdo->exec('ALTER TABLE invoices ADD KEY idx_invoice_issued (issued_at)');
        $pdo->exec('ALTER TABLE collection_transactions ADD KEY idx_collection_recorded (recorded_at)');
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('ALTER TABLE collection_transactions DROP KEY idx_collection_recorded');
        $pdo->exec('ALTER TABLE invoices DROP KEY idx_invoice_issued');
        $pdo->exec('ALTER TABLE stock_dispatches DROP KEY idx_dispatch_delivered');
        $pdo->exec('ALTER TABLE purchase_orders DROP KEY idx_po_created');
        $pdo->exec('ALTER TABLE financial_ledger DROP KEY idx_ledger_type_date');
        $pdo->exec("DELETE FROM permissions WHERE slug = 'reports.export'");
    },
];
