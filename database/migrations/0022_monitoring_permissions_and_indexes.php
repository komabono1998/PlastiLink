<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        $perm = $pdo->prepare('INSERT INTO permissions (slug, name, module) VALUES (?, ?, ?)');
        $link = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.slug = ? AND p.slug = ?');
        foreach ([
            ['monitoring.view', 'View maps, hotspots and programme progress', ['super_admin', 'project_manager', 'hub_manager']],
            ['targets.manage', 'Change programme targets', ['super_admin']],
        ] as [$slug, $name, $roles]) {
            $perm->execute([$slug, $name, 'monitoring']);
            foreach ($roles as $role) {
                $link->execute([$role, $slug]);
            }
        }
        // Hotspot and monthly queries group approved collections by neighbourhood / period
        $pdo->exec('ALTER TABLE collection_transactions ADD KEY idx_collection_nb (neighbourhood_id, status, recorded_at)');
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('ALTER TABLE collection_transactions DROP KEY idx_collection_nb');
        $pdo->exec("DELETE FROM permissions WHERE slug IN ('monitoring.view', 'targets.manage')");
    },
];
