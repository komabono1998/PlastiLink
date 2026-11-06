<?php /** @var array $picker @var array $entries @var int $earned @var int $paid @var int $opening @var int $closing @var string $from_raw @var string $to_raw @var string $statement_no */ ?>
<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-6 col-md-3"><label class="visually-hidden" for="from">From</label><input class="form-control" id="from" name="from" type="date" value="<?= e($from_raw) ?>"></div>
  <div class="col-6 col-md-3"><label class="visually-hidden" for="to">To</label><input class="form-control" id="to" name="to" type="date" value="<?= e($to_raw) ?>"></div>
  <div class="col-6 col-md-2"><button class="btn btn-outline-primary w-100" type="submit">Show period</button></div>
  <div class="col-6 col-md-2"><button class="btn btn-primary w-100" type="button" onclick="window.print()"><i class="bi bi-printer"></i> Print</button></div>
</form>

<div class="card mx-auto" style="max-width:720px"><div class="card-body p-4">
  <div class="text-center mb-3">
    <div class="brand"><span class="brand-plasti">Plasti</span><span class="brand-link">Link</span></div>
    <h2 class="h5 mt-2 mb-0">Income statement</h2>
    <div class="small text-muted"><?= e($statement_no) ?> · <?= $from_raw || $to_raw ? e(($from_raw ?: 'start') . ' to ' . ($to_raw ?: 'today')) : 'All time' ?></div>
  </div>
  <dl class="row small mb-3">
    <dt class="col-4">Waste picker</dt><dd class="col-8"><?= e($picker['full_name']) ?> (<?= e($picker['picker_code']) ?>)</dd>
    <dt class="col-4">Neighbourhood</dt><dd class="col-8"><?= e($picker['neighbourhood']) ?></dd>
  </dl>
  <table class="table table-sm mb-3">
    <tbody>
      <tr><td>Opening balance</td><td class="text-end"><?= e(format_ugx($opening)) ?></td></tr>
      <tr><td>Earned from approved collections</td><td class="text-end text-success"><?= e(format_ugx($earned)) ?></td></tr>
      <tr><td>Paid out</td><td class="text-end text-danger"><?= e(format_ugx($paid)) ?></td></tr>
      <tr class="table-light"><td class="fw-bold">Closing balance (owed to the picker)</td><td class="text-end fw-bold"><?= e(format_ugx($closing)) ?></td></tr>
    </tbody>
  </table>
  <?php if ($entries): ?>
  <table class="table table-sm small mb-0">
    <thead><tr><th>Date</th><th>What</th><th>Reference</th><th class="text-end">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($entries as $en): ?>
      <tr><td class="text-nowrap"><?= e(format_datetime($en['created_at'], 'd M Y')) ?></td><td><?= e(\App\Services\LedgerService::LABELS[$en['entry_type']] ?? $en['entry_type']) ?></td><td><?= e($en['reference'] ?? '') ?></td>
        <td class="text-end text-nowrap"><?= e(\App\Helpers\Money::signed((int) $en['amount_ugx'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <p class="small text-muted mt-3 mb-0">Generated <?= e(format_datetime(gmdate('Y-m-d H:i:s'))) ?>. Contains no phone number or national ID.</p>
</div></div>
