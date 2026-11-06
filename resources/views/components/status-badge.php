<?php
/** @var string $status */
$map = [
    'active' => 'success', 'approved' => 'success', 'verified' => 'success', 'completed' => 'success', 'confirmed' => 'success', 'delivered' => 'success',
    'successful' => 'success', 'paid' => 'success',
    'pending' => 'warning text-dark', 'negotiating' => 'warning text-dark', 'pending_approval' => 'warning text-dark', 'issued' => 'warning text-dark',
    'in_delivery' => 'info text-dark', 'dispatched' => 'info text-dark', 'in_transit' => 'info text-dark', 'processing' => 'info text-dark', 'partially_paid' => 'info text-dark',
    'unverified' => 'secondary', 'inactive' => 'secondary', 'withdrawn' => 'secondary', 'cancelled' => 'secondary', 'expired' => 'secondary', 'void' => 'secondary',
    'suspended' => 'danger', 'rejected' => 'danger', 'reversed' => 'danger', 'failed' => 'danger', 'overdue' => 'danger',
];
?>
<span class="badge text-bg-<?= e($map[$status] ?? 'secondary') ?>"><?= e(ucfirst(str_replace('_', ' ', $status))) ?></span>
