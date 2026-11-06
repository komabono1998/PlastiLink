<?php
/** @var string $status */
$map = [
    'active' => 'success', 'approved' => 'success', 'verified' => 'success',
    'pending' => 'warning text-dark', 'unverified' => 'secondary',
    'suspended' => 'danger', 'rejected' => 'danger', 'inactive' => 'secondary',
];
?>
<span class="badge text-bg-<?= e($map[$status] ?? 'secondary') ?>"><?= e(ucfirst($status)) ?></span>
