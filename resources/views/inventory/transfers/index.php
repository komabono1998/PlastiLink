<?php /** @var array $rows @var array $pg @var array $f @var string $tab */ ?>
<?= component('inventory-tabs', ['tab' => $tab]) ?>
<div class="d-flex flex-wrap gap-2 justify-content-between mb-3">
  <form class="d-flex gap-2" method="get" action="<?= e(url('/inventory/transfers')) ?>">
    <label class="visually-hidden" for="status">Status</label>
    <select class="form-select" id="status" name="status"><option value="">Any status</option>
      <?php foreach (['in_transit' => 'In transit', 'received' => 'Received', 'cancelled' => 'Cancelled'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <button class="btn btn-outline-primary" type="submit">Filter</button>
  </form>
  <?php if (can('inventory.manage')): ?><a class="btn btn-primary" href="<?= e(url('/inventory/transfers/new')) ?>"><i class="bi bi-plus-lg"></i> New transfer</a><?php endif; ?>
</div>
<div class="card">
  <?php if (!$rows): ?><?= component('empty-state', ['icon' => 'arrow-left-right', 'title' => 'No transfers']) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Reference</th><th>From</th><th>To</th><th>Material</th><th class="text-end">Sent</th><th class="text-end">Received</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $t): ?>
      <tr><td class="text-nowrap"><a class="fw-semibold" href="<?= e(url('/inventory/transfers/' . $t['id'])) ?>"><?= e($t['reference']) ?></a></td>
        <td><?= e($t['from_hub']) ?></td><td><?= e($t['to_hub']) ?></td><td><?= e($t['category_name']) ?></td>
        <td class="text-end text-nowrap"><?= e(format_kg($t['qty_sent_kg'])) ?></td><td class="text-end text-nowrap"><?= $t['qty_received_kg'] !== null ? e(format_kg($t['qty_received_kg'])) : '—' ?></td>
        <td><?= e(ucfirst(str_replace('_', ' ', $t['status']))) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= component('pagination', ['p' => $pg]) ?>
  <?php endif; ?>
</div>
