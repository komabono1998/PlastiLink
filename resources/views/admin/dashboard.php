<?php
/** @var int $usersTotal @var int $usersActive @var int $usersPending @var int $usersSuspended
 *  @var int $pickersTotal @var int $pickersPending @var int $recyclersTotal @var int $recyclersPending @var int $hubsActive
 *  @var array $byRole @var int $failed24h @var array $recentLogins @var array $today @var array $allTime @var array $stock
 *  @var int $transfersTransit @var int $adjustmentsPending @var int $payoutsToApprove @var int $payoutsToPay @var array $orderStats @var ?array $fin */
$user = auth_user();
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$first = explode(' ', trim((string) $user['full_name']))[0];

// "Needs your attention": only items the person can act on, only when something is waiting
$attention = [];
$add = static function (bool $show, int $n, string $icon, string $label, string $href, string $btn) use (&$attention): void {
    if ($show && $n > 0) { $attention[] = compact('n', 'icon', 'label', 'href', 'btn'); }
};
$add(can('pickers.manage', 'recyclers.approve'), $pickersPending + $recyclersPending, 'patch-check', 'registration' . (($pickersPending + $recyclersPending) === 1 ? '' : 's') . ' waiting for approval', '/approvals', 'Review');
$add(can('collections.approve'), (int) $allTime['pending'], 'box-seam', 'collection' . ((int) $allTime['pending'] === 1 ? '' : 's') . ' waiting for approval', '/collections?status=pending', 'Review');
$add(can('payments.approve'), $payoutsToApprove, 'cash-stack', 'payout request' . ($payoutsToApprove === 1 ? '' : 's') . ' waiting for approval', '/payouts?status=pending_approval', 'Review');
$add(can('payments.process'), $payoutsToPay, 'credit-card', 'approved payout' . ($payoutsToPay === 1 ? '' : 's') . ' ready to pay', '/payments', 'Pay');
$add(can('orders.review'), (int) $orderStats['awaiting'], 'receipt-cutoff', 'purchase request' . ((int) $orderStats['awaiting'] === 1 ? '' : 's') . ' waiting for a response', '/orders', 'Open');
$add(can('inventory.approve'), $adjustmentsPending, 'clipboard-check', 'stock adjustment' . ($adjustmentsPending === 1 ? '' : 's') . ' waiting for approval', '/inventory/adjustments?status=pending', 'Review');

// Quick links: only what this person may open
$links = [
    ['/pickers/register', 'person-plus', 'Register a picker', 'Sign up someone in person', can('pickers.register')],
    ['/collections/new', 'plus-circle', 'Record a collection', 'Weigh and log plastic', can('collections.record')],
    ['/approvals', 'patch-check', 'Approvals', 'New pickers and recyclers', can('pickers.manage', 'recyclers.approve')],
    ['/pickers', 'person-badge', 'Waste pickers', 'Find, edit, suspend', can('pickers.view')],
    ['/users/create', 'person-gear', 'Add staff', 'Create a staff account', can('users.manage')],
    ['/users', 'people', 'Users', 'All accounts and roles', can('users.view')],
    ['/hubs', 'shop', 'Collection hubs', 'Hubs and their staff', can('hubs.view')],
    ['/recyclers', 'building', 'Recyclers', 'Buyers and their status', can('recyclers.approve', 'recyclers.manage')],
    ['/prices', 'tag', 'Prices', 'What pickers are paid per kg', can('prices.view')],
    ['/inventory', 'boxes', 'Inventory', 'Stock at every hub', can('inventory.view')],
    ['/payouts', 'cash-stack', 'Payout requests', 'Approve and pay pickers', can('payments.view')],
    ['/finance', 'graph-up-arrow', 'Finance', 'Money owed and received', can('finance.view')],
    ['/reports', 'file-earmark-bar-graph', 'Reports', 'Charts and exports', can('reports.view')],
    ['/monitoring/map', 'geo-alt', 'Map and hotspots', 'Where plastic comes from', can('monitoring.view')],
    ['/monitoring/programme', 'bullseye', 'Programme progress', 'Targets and trends', can('monitoring.view')],
    ['/neighbourhoods', 'pin-map', 'Neighbourhoods', 'Areas and coordinates', can('neighbourhoods.manage')],
    ['/admin/audit', 'journal-text', 'Audit trail', 'Who did what, when', can('audit.view')],
];
?>
<section class="dash-hero mb-4">
  <div>
    <h1 class="h3 mb-1"><?= e($greeting) ?>, <?= e($first) ?> 👋</h1>
    <p class="mb-0 text-muted"><?= e(date('l, j F Y')) ?> · <?= e($user['role_name'] ?? 'Administrator') ?></p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if (can('collections.record')): ?><a class="btn btn-primary" href="<?= e(url('/collections/new')) ?>"><i class="bi bi-plus-lg"></i> Record collection</a><?php endif; ?>
    <?php if (can('pickers.register')): ?><a class="btn btn-outline-primary" href="<?= e(url('/pickers/register')) ?>"><i class="bi bi-person-plus"></i> Register picker</a><?php endif; ?>
    <?php if (can('reports.view')): ?><a class="btn btn-outline-primary" href="<?= e(url('/reports')) ?>"><i class="bi bi-bar-chart"></i> Reports</a><?php endif; ?>
  </div>
