<?php
/** @var int $usersTotal @var int $usersActive @var int $usersPending @var int $usersSuspended
 *  @var array $byRole @var int $failed24h @var array $recentLogins */
?>
<?= component('welcome', [
    'user' => auth_user(),
    'blurb' => 'Platform administration',
    'coming' => ['User, picker, recycler and hub management (Phase 3)', 'Collections, inventory and finance (Phases 4 to 7)'],
]) ?>

<div class="row g-3 mb-4 mt-1">
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'people', 'value' => (string) $usersTotal, 'label' => 'Registered users']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'person-check', 'value' => (string) $usersActive, 'label' => 'Active accounts']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'hourglass-split', 'value' => (string) $usersPending, 'label' => 'Pending approval']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'shield-exclamation', 'value' => (string) $failed24h, 'label' => 'Failed sign-ins (24 h)', 'hint' => $usersSuspended . ' suspended']) ?></div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-body">
      <h2 class="h6">Users by role</h2>
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
