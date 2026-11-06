<?php /** @var array $rows @var array $pg @var array $f @var array $hubs @var string $tab */ ?>
<?= component('inventory-tabs', ['tab' => $tab]) ?>
<form class="row g-2 mb-3" method="get" action="<?= e(url('/inventory/movements')) ?>">
  <div class="col-6 col-md-3"><label class="visually-hidden" for="hub_id">Hub</label>
    <select class="form-select" id="hub_id" name="hub_id"><option value="">All hubs</option>
      <?php foreach ($hubs as $id => $n): ?><option value="<?= e($id) ?>"<?= (string) $f['hub_id'] === (string) $id ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-3"><label class="visually-hidden" for="type">Type</label>
    <select class="form-select" id="type" name="type"><option value="">All types</option>
      <?php foreach (\App\Helpers\Labels::movementTypes() as $k => $l): ?><option value="<?= e($k) ?>"<?= $f['type'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><label class="visually-hidden" for="from">From</label><input class="form-control" id="from" name="from" type="date" value="<?= e($f['from_raw']) ?>"></div>
  <div class="col-6 col-md-2"><label class="visually-hidden" for="to">To</label><input class="form-control" id="to" name="to" type="date" value="<?= e($f['to_raw']) ?>"></div>
  <div class="col-12 col-md-2"><button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-search"></i> Filter</button></div>
</form>
<div class="card">
  <?php if (!$rows): ?><?= component('empty-state', ['icon' => 'journal-text', 'title' => 'No movements found']) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>When</th><th>Hub</th><th>Material</th><th>Movement</th><th class="text-end">Change</th><th class="text-end">Balance after</th><th>Reference</th><th>By</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $m): $neg = str_starts_with((string) $m['qty_kg'], '-'); $isRes = in_array($m['movement_type'], ['reserve', 'release'], true); ?>
      <tr>
        <td class="small text-nowrap"><?= e(format_datetime($m['created_at'])) ?></td><td><?= e($m['hub_name']) ?></td><td><?= e($m['category_name']) ?></td>
        <td><?= e(\App\Helpers\Labels::movement($m['movement_type'])) ?></td>
        <td class="text-end text-nowrap <?= $isRes ? 'text-muted' : ($neg ? 'text-danger' : 'text-success') ?>"><?= $isRes ? e(($m['reserved_delta_kg'][0] === '-' ? '' : '+') . format_kg($m['reserved_delta_kg'])) . ' reserved' : e(($neg ? '' : '+') . format_kg($m['qty_kg'])) ?></td>
        <td class="text-end text-nowrap"><?= e(format_kg($m['balance_after_kg'])) ?></td>
        <td class="small text-nowrap"><?= e($m['reference'] ?? '') ?></td><td class="small"><?= e($m['by_name'] ?? 'System') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= component('pagination', ['p' => $pg]) ?>
  <?php endif; ?>
</div>
