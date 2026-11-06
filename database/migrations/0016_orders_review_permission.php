<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        $pdo->prepare('INSERT INTO permissions (slug, name, module) VALUES (?, ?, ?)')
            ->execute(['orders.review', 'Respond to purchase requests and manage orders (seller side)', 'marketplace']);
        $link = $pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r, permissions p WHERE r.slug = ? AND p.slug = ?'
        );
        foreach (['super_admin', 'project_manager', 'hub_manager'] as $role) {
            $link->execute([$role, 'orders.review']);
        }
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec("DELETE FROM permissions WHERE slug = 'orders.review'");
    },
];