</section>

<section class="mb-4" aria-labelledby="attn-h">
  <h2 class="h6 text-muted" id="attn-h">Needs your attention</h2>
  <?php if (!$attention): ?>
    <div class="card"><div class="card-body d-flex align-items-center gap-3">
      <span class="stat-icon"><i class="bi bi-emoji-smile" aria-hidden="true"></i></span>
      <div><strong>You're all caught up.</strong><div class="small text-muted">Nothing is waiting for approval right now.</div></div>
    </div></div>
  <?php else: ?>
    <div class="row g-3">
    <?php foreach ($attention as $a): ?>
      <div class="col-12 col-md-6 col-xl-4">
        <div class="card attn-card h-100"><div class="card-body d-flex align-items-center gap-3">
          <span class="attn-count"><?= e($a['n']) ?></span>
          <div class="flex-grow-1"><i class="bi bi-<?= e($a['icon']) ?> text-muted" aria-hidden="true"></i> <?= e($a['label']) ?></div>
          <a class="btn btn-sm btn-primary" href="<?= e(url($a['href'])) ?>"><?= e($a['btn']) ?></a>
        </div></div>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="mb-4" aria-labelledby="quick-h">
  <h2 class="h6 text-muted" id="quick-h">Quick links</h2>
  <div class="row g-3">
  <?php foreach ($links as [$href, $icon, $label, $desc, $show]): if (!$show) { continue; } ?>
    <div class="col-6 col-md-4 col-xl-3">
      <a class="quick-tile" href="<?= e(url($href)) ?>">
        <span class="stat-icon"><i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i></span>
        <span><span class="d-block fw-semibold"><?= e($label) ?></span><span class="d-block small text-muted"><?= e($desc) ?></span></span>
      </a>
    </div>
  <?php endforeach; ?>
  </div>
</section>

<h2 class="h6 text-muted">At a glance</h2>
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3"><a class="text-decoration-none text-reset" href="<?= e(url('/pickers')) ?>"><?= component('stat-card', ['icon' => 'person-badge', 'value' => (string) $pickersTotal, 'label' => 'Waste pickers', 'hint' => $pickersPending . ' pending']) ?></a></div>
  <div class="col-6 col-xl-3"><a class="text-decoration-none text-reset" href="<?= e(url('/hubs')) ?>"><?= component('stat-card', ['icon' => 'shop', 'value' => (string) $hubsActive, 'label' => 'Active hubs']) ?></a></div>
  <div class="col-6 col-xl-3"><a class="text-decoration-none text-reset" href="<?= e(url('/recyclers')) ?>"><?= component('stat-card', ['icon' => 'building', 'value' => (string) $recyclersTotal, 'label' => 'Recyclers', 'hint' => $recyclersPending . ' pending']) ?></a></div>
  <div class="col-6 col-xl-3"><?= component('stat-card', ['icon' => 'shield-exclamation', 'value' => (string) $failed24h, 'label' => 'Failed sign-ins (24 h)', 'hint' => $usersSuspended . ' suspended accounts']) ?></div>
