<?php /** @var array $p @var array $attempts @var string $provider @var bool $canProcess @var bool $canFail @var bool $canReverse @var bool $canConfirm @var bool $ownRecord */ ?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-body">
      <div class="d-flex justify-content-between gap-2 flex-wrap"><div><h2 class="h5 mb-0"><?= e($p['reference']) ?></h2><div class="small text-muted"><?= $p['direction'] === 'out' ? 'Payout to a picker' : 'Payment received from a recycler' ?></div></div><?= component('status-badge', ['status' => $p['status']]) ?></div>
      <dl class="row mt-3 mb-0">
        <?php if ($p['direction'] === 'out'): ?>
          <dt class="col-sm-4">Picker</dt><dd class="col-sm-8"><?= e($p['picker_name']) ?> (<?= e($p['picker_code']) ?>)</dd>
          <?php if ($p['request_id']): ?><dt class="col-sm-4">Request</dt><dd class="col-sm-8"><a href="<?= e(url('/payouts/' . $p['request_id'])) ?>">Open the payout request</a></dd><?php endif; ?>
          <?php if ($p['destination_phone']): ?><dt class="col-sm-4">Phone</dt><dd class="col-sm-8"><?= e(display_phone($p['destination_phone'])) ?></dd><?php endif; ?>
        <?php else: ?>
          <dt class="col-sm-4">Recycler</dt><dd class="col-sm-8"><?= e($p['business_name']) ?></dd>
          <dt class="col-sm-4">Invoice</dt><dd class="col-sm-8"><a href="<?= e(url('/invoices/' . $p['invoice_id'])) ?>"><?= e($p['invoice_number']) ?></a></dd>
          <?php if ($p['received_on']): ?><dt class="col-sm-4">Received on</dt><dd class="col-sm-8"><?= e(date('d M Y', strtotime($p['received_on']))) ?></dd><?php endif; ?>
        <?php endif; ?>
        <dt class="col-sm-4">Amount</dt><dd class="col-sm-8 fs-5 fw-bold"><?= e(format_ugx($p['amount_ugx'])) ?></dd>
        <dt class="col-sm-4">Method</dt><dd class="col-sm-8"><?= e(ucfirst(str_replace('_', ' ', $p['method']))) ?> · provider: <?= e($p['provider']) ?></dd>
        <dt class="col-sm-4">Transaction ref.</dt><dd class="col-sm-8"><?= e($p['provider_reference'] ?? '—') ?></dd>
        <dt class="col-sm-4">Created</dt><dd class="col-sm-8"><?= e(format_datetime($p['created_at'])) ?> by <?= e($p['created_by_name']) ?></dd>
        <?php if ($p['processed_at']): ?><dt class="col-sm-4">Processed</dt><dd class="col-sm-8"><?= e(format_datetime($p['processed_at'])) ?><?= $p['processed_by_name'] ? ' by ' . e($p['processed_by_name']) : '' ?></dd><?php endif; ?>
        <?php if ($p['confirmed_at']): ?><dt class="col-sm-4">Confirmed</dt><dd class="col-sm-8"><?= e(format_datetime($p['confirmed_at'])) ?> by <?= e($p['confirmed_by_name'] ?? '—') ?></dd><?php endif; ?>
        <?php if ($p['failure_reason']): ?><dt class="col-sm-4">Failure</dt><dd class="col-sm-8 text-danger"><?= e($p['failure_reason']) ?></dd><?php endif; ?>
        <?php if ($p['reversed_at']): ?><dt class="col-sm-4">Reversed</dt><dd class="col-sm-8"><?= e(format_datetime($p['reversed_at'])) ?> by <?= e($p['reversed_by_name'] ?? '—') ?><br>Reason: <?= e($p['reversal_reason']) ?></dd><?php endif; ?>
        <?php if ($p['reconciled_at']): ?><dt class="col-sm-4">Reconciled</dt><dd class="col-sm-8"><?= e(format_datetime($p['reconciled_at'])) ?> against <?= e($p['statement_reference']) ?></dd><?php endif; ?>
      </dl>
    </div></div>
    <?php if ($attempts): ?>
    <div class="card"><div class="card-body pb-0"><h2 class="h6">Attempts</h2></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>#</th><th>When</th><th>Result</th><th>Reference</th><th>Message</th></tr></thead>
        <tbody><?php foreach ($attempts as $a): ?>
          <tr><td><?= e($a['attempt_no']) ?></td><td class="small text-nowrap"><?= e(format_datetime($a['created_at'], 'd M, H:i')) ?></td><td><?= component('status-badge', ['status' => $a['status']]) ?></td><td class="small"><?= e($a['provider_reference'] ?? '') ?></td><td class="small"><?= e($a['message'] ?? '') ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-5">
    <?php if ($canProcess): ?>
    <div class="card mb-3"><div class="card-body"><h2 class="h6"><?= $p['status'] === 'failed' ? 'Try again' : 'Pay now' ?></h2>
      <form method="post" action="<?= e(url('/payments/' . $p['id'] . '/process')) ?>" data-loading novalidate><?= csrf_field() ?>
        <?php if ($provider === 'manual'): ?>
          <?php if ($p['method'] === 'mobile_money'): ?><?= component('field', ['name' => 'provider_reference', 'label' => 'Mobile money transaction ID', 'required' => true, 'attrs' => ['autocomplete' => 'off']]) ?>
          <?php else: ?><?= component('field', ['name' => 'receipt_note', 'label' => 'Cash receipt note', 'required' => true]) ?><?php endif; ?>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit">Record payment</button>
      </form></div></div>
    <?php endif; ?>
    <?php if ($canConfirm): ?>
    <div class="card mb-3"><div class="card-body"><h2 class="h6">Confirm this receipt</h2>
      <form method="post" action="<?= e(url('/payments/' . $p['id'] . '/receipt-decision')) ?>" class="mb-3"><?= csrf_field() ?><input type="hidden" name="decision" value="confirm"><button class="btn btn-primary" type="submit"><i class="bi bi-check-circle"></i> Confirm money received</button></form>
      <form method="post" action="<?= e(url('/payments/' . $p['id'] . '/receipt-decision')) ?>"><?= csrf_field() ?><input type="hidden" name="decision" value="reject">
        <textarea class="form-control mb-2" name="note" rows="2" placeholder="Reason (required)" required aria-label="Reason"></textarea><button class="btn btn-outline-danger" type="submit">Reject</button></form>
    </div></div>
    <?php elseif ($p['direction'] === 'in' && $p['status'] === 'pending' && $ownRecord): ?>
      <?= component('alert', ['type' => 'info', 'message' => 'You recorded this payment, so someone else must confirm it before it counts.']) ?>
    <?php endif; ?>
    <?php if ($canFail): ?>
    <div class="card mb-3"><div class="card-body"><h2 class="h6">Mark as failed</h2>
      <form method="post" action="<?= e(url('/payments/' . $p['id'] . '/fail')) ?>"><?= csrf_field() ?>
        <textarea class="form-control mb-2" name="reason" rows="2" placeholder="Why (required)" required aria-label="Why it failed"></textarea><button class="btn btn-outline-danger" type="submit">Mark failed</button></form></div></div>
    <?php endif; ?>
    <?php if ($canReverse): ?>
    <div class="card"><div class="card-body"><h2 class="h6">Reverse this payment</h2>
      <p class="small text-muted">For genuine errors only. An opposite entry is posted and the reason is audited.</p>
      <form method="post" action="<?= e(url('/payments/' . $p['id'] . '/reverse')) ?>" onsubmit="return confirm('Reverse this payment?');"><?= csrf_field() ?>
        <textarea class="form-control mb-2" name="reason" rows="2" placeholder="Reason (required, at least 5 characters)" required aria-label="Reason for reversal"></textarea><button class="btn btn-outline-danger" type="submit">Reverse</button></form></div></div>
    <?php endif; ?>
  </div>
</div>
