<?php
/** @var int $usersTotal @var int $usersActive @var int $usersPending @var int $usersSuspended
 *  @var int $pickersTotal @var int $pickersPending @var int $recyclersTotal @var int $recyclersPending @var int $hubsActive
 *  @var array $byRole @var int $failed24h @var array $recentLogins @var array $today @var array $allTime @var array $stock @var int $transfersTransit @var int $adjustmentsPending @var array $orderStats */
$waiting = $pickersPending + $recyclersPending;
?>
<?= component('welcome', ['user' => auth_user(), 'blurb' => 'Platform administration', 'coming' => ['Finance and payments (Phase 7)', 'Reports and analytics (Phase 8)']]) ?>

<?php if ($waiting > 0 && can('pickers.manage', 'recyclers.approve')): ?>
  <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2" role="status">
    <span><i class="bi bi-hourglass-split"></i> <strong><?= e($waiting) ?></strong> registration<?= $waiting === 1 ? '' : 's' ?> waiting for approval
      (<?= e($pickersTotal ? $pickersPending : 0) ?> pickers, <?= e($recyclersPending) ?> recyclers).</span>
    <a class="btn btn-sm btn-primary" href="<?= e(url('/approvals')) ?>">Review now</a>
  </div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'person-badge', 'value' => (string) $pickersTotal, 'label' => 'Waste pickers', 'hint' => $pickersPending . ' pending']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'shop', 'value' => (string) $hubsActive, 'label' => 'Active hubs']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'building', 'value' => (string) $recyclersTotal, 'label' => 'Recyclers', 'hint' => $recyclersPending . ' pending']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'shield-exclamation', 'value' => (string) $failed24h, 'label' => 'Failed sign-ins (24 h)', 'hint' => $usersSuspended . ' suspended accounts']) ?></div>
</div>

<h2 class="h6 text-muted">Plastic collected (approved)</h2>
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'box-seam', 'value' => format_kg($allTime['kg']), 'label' => 'Total collected', 'hint' => $allTime['approved'] . ' collections']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'cash-coin', 'value' => format_ugx($allTime['value']), 'label' => 'Total value']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'calendar-day', 'value' => format_kg($today['kg']), 'label' => 'Collected today', 'hint' => $today['recorded'] . ' recorded']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'hourglass-split', 'value' => (string) $allTime['pending'], 'label' => 'Collections awaiting approval']) ?></div>
</div>

<?php if (can('orders.review')): ?>
<h2 class="h6 text-muted">Marketplace</h2>
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><a class="text-decoration-none text-reset" href="<?= e(url('/orders?awaiting=1')) ?>"><?= component('stat-card', ['icon' => 'hourglass-split', 'value' => (string) $orderStats['awaiting'], 'label' => 'Purchase requests awaiting a response']) ?></a></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'truck', 'value' => (string) $orderStats['active'], 'label' => 'Orders being delivered']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'check2-circle', 'value' => (string) $orderStats['completed'], 'label' => 'Orders completed']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'cash-coin', 'value' => format_ugx($orderStats['received_value']), 'label' => 'Value confirmed received', 'hint' => format_kg($orderStats['received_kg'])]) ?></div>
</div>
<?php endif; ?>

<h2 class="h6 text-muted">Stock on hand</h2>
<div class="row g-3 mb-4">
  <?php if (!$stock): ?><div class="col-12"><div class="card"><?= component('empty-state', ['icon' => 'boxes', 'title' => 'No stock yet']) ?></div></div><?php endif; ?>
  <?php foreach ($stock as $s): ?>
    <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'boxes', 'value' => format_kg($s['on_hand_kg']), 'label' => $s['category_name'] . ' on hand (all hubs)', 'hint' => format_kg($s['reserved_kg']) . ' reserved']) ?></div>
  <?php endforeach; ?>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'arrow-left-right', 'value' => (string) $transfersTransit, 'label' => 'Transfers in transit']) ?></div>
  <?php if (can('inventory.approve')): ?><div class="col-6 col-lg-3"><a class="text-decoration-none text-reset" href="<?= e(url('/inventory/adjustments?status=pending')) ?>"><?= component('stat-card', ['icon' => 'clipboard-check', 'value' => (string) $adjustmentsPending, 'label' => 'Adjustments awaiting approval']) ?></a></div><?php endif; ?>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-body">
      <h2 class="h6">Users by role <span class="text-muted fw-normal">(<?= e($usersActive) ?> of <?= e($usersTotal) ?> active)</span></h2>
      <div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th>Role</th><th class="text-end">Users</th></tr></thead>
        <tbody>
        <?php foreach ($byRole as $r): ?>
          <tr><td><?= e($r['name']) ?></td><td class="text-end"><?= e($r['total']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-body">
      <h2 class="h6">Recent sign-ins</h2>
      <?php if (!$recentLogins): ?>
        <?= component('empty-state', ['icon' => 'clock-history', 'title' => 'No sign-ins yet']) ?>
      <?php else: ?>
        <div class="table-responsive"><table class="table align-middle mb-0">
          <thead><tr><th>User</th><th>When</th></tr></thead>
          <tbody>
          <?php foreach ($recentLogins as $l): ?>
            <tr><td><?= e($l['full_name'] ?? 'Unknown') ?></td><td><?= e(format_datetime($l['created_at'])) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div></div>
  </div>
</div>