</div>

<h2 class="h6 text-muted">Plastic collected (approved)</h2>
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3"><?= component('stat-card', ['icon' => 'box-seam', 'value' => format_kg($allTime['kg']), 'label' => 'Total collected', 'hint' => $allTime['approved'] . ' collections']) ?></div>
  <div class="col-6 col-xl-3"><?= component('stat-card', ['icon' => 'cash-coin', 'value' => format_ugx($allTime['value']), 'label' => 'Total value']) ?></div>
  <div class="col-6 col-xl-3"><?= component('stat-card', ['icon' => 'calendar-day', 'value' => format_kg($today['kg']), 'label' => 'Collected today', 'hint' => $today['recorded'] . ' recorded']) ?></div>
  <div class="col-6 col-xl-3"><?= component('stat-card', ['icon' => 'hourglass-split', 'value' => (string) $allTime['pending'], 'label' => 'Collections awaiting approval']) ?></div>
</div>

<?php if (!empty($fin)): ?>
<h2 class="h6 text-muted">Money</h2>
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3"><?= component('stat-card', ['icon' => 'wallet2', 'value' => format_ugx($fin['owed']), 'label' => 'Owed to pickers', 'hint' => format_ugx($fin['paid']) . ' paid so far']) ?></div>
  <div class="col-6 col-xl-3"><?= component('stat-card', ['icon' => 'file-earmark-text', 'value' => format_ugx($fin['outstanding']), 'label' => 'Owed by recyclers', 'hint' => format_ugx($fin['overdue']) . ' overdue']) ?></div>
  <div class="col-6 col-xl-3"><?= component('stat-card', ['icon' => 'check2-circle', 'value' => format_ugx($fin['received']), 'label' => 'Received from recyclers']) ?></div>
  <div class="col-6 col-xl-3"><a class="text-decoration-none text-reset" href="<?= e(url('/finance')) ?>"><?= component('stat-card', ['icon' => 'graph-up-arrow', 'value' => 'Open', 'label' => 'Finance summary and ledger check']) ?></a></div>
</div>
<?php endif; ?>

<?php if (can('orders.review')): ?>
<h2 class="h6 text-muted">Marketplace</h2>
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3"><a class="text-decoration-none text-reset" href="<?= e(url('/orders')) ?>"><?= component('stat-card', ['icon' => 'hourglass-split', 'value' => (string) $orderStats['awaiting'], 'label' => 'Purchase requests awaiting a response']) ?></a></div>
  <div class="col-6 col-xl-3"><?= component('stat-card', ['icon' => 'truck', 'value' => (string) $orderStats['active'], 'label' => 'Orders being delivered']) ?></div>
  <div class="col-6 col-xl-3"><?= component('stat-card', ['icon' => 'check2-circle', 'value' => (string) $orderStats['completed'], 'label' => 'Orders completed']) ?></div>
  <div class="col-6 col-xl-3"><?= component('stat-card', ['icon' => 'cash-coin', 'value' => format_ugx($orderStats['received_value']), 'label' => 'Value confirmed received', 'hint' => format_kg($orderStats['received_kg'])]) ?></div>
</div>
<?php endif; ?>

<h2 class="h6 text-muted">Stock on hand</h2>
<div class="row g-3 mb-4">
  <?php if (!$stock): ?><div class="col-12"><div class="card"><?= component('empty-state', ['icon' => 'boxes', 'title' => 'No stock yet']) ?></div></div><?php endif; ?>
  <?php foreach ($stock as $s): ?>
    <div class="col-6 col-xl-3"><?= component('stat-card', ['icon' => 'boxes', 'value' => format_kg($s['on_hand_kg']), 'label' => $s['category_name'] . ' on hand (all hubs)', 'hint' => format_kg($s['reserved_kg']) . ' reserved']) ?></div>
  <?php endforeach; ?>
  <div class="col-6 col-xl-3"><?= component('stat-card', ['icon' => 'arrow-left-right', 'value' => (string) $transfersTransit, 'label' => 'Transfers in transit']) ?></div>
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
