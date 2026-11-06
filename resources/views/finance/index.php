<?php /** @var array $s @var array $problems */ ?>
<?php if ($problems): ?>
  <?= component('alert', ['type' => 'danger', 'message' => 'Ledger check FAILED: ' . count($problems) . ' difference(s) between the ledger and the records it is built from. Do not approve payments until an administrator investigates.']) ?>
  <ul class="small text-danger"><?php foreach (array_slice($problems, 0, 10) as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul>
<?php else: ?>
  <div class="small text-success mb-3"><i class="bi bi-shield-check" aria-hidden="true"></i> Ledger check passed: earnings, payouts, invoices and receipts all agree with the records they come from.</div>
<?php endif; ?>

<?php if ($s['pending_approvals'] > 0 && can('payments.approve')): ?>
  <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2"><span><i class="bi bi-hourglass-split"></i> <strong><?= e($s['pending_approvals']) ?></strong> payout request<?= $s['pending_approvals'] === 1 ? '' : 's' ?> waiting for approval.</span>
    <a class="btn btn-sm btn-primary" href="<?= e(url('/payouts?status=pending_approval')) ?>">Review</a></div>
<?php endif; ?>

<h2 class="h6 text-muted">Pickers</h2>
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'cash-coin', 'value' => format_ugx($s['earned']), 'label' => 'Earned by pickers']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'check2-circle', 'value' => format_ugx($s['paid']), 'label' => 'Paid out']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'wallet2', 'value' => format_ugx($s['owed']), 'label' => 'Still owed to pickers', 'hint' => format_ugx($s['pending_payouts']) . ' in requests']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'exclamation-triangle', 'value' => (string) $s['negative_accounts'], 'label' => 'Negative balances', 'hint' => 'Recovered from future earnings']) ?></div>
</div>

<h2 class="h6 text-muted">Recyclers</h2>
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'file-earmark-text', 'value' => format_ugx($s['invoiced']), 'label' => 'Invoiced']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'check2-circle', 'value' => format_ugx($s['received']), 'label' => 'Received']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'hourglass-split', 'value' => format_ugx($s['outstanding']), 'label' => 'Outstanding']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'alarm', 'value' => format_ugx($s['overdue']), 'label' => 'Overdue']) ?></div>
</div>

<div class="row g-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-body"><h2 class="h6">Receivables aging</h2>
    <table class="table table-sm mb-0"><tbody>
      <tr><td>Not yet due</td><td class="text-end"><?= e(format_ugx($s['aging']['current'])) ?></td></tr>
      <tr><td>1 to 30 days overdue</td><td class="text-end"><?= e(format_ugx($s['aging']['d1_30'])) ?></td></tr>
      <tr><td>31 to 60 days overdue</td><td class="text-end"><?= e(format_ugx($s['aging']['d31_60'])) ?></td></tr>
      <tr><td>Over 60 days overdue</td><td class="text-end"><?= e(format_ugx($s['aging']['d61'])) ?></td></tr>
    </tbody></table></div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-body"><h2 class="h6">Payments by status</h2>
    <?php if (!$s['payments_by_status']): ?><p class="small text-muted mb-0">No payments yet.</p><?php else: ?>
    <table class="table table-sm mb-2"><tbody><?php foreach ($s['payments_by_status'] as $st => $n): ?>
      <tr><td><?= component('status-badge', ['status' => $st]) ?></td><td class="text-end"><?= e($n) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
    <a href="<?= e(url('/finance/reconciliation')) ?>">Open reconciliation</a></div></div></div>
</div>
