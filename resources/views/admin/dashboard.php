<?php
/** @var int $usersTotal @var int $usersActive @var int $usersPending @var int $usersSuspended
 *  @var int $pickersTotal @var int $pickersPending @var int $recyclersTotal @var int $recyclersPending @var int $hubsActive
 *  @var array $byRole @var int $failed24h @var array $recentLogins */
$waiting = $pickersPending + $recyclersPending;
?>
<?= component('welcome', ['user' => auth_user(), 'blurb' => 'Platform administration', 'coming' => ['Collections, inventory and finance (Phases 4 to 7)']]) ?>

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
