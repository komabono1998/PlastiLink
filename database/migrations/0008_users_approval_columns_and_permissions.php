<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec('ALTER TABLE users
            ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
            ADD COLUMN approved_by INT UNSIGNED NULL AFTER last_login_ip,
            ADD COLUMN approved_at DATETIME NULL AFTER approved_by,
            ADD CONSTRAINT fk_users_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL');

        $perms = [
            'neighbourhoods.manage' => ['Manage neighbourhoods', 'administration'],
            'recyclers.manage'      => ['Register and manage recyclers', 'marketplace'],
            'pickers.register'      => ['Register waste pickers in person', 'pickers'],
        ];
        $insPerm = $pdo->prepare('INSERT INTO permissions (slug, name, module) VALUES (?, ?, ?)');
        foreach ($perms as $slug => [$name, $module]) {
            $insPerm->execute([$slug, $name, $module]);
        }

        $grants = [
            'super_admin'     => ['neighbourhoods.manage', 'recyclers.manage', 'pickers.register'],
            'project_manager' => ['recyclers.manage'],
            'hub_manager'     => ['pickers.register'],
            'hub_operator'    => ['pickers.register'],
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
        $pdo->exec("DELETE FROM permissions WHERE slug IN ('neighbourhoods.manage','recyclers.manage','pickers.register')");
        $pdo->exec('ALTER TABLE users DROP FOREIGN KEY fk_users_approved_by');
        $pdo->exec('ALTER TABLE users DROP COLUMN approved_at, DROP COLUMN approved_by, DROP COLUMN must_change_password');
    },
];
