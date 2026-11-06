<?php /** @var array $rec @var int $balance @var array $entries */ ?>
<div class="d-flex justify-content-end mb-2 no-print"><button class="btn btn-outline-primary" type="button" onclick="window.print()"><i class="bi bi-printer"></i> Print</button></div>
<div class="card mx-auto" style="max-width:720px"><div class="card-body p-4">
  <div class="text-center mb-3"><div class="brand"><span class="brand-plasti">Plasti</span><span class="brand-link">Link</span></div>
    <h2 class="h5 mt-2 mb-0">Account statement</h2><div class="small text-muted"><?= e($rec['business_name']) ?> · <?= e(format_datetime(gmdate('Y-m-d H:i:s'), 'd M Y')) ?></div></div>
  <table class="table table-sm mb-3"><tbody>
    <tr class="table-light"><td class="fw-bold">Balance you owe PlastiLink</td><td class="text-end fw-bold"><?= e(format_ugx($balance)) ?></td></tr>
  </tbody></table>
  <?php if (!$entries): ?><?= component('empty-state', ['icon' => 'journal-richtext', 'title' => 'No activity yet']) ?>
  <?php else: ?>
  <table class="table table-sm small mb-0">
    <thead><tr><th>Date</th><th>What</th><th>Reference</th><th class="text-end">Amount</th></tr></thead>
    <tbody><?php foreach ($entries as $en): ?>
      <tr><td class="text-nowrap"><?= e(format_datetime($en['created_at'], 'd M Y')) ?></td><td><?= e(\App\Services\LedgerService::LABELS[$en['entry_type']] ?? $en['entry_type']) ?></td><td><?= e($en['reference'] ?? '') ?></td>
        <td class="text-end text-nowrap"><?= e(\App\Helpers\Money::signed((int) $en['amount_ugx'])) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
  <p class="small text-muted mt-3 mb-0">Positive amounts increase what you owe (invoices); negative amounts reduce it (confirmed payments).</p>
</div></div>
