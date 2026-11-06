<?php
declare(strict_types=1);

/**
 * Seeds the six roles and the permission matrix (all environments; not demo data).
 * Later phases add permissions through their own migrations.
 */
return [
    'up' => function (PDO $pdo): void {
        $roles = [
            'super_admin'     => ['Super Administrator', 'Full control of the platform'],
            'project_manager' => ['Project Manager', 'Oversees the programme, reports and impact'],
            'hub_manager'     => ['Hub Manager', 'Runs a collection hub and its operators'],
            'hub_operator'    => ['Hub Operator', 'Weighs and records collections at a hub'],
            'waste_picker'    => ['Waste Picker', 'Collects and sells plastic'],
            'recycler'        => ['Recycler', 'Buys plastic from hubs'],
        ];

        $permissions = [
            'dashboard.view'      => ['View own dashboard', 'core'],
            'users.view'          => ['View users', 'administration'],
            'users.manage'        => ['Create, edit, approve and suspend users', 'administration'],
            'roles.manage'        => ['Manage roles and permissions', 'administration'],
            'settings.manage'     => ['Manage system settings', 'administration'],
            'audit.view'          => ['View audit trail', 'administration'],
            'hubs.view'           => ['View collection hubs', 'hubs'],
            'hubs.manage'         => ['Manage collection hubs', 'hubs'],
            'recyclers.approve'   => ['Approve recyclers', 'marketplace'],
            'pickers.view'        => ['View waste pickers', 'pickers'],
            'pickers.manage'      => ['Register and verify waste pickers', 'pickers'],
            'collections.view'    => ['View collections', 'collections'],
            'collections.record'  => ['Record and weigh collections', 'collections'],
            'collections.approve' => ['Approve collections', 'collections'],
            'inventory.view'      => ['View inventory', 'inventory'],
            'inventory.manage'    => ['Manage inventory and stock', 'inventory'],
            'marketplace.browse'  => ['Browse available plastic', 'marketplace'],
            'orders.manage'       => ['Manage purchase orders', 'marketplace'],
            'payments.view'       => ['View payments', 'finance'],
            'payments.approve'    => ['Approve and record payments', 'finance'],
            'reports.view'        => ['View reports', 'reports'],
            'impact.manage'       => ['Manage programme and impact records', 'impact'],
        ];

        $matrix = [
            'super_admin'     => array_keys($permissions),
            'project_manager' => ['dashboard.view', 'users.view', 'audit.view', 'hubs.view', 'pickers.view',
                                  'collections.view', 'inventory.view', 'payments.view', 'reports.view',
                                  'impact.manage', 'recyclers.approve'],
            'hub_manager'     => ['dashboard.view', 'hubs.view', 'pickers.view', 'pickers.manage',
                                  'collections.view', 'collections.record', 'collections.approve',
                                  'inventory.view', 'inventory.manage', 'reports.view'],
            'hub_operator'    => ['dashboard.view', 'pickers.view', 'collections.view', 'collections.record', 'inventory.view'],
            'waste_picker'    => ['dashboard.view', 'collections.view', 'payments.view'],
            'recycler'        => ['dashboard.view', 'marketplace.browse', 'orders.manage', 'payments.view'],
        ];

        $insRole = $pdo->prepare('INSERT INTO roles (slug, name, description) VALUES (?, ?, ?)');
        foreach ($roles as $slug => [$name, $desc]) {
            $insRole->execute([$slug, $name, $desc]);
        }
        $insPerm = $pdo->prepare('INSERT INTO permissions (slug, name, module) VALUES (?, ?, ?)');
        foreach ($permissions as $slug => [$name, $module]) {
            $insPerm->execute([$slug, $name, $module]);
        }
        $link = $pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r, permissions p WHERE r.slug = ? AND p.slug = ?'
        );
        foreach ($matrix as $role => $perms) {
            foreach ($perms as $perm) {
                $link->execute([$role, $perm]);
            }
        }
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DELETE FROM role_permissions');
        $pdo->exec('DELETE FROM permissions');
        $pdo->exec('DELETE FROM roles');
    },
];
