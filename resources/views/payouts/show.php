<?php /** @var array $q @var ?array $payment @var array $attempts @var bool $canDecide @var bool $own @var bool $canCancel @var bool $canProcess @var string $provider */ ?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-body">
      <div class="d-flex justify-content-between gap-2 flex-wrap"><div><h2 class="h5 mb-0"><?= e($q['reference']) ?></h2><div class="small text-muted">Payout request</div></div><?= component('status-badge', ['status' => $q['status']]) ?></div>
      <dl class="row mt-3 mb-0">
        <dt class="col-sm-4">Picker</dt><dd class="col-sm-8"><?= e($q['picker_name']) ?> (<?= e($q['picker_code']) ?>)</dd>
        <dt class="col-sm-4">Amount</dt><dd class="col-sm-8 fs-5 fw-bold"><?= e(format_ugx($q['amount_ugx'])) ?></dd>
        <dt class="col-sm-4">Paid by</dt><dd class="col-sm-8"><?= $q['method'] === 'cash' ? 'Cash at the hub' : 'Mobile money to ' . e(display_phone($q['destination_phone'])) ?></dd>
        <dt class="col-sm-4">Requested</dt><dd class="col-sm-8"><?= e(format_datetime($q['requested_at'])) ?> by <?= e($q['requested_by_name']) ?></dd>
        <?php if ($q['decided_at']): ?><dt class="col-sm-4"><?= e(ucfirst($q['status'] === 'rejected' ? 'Rejected' : 'Decided')) ?></dt><dd class="col-sm-8"><?= e($q['decided_by_name'] ?? '—') ?>, <?= e(format_datetime($q['decided_at'])) ?><?= $q['decision_note'] ? '<br>' . e($q['decision_note']) : '' ?></dd><?php endif; ?>
      </dl>
      <?php if ($payment): ?><hr><a class="btn btn-outline-primary" href="<?= e(url('/payments/' . $payment['id'])) ?>"><i class="bi bi-credit-card"></i> Payment <?= e($payment['reference']) ?> · <?= e(ucfirst($payment['status'])) ?></a><?php endif; ?>
    </div></div>

    <?php if ($canProcess): ?>
    <div class="card"><div class="card-body"><h2 class="h6">Pay this request</h2>
      <?php if ($provider === 'manual'): ?>
        <p class="small text-muted">Send the money yourself (mobile money app or cash), then record it here. This is checked later against the statement.</p>
        <form method="post" action="<?= e(url('/payments/' . $payment['id'] . '/process')) ?>" data-loading novalidate>
          <?= csrf_field() ?>
          <?php if ($q['method'] === 'mobile_money'): ?>
            <?= component('field', ['name' => 'provider_reference', 'label' => 'Mobile money transaction ID', 'required' => true, 'attrs' => ['autocomplete' => 'off']]) ?>
          <?php else: ?>
            <?= component('field', ['name' => 'receipt_note', 'label' => 'Cash receipt note', 'required' => true, 'hint' => 'Who was paid and who witnessed it.']) ?>
          <?php endif; ?>
          <button class="btn btn-primary" type="submit"><i class="bi bi-check-circle"></i> Record as paid <?= e(format_ugx($q['amount_ugx'])) ?></button>
        </form>
      <?php else: ?>
        <form method="post" action="<?= e(url('/payments/' . $payment['id'] . '/process')) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit">Send via <?= e($provider) ?> provider</button></form>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>
  </div>

  <div class="col-lg-5">
    <?php if ($q['status'] === 'pending_approval' && can('payments.approve')): ?>
    <div class="card mb-3"><div class="card-body"><h2 class="h6">Approval</h2>
      <?php if ($own): ?><p class="small text-muted mb-0">You made this request, so someone else must approve it.</p>
      <?php elseif ($canDecide): ?>
        <form method="post" action="<?= e(url('/payouts/' . $q['id'] . '/decision')) ?>" class="mb-3"><?= csrf_field() ?><input type="hidden" name="decision" value="approve"><button class="btn btn-primary" type="submit"><i class="bi bi-check-circle"></i> Approve</button></form>
        <form method="post" action="<?= e(url('/payouts/' . $q['id'] . '/decision')) ?>"><?= csrf_field() ?><input type="hidden" name="decision" value="reject">
          <textarea class="form-control mb-2" name="note" rows="2" placeholder="Reason (required)" required aria-label="Reason for rejecting"></textarea><button class="btn btn-outline-danger" type="submit">Reject</button></form>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>
    <?php if ($canCancel): ?>
    <div class="card"><div class="card-body"><h2 class="h6">Cancel this request</h2>
      <form method="post" action="<?= e(url('/payouts/' . $q['id'] . '/cancel')) ?>" data-confirm="Cancel this payout request?"><?= csrf_field() ?>
        <textarea class="form-control mb-2" name="reason" rows="2" placeholder="Reason (required)" required aria-label="Reason for cancelling"></textarea><button class="btn btn-outline-danger" type="submit">Cancel request</button></form>
    </div></div>
    <?php endif; ?>
  </div>
</div>
