<?php /** @var array $rows @var array $pg @var array $f @var string $tab */ ?>
<?= component('inventory-tabs', ['tab' => $tab]) ?>
<div class="d-flex flex-wrap gap-2 justify-content-between mb-3">
  <form class="d-flex gap-2" method="get" action="<?= e(url('/inventory/adjustments')) ?>">
    <label class="visually-hidden" for="status">Status</label>
    <select class="form-select" id="status" name="status"><option value="">Any status</option>
      <?php foreach (['pending', 'approved', 'rejected'] as $s): ?><option value="<?= e($s) ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select>
    <button class="btn btn-outline-primary" type="submit">Filter</button>
  </form>
  <?php if (can('inventory.manage')): ?><a class="btn btn-primary" href="<?= e(url('/inventory/adjustments/new')) ?>"><i class="bi bi-plus-lg"></i> New request</a><?php endif; ?>
</div>
<div class="card">
  <?php if (!$rows): ?><?= component('empty-state', ['icon' => 'clipboard-check', 'title' => 'No adjustment requests']) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Reference</th><th>Hub</th><th>Material</th><th>Kind</th><th class="text-end">Change</th><th>Requested by</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $a): $neg = str_starts_with((string) $a['change_kg'], '-'); ?>
      <tr><td class="text-nowrap"><a class="fw-semibold" href="<?= e(url('/inventory/adjustments/' . $a['id'])) ?>"><?= e($a['reference']) ?></a></td>
        <td><?= e($a['hub_name']) ?></td><td><?= e($a['category_name']) ?></td><td><?= e(\App\Services\AdjustmentService::KINDS[$a['kind']] ?? $a['kind']) ?></td>
        <td class="text-end text-nowrap <?= $neg ? 'text-danger' : 'text-success' ?>"><?= e(($neg ? '' : '+') . format_kg($a['change_kg'])) ?></td>
        <td><?= e($a['requested_by_name']) ?></td><td><?= component('status-badge', ['status' => $a['status']]) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= component('pagination', ['p' => $pg]) ?>
  <?php endif; ?>
</div>
