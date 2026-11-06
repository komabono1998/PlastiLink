<?php /** @var array $rows @var array $pg @var array $f @var array $summary */
$fmt = static fn(array $x): string => e($x[0]) . ' · ' . e(format_ugx($x[1]));
?>
<div class="row g-3 mb-3">
  <div class="col-md-6"><div class="card"><div class="card-body"><h2 class="h6">Paid out (successful)</h2>
    <div class="small">Reconciled: <strong><?= $fmt($summary['out']['reconciled']) ?></strong></div><div class="small">Not yet reconciled: <strong class="text-danger"><?= $fmt($summary['out']['unreconciled']) ?></strong></div></div></div></div>
  <div class="col-md-6"><div class="card"><div class="card-body"><h2 class="h6">Received (successful)</h2>
    <div class="small">Reconciled: <strong><?= $fmt($summary['in']['reconciled']) ?></strong></div><div class="small">Not yet reconciled: <strong class="text-danger"><?= $fmt($summary['in']['unreconciled']) ?></strong></div></div></div></div>
</div>

<form class="row g-2 mb-3" method="get" action="<?= e(url('/finance/reconciliation')) ?>">
  <div class="col-6 col-md-3"><label class="visually-hidden" for="reconciled">Show</label>
    <select class="form-select" id="reconciled" name="reconciled"><option value="0"<?= $f['reconciled'] === '0' ? ' selected' : '' ?>>Not reconciled</option><option value="1"<?= $f['reconciled'] === '1' ? ' selected' : '' ?>>Reconciled</option><option value=""<?= $f['reconciled'] === '' ? ' selected' : '' ?>>All</option></select></div>
  <div class="col-6 col-md-2"><label class="visually-hidden" for="direction">Direction</label>
    <select class="form-select" id="direction" name="direction"><option value="">In and out</option><option value="out"<?= $f['direction'] === 'out' ? ' selected' : '' ?>>Paid out</option><option value="in"<?= $f['direction'] === 'in' ? ' selected' : '' ?>>Received</option></select></div>
  <div class="col-6 col-md-2"><label class="visually-hidden" for="from">From</label><input class="form-control" id="from" name="from" type="date" value="<?= e($f['from_raw']) ?>"></div>
  <div class="col-6 col-md-2"><label class="visually-hidden" for="to">To</label><input class="form-control" id="to" name="to" type="date" value="<?= e($f['to_raw']) ?>"></div>
  <div class="col-12 col-md-3"><button class="btn btn-outline-primary w-100" type="submit">Filter</button></div>
</form>

<form method="post" action="<?= e(url('/finance/reconciliation')) ?>">
  <?= csrf_field() ?>
  <div class="card mb-3">
    <?php if (!$rows): ?><?= component('empty-state', ['icon' => 'check2-square', 'title' => 'Nothing to show']) ?>
    <?php else: ?>
    <div class="table-responsive"><table class="table align-middle mb-0">
      <thead><tr><th><span class="visually-hidden">Select</span></th><th>Reference</th><th>Who</th><th class="text-end">Amount</th><th>Method / transaction</th><th>Reconciled</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $p): ?>
        <tr><td><?php if (!$p['reconciled_at']): ?><input class="form-check-input" type="checkbox" name="payment_ids[]" value="<?= e($p['id']) ?>" aria-label="Select <?= e($p['reference']) ?>"><?php endif; ?></td>
          <td class="text-nowrap"><a href="<?= e(url('/payments/' . $p['id'])) ?>"><?= e($p['reference']) ?></a><div class="small text-muted"><?= $p['direction'] === 'out' ? 'Payout' : 'Received' ?></div></td>
          <td><?= e($p['direction'] === 'out' ? $p['picker_name'] : $p['business_name']) ?></td><td class="text-end text-nowrap"><?= e(format_ugx($p['amount_ugx'])) ?></td>
          <td class="small"><?= e(ucfirst(str_replace('_', ' ', $p['method']))) ?><br><?= e($p['provider_reference'] ?? '—') ?></td>
          <td class="small"><?= $p['reconciled_at'] ? '<i class="bi bi-check2 text-success"></i> ' . e($p['statement_reference']) : '—' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?= component('pagination', ['p' => $pg]) ?>
    <?php endif; ?>
  </div>
  <div class="card"><div class="card-body row g-2 align-items-end">
    <div class="col-md-6"><label class="form-label" for="statement_reference">Statement reference</label>
      <input class="form-control" id="statement_reference" name="statement_reference" maxlength="80" placeholder="e.g. MTN statement 2026-11-30" required></div>
    <div class="col-md-3"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2-square"></i> Reconcile ticked payments</button></div>
  </div></div>
</form>
