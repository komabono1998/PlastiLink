<?php /** @var array $picker @var bool $own @var array $pos @var array $entries @var array $pg @var array $requests @var string $token @var int $min @var int $max @var bool $canRequest @var array $totals */
$earned = ($totals['collection_earning'] ?? 0) + ($totals['collection_reversal'] ?? 0);
$paid = -(($totals['payout'] ?? 0) + ($totals['payout_reversal'] ?? 0));
$base = $own ? '/earnings' : '/earnings/' . $picker['id'];
?>
<?php if (!$own): ?>
  <p class="text-muted mb-2"><?= e($picker['full_name']) ?> · <?= e($picker['picker_code']) ?> · <?= e($picker['neighbourhood']) ?></p>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'cash-coin', 'value' => format_ugx($earned), 'label' => 'Total earned']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'check2-circle', 'value' => format_ugx($paid), 'label' => 'Total paid out']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'hourglass-split', 'value' => format_ugx($pos['held']), 'label' => 'In payout requests']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'wallet2', 'value' => format_ugx($pos['available']), 'label' => 'Available to pay out', 'hint' => $pos['balance'] < 0 ? 'Balance is negative: recovered from future earnings' : null]) ?></div>
</div>
<?php if ($pos['balance'] < 0): ?>
  <?= component('alert', ['type' => 'warning', 'message' => 'The balance is ' . format_ugx($pos['balance']) . ' because a collection that had already been paid was reversed. It will be recovered from future earnings.']) ?>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <?php if ($canRequest): ?>
    <div class="card mb-3"><div class="card-body">
      <h2 class="h6">Request a payout</h2>
      <?php if ($pos['available'] < $min): ?>
        <p class="small text-muted mb-0">You can request a payout once at least <?= e(format_ugx($min)) ?> is available.</p>
      <?php else: ?>
        <form method="post" action="<?= e(url('/payouts')) ?>" data-loading novalidate>
          <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
          <?php if (!$own): ?><input type="hidden" name="picker_id" value="<?= e($picker['id']) ?>"><?php endif; ?>
          <?= component('field', ['name' => 'amount_ugx', 'label' => 'Amount (UGX)', 'required' => true, 'value' => old('amount_ugx', (string) $pos['available']), 'attrs' => ['inputmode' => 'numeric', 'autocomplete' => 'off'],
              'hint' => 'Between ' . format_ugx($min) . ' and ' . format_ugx(min($max, $pos['available'])) . '.']) ?>
          <?= component('field', ['name' => 'method', 'label' => 'How should it be paid?', 'type' => 'select', 'required' => true,
              'options' => ['' => 'Choose…', 'mobile_money' => 'Mobile money (to the registered phone number)', 'cash' => 'Cash at the hub']]) ?>
          <button class="btn btn-primary" type="submit">Request payout</button>
        </form>
        <p class="small text-muted mt-2 mb-0">A manager approves every payout before any money is sent.</p>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>

    <div class="card"><div class="card-body pb-0 d-flex justify-content-between"><h2 class="h6">Payout requests</h2><a href="<?= e(url('/payouts')) ?>">All</a></div>
      <?php if (!$requests): ?><?= component('empty-state', ['icon' => 'cash-stack', 'title' => 'No payout requests yet']) ?>
      <?php else: ?>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Reference</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($requests as $q): ?>
          <tr><td><a href="<?= e(url('/payouts/' . $q['id'])) ?>"><?= e($q['reference']) ?></a></td><td class="text-end text-nowrap"><?= e(format_ugx($q['amount_ugx'])) ?></td><td><?= component('status-badge', ['status' => $q['status']]) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card"><div class="card-body pb-0 d-flex justify-content-between flex-wrap gap-2"><h2 class="h6">Money in and out</h2>
      <a href="<?= e(url($base . '/statement')) ?>"><i class="bi bi-printer"></i> Income statement</a></div>
      <?php if (!$entries): ?><?= component('empty-state', ['icon' => 'wallet2', 'title' => 'Nothing yet', 'text' => 'Earnings appear here when your collections are approved.']) ?>
      <?php else: ?>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>When</th><th>What</th><th>Reference</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($entries as $en): ?>
          <tr><td class="small text-nowrap"><?= e(format_datetime($en['created_at'], 'd M Y, H:i')) ?></td><td><?= e(\App\Services\LedgerService::LABELS[$en['entry_type']] ?? $en['entry_type']) ?></td>
            <td class="small"><?= e($en['reference'] ?? '') ?></td>
            <td class="text-end text-nowrap <?= (int) $en['amount_ugx'] < 0 ? 'text-danger' : 'text-success' ?>"><?= e(\App\Helpers\Money::signed((int) $en['amount_ugx'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?= component('pagination', ['p' => $pg]) ?>
      <?php endif; ?>
    </div>
  </div>
</div>
