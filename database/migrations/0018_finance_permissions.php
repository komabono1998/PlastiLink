<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        $perms = [
            'payouts.request'     => ['Request picker payouts on their behalf', 'finance'],
            'payouts.request_own' => ['Request a payout of my own earnings', 'finance'],
            'payments.process'    => ['Execute and record payments', 'finance'],
            'payments.reverse'    => ['Reverse successful payments', 'finance'],
            'payments.reconcile'  => ['Reconcile payments against statements', 'finance'],
            'invoices.view'       => ['View invoices', 'finance'],
            'invoices.manage'     => ['Void invoices and record recycler payments', 'finance'],
            'finance.view'        => ['View financial summaries', 'finance'],
        ];
        $ins = $pdo->prepare('INSERT INTO permissions (slug, name, module) VALUES (?, ?, ?)');
        foreach ($perms as $slug => [$name, $module]) {
            $ins->execute([$slug, $name, $module]);
        }

        $grants = [
            'super_admin'     => ['payouts.request', 'payouts.request_own', 'payments.process', 'payments.reverse', 'payments.reconcile', 'invoices.view', 'invoices.manage', 'finance.view'],
            'project_manager' => ['payments.approve', 'payments.process', 'payments.reverse', 'payments.reconcile', 'invoices.view', 'invoices.manage', 'finance.view'],
            'hub_manager'     => ['payouts.request', 'payments.process', 'payments.view', 'invoices.view'],
            'waste_picker'    => ['payouts.request_own'],
            'recycler'        => ['invoices.view'],
        ];
        $link = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r, permissions p WHERE r.slug = ? AND p.slug = ?'
        );
        foreach ($grants as $role => $slugs) {
            foreach ($slugs as $slug) {
                $link->execute([$role, $slug]);
            }
        }
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id JOIN roles r ON r.id = rp.role_id
                    WHERE (r.slug = 'project_manager' AND p.slug = 'payments.approve') OR (r.slug = 'hub_manager' AND p.slug = 'payments.view')");
        $pdo->exec("DELETE FROM permissions WHERE slug IN ('payouts.request','payouts.request_own','payments.process','payments.reverse','payments.reconcile','invoices.view','invoices.manage','finance.view')");
    },
];
