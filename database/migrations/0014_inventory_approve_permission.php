<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        $pdo->prepare('INSERT INTO permissions (slug, name, module) VALUES (?, ?, ?)')
            ->execute(['inventory.approve', 'Approve stock adjustments', 'inventory']);
        $link = $pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r, permissions p WHERE r.slug = ? AND p.slug = ?'
        );
        foreach (['super_admin', 'project_manager', 'hub_manager'] as $role) {
            $link->execute([$role, 'inventory.approve']);
        }
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec("DELETE FROM permissions WHERE slug = 'inventory.approve'");
    },
];
