<?php /** @var array $rows @var array $pg @var array $f @var string $role */
$staff = !in_array($role, ['waste_picker', 'recycler'], true);
?>
<form class="row g-2 mb-3" method="get" action="<?= e(url('/payments')) ?>">
  <div class="col-12 col-md-3"><label class="visually-hidden" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Reference or name"></div>
  <div class="col-6 col-md-2"><label class="visually-hidden" for="status">Status</label>
    <select class="form-select" id="status" name="status"><option value="">Any status</option>
      <?php foreach (['pending', 'processing', 'successful', 'failed', 'reversed'] as $s): ?><option value="<?= e($s) ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select></div>
  <?php if ($staff): ?>
  <div class="col-6 col-md-2"><label class="visually-hidden" for="direction">Direction</label>
    <select class="form-select" id="direction" name="direction"><option value="">In and out</option><option value="out"<?= $f['direction'] === 'out' ? ' selected' : '' ?>>Paid out</option><option value="in"<?= $f['direction'] === 'in' ? ' selected' : '' ?>>Received</option></select></div>
  <?php endif; ?>
  <div class="col-6 col-md-2"><label class="visually-hidden" for="from">From</label><input class="form-control" id="from" name="from" type="date" value="<?= e($f['from_raw']) ?>"></div>
  <div class="col-6 col-md-2"><label class="visually-hidden" for="to">To</label><input class="form-control" id="to" name="to" type="date" value="<?= e($f['to_raw']) ?>"></div>
  <div class="col-12 col-md-1"><button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-search"></i></button></div>
</form>
<div class="card">
  <?php if (!$rows): ?><?= component('empty-state', ['icon' => 'credit-card', 'title' => 'No payments found']) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Reference</th><th>Who</th><th>Type</th><th class="text-end">Amount</th><th>Method</th><th>Status</th><th>Reconciled</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $p): ?>
      <tr><td class="text-nowrap"><a class="fw-semibold" href="<?= e(url('/payments/' . $p['id'])) ?>"><?= e($p['reference']) ?></a></td>
        <td><?= e($p['direction'] === 'out' ? $p['picker_name'] : $p['business_name']) ?><div class="small text-muted"><?= e($p['direction'] === 'out' ? $p['picker_code'] : $p['invoice_number']) ?></div></td>
        <td><?= $p['direction'] === 'out' ? 'Payout' : 'Received' ?></td>
        <td class="text-end text-nowrap"><?= e(format_ugx($p['amount_ugx'])) ?></td><td><?= e(ucfirst(str_replace('_', ' ', $p['method']))) ?></td>
        <td><?= component('status-badge', ['status' => $p['status']]) ?></td>
        <td class="small"><?= $p['reconciled_at'] ? '<i class="bi bi-check2 text-success"></i> ' . e($p['statement_reference']) : '—' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= component('pagination', ['p' => $pg]) ?>
  <?php endif; ?>
</div>
