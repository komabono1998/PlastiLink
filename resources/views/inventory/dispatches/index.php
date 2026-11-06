<?php /** @var array $rows @var array $pg @var array $f @var string $tab */ ?>
<?= component('inventory-tabs', ['tab' => $tab]) ?>
<div class="d-flex flex-wrap gap-2 justify-content-between mb-3">
  <form class="d-flex gap-2" method="get" action="<?= e(url('/inventory/dispatches')) ?>">
    <label class="visually-hidden" for="status">Status</label>
    <select class="form-select" id="status" name="status"><option value="">Any status</option>
      <?php foreach (['dispatched' => 'Dispatched', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <button class="btn btn-outline-primary" type="submit">Filter</button>
  </form>
  <?php if (can('inventory.manage')): ?><a class="btn btn-primary" href="<?= e(url('/inventory/dispatches/new')) ?>"><i class="bi bi-plus-lg"></i> New dispatch</a><?php endif; ?>
</div>
<div class="card">
  <?php if (!$rows): ?><?= component('empty-state', ['icon' => 'truck', 'title' => 'No dispatches']) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Reference</th><th>Hub</th><th>Recycler</th><th>Material</th><th class="text-end">Weight</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $d): ?>
      <tr><td class="text-nowrap"><a class="fw-semibold" href="<?= e(url('/inventory/dispatches/' . $d['id'])) ?>"><?= e($d['reference']) ?></a></td>
        <td><?= e($d['hub_name']) ?></td><td><?= e($d['recycler_name']) ?></td><td><?= e($d['category_name']) ?></td>
        <td class="text-end text-nowrap"><?= e(format_kg($d['qty_kg'])) ?></td><td><?= e(ucfirst($d['status'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= component('pagination', ['p' => $pg]) ?>
  <?php endif; ?>
</div>
