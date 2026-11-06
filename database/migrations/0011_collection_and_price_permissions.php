<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        $perms = [
            'prices.view'         => ['View prices', 'prices'],
            'prices.manage'       => ['Set prices', 'prices'],
            'collections.reverse' => ['Reverse approved collections', 'collections'],
        ];
        $ins = $pdo->prepare('INSERT INTO permissions (slug, name, module) VALUES (?, ?, ?)');
        foreach ($perms as $slug => [$name, $module]) {
            $ins->execute([$slug, $name, $module]);
        }
        $grants = [
            'super_admin'     => ['prices.view', 'prices.manage', 'collections.reverse'],
            'project_manager' => ['prices.view', 'prices.manage', 'collections.reverse'],
            'hub_manager'     => ['prices.view'],
            'hub_operator'    => ['prices.view'],
        ];
        $link = $pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r, permissions p WHERE r.slug = ? AND p.slug = ?'
        );
        foreach ($grants as $role => $slugs) {
            foreach ($slugs as $slug) {
                $link->execute([$role, $slug]);
            }
        }
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec("DELETE FROM permissions WHERE slug IN ('prices.view','prices.manage','collections.reverse')");
    },
];
