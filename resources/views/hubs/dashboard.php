<?php /** @var array $myHubs @var int $pickersTotal @var int $pickersPending @var array $today @var array $recent @var array $stock @var int $transfersWaiting @var int $adjustmentsPending */ ?>
<?= component('welcome', [
    'user' => auth_user(), 'blurb' => 'Hub operations',
    'coming' => ['Recycler marketplace and purchase orders (Phase 6)'],
]) ?>

<div class="d-flex flex-wrap gap-2 mt-1 mb-3">
  <?php if (can('collections.record')): ?><a class="btn btn-primary btn-lg" href="<?= e(url('/collections/new')) ?>"><i class="bi bi-plus-circle"></i> Record a collection</a><?php endif; ?>
  <?php if (can('pickers.register')): ?><a class="btn btn-outline-primary btn-lg" href="<?= e(url('/pickers/register')) ?>"><i class="bi bi-person-plus"></i> Register a picker</a><?php endif; ?>
</div>

<h2 class="h6 text-muted">Today at my hubs</h2>
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'box-seam', 'value' => (string) $today['recorded'], 'label' => 'Collections recorded today']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'speedometer2', 'value' => format_kg($today['kg']), 'label' => 'Approved weight today']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'cash-coin', 'value' => format_ugx($today['value']), 'label' => 'Approved value today']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'hourglass-split', 'value' => (string) $today['pending'], 'label' => 'Waiting for approval', 'hint' => $pickersPending . ' pickers pending']) ?></div>
</div>

<h2 class="h6 text-muted">Stock at my hubs</h2>
<div class="row g-3 mb-4">
  <?php if (!$stock): ?><div class="col-12"><div class="card"><?= component('empty-state', ['icon' => 'boxes', 'title' => 'No stock yet', 'text' => 'Stock appears when collections are approved.']) ?></div></div><?php endif; ?>
  <?php foreach ($stock as $s): ?>
    <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'boxes', 'value' => format_kg($s['on_hand_kg']), 'label' => $s['category_name'] . ' on hand', 'hint' => format_kg($s['reserved_kg']) . ' reserved']) ?></div>
  <?php endforeach; ?>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'arrow-left-right', 'value' => (string) $transfersWaiting, 'label' => 'Transfers in transit', 'hint' => 'Open Inventory to confirm receipt']) ?></div>
  <?php if (can('inventory.approve')): ?><div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'clipboard-check', 'value' => (string) $adjustmentsPending, 'label' => 'Adjustments awaiting approval']) ?></div><?php endif; ?>
</div>

<?php if (!$myHubs): ?>
  <?= component('alert', ['type' => 'warning', 'message' => 'You are not assigned to a hub yet. Ask the administrator to assign you.']) ?>
<?php else: ?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card h-100"><div class="card-body pb-0 d-flex justify-content-between"><h2 class="h6">Latest collections</h2><a href="<?= e(url('/collections')) ?>">See all</a></div>
    <?php if (!$recent): ?><?= component('empty-state', ['icon' => 'box-seam', 'title' => 'No collections yet']) ?>
    <?php else: ?>
    <div class="table-responsive"><table class="table align-middle mb-0">
      <thead><tr><th>Reference</th><th>Picker</th><th class="text-end">Net</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $c): ?>
        <tr><td><a href="<?= e(url('/collections/' . $c['id'])) ?>"><?= e($c['reference']) ?></a></td><td><?= e($c['picker_name']) ?></td>
          <td class="text-end text-nowrap"><?= e(format_kg($c['net_weight_kg'])) ?></td><td><?= component('status-badge', ['status' => $c['status']]) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div></div>
  <div class="col-lg-5"><div class="card h-100"><div class="card-body">
    <h2 class="h6">My hubs</h2>
    <ul class="mb-0">
      <?php foreach ($myHubs as $h): ?><li><a href="<?= e(url('/hubs/' . $h['id'])) ?>"><?= e($h['name']) ?></a> · <?= e($h['neighbourhood']) ?></li><?php endforeach; ?>
    </ul>
  </div></div></div>
</div>
<?php endif; ?>
