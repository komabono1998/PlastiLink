<?php
/** @var string $status */
$map = [
    'active' => 'success', 'approved' => 'success', 'verified' => 'success', 'completed' => 'success', 'confirmed' => 'success', 'delivered' => 'success',
    'pending' => 'warning text-dark', 'negotiating' => 'warning text-dark', 'in_delivery' => 'info text-dark', 'dispatched' => 'info text-dark', 'in_transit' => 'info text-dark',
    'unverified' => 'secondary', 'inactive' => 'secondary', 'withdrawn' => 'secondary', 'cancelled' => 'secondary', 'expired' => 'secondary',
    'suspended' => 'danger', 'rejected' => 'danger', 'reversed' => 'danger',
];
?>
<span class="badge text-bg-<?= e($map[$status] ?? 'secondary') ?>"><?= e(ucfirst(str_replace('_', ' ', $status))) ?></span>
