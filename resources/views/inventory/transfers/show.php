<?php /** @var array $t @var string $tab @var bool $canReceive @var bool $canCancel */ ?>
<?= component('inventory-tabs', ['tab' => $tab]) ?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card"><div class="card-body">
    <div class="d-flex justify-content-between gap-2"><h2 class="h5 mb-0"><?= e($t['reference']) ?></h2><span class="badge text-bg-secondary"><?= e(ucfirst(str_replace('_', ' ', $t['status']))) ?></span></div>
    <dl class="row mt-3 mb-0">
      <dt class="col-sm-4">From</dt><dd class="col-sm-8"><?= e($t['from_hub']) ?></dd>
      <dt class="col-sm-4">To</dt><dd class="col-sm-8"><?= e($t['to_hub']) ?></dd>
      <dt class="col-sm-4">Material</dt><dd class="col-sm-8"><?= e($t['category_name']) ?></dd>
      <dt class="col-sm-4">Weight sent</dt><dd class="col-sm-8 fw-bold"><?= e(format_kg($t['qty_sent_kg'])) ?></dd>
      <dt class="col-sm-4">Sent</dt><dd class="col-sm-8"><?= e(format_datetime($t['sent_at'])) ?> by <?= e($t['sent_by_name']) ?></dd>
      <?php if ($t['notes']): ?><dt class="col-sm-4">Notes</dt><dd class="col-sm-8"><?= e($t['notes']) ?></dd><?php endif; ?>
      <?php if ($t['status'] === 'received'): ?>
        <dt class="col-sm-4">Received</dt><dd class="col-sm-8"><?= e(format_kg($t['qty_received_kg'])) ?> on <?= e(format_datetime($t['received_at'])) ?> by <?= e($t['received_by_name'] ?? '—') ?></dd>
        <?php $loss = \App\Helpers\Weight::fromDecimal((string) $t['qty_sent_kg']) - \App\Helpers\Weight::fromDecimal((string) $t['qty_received_kg']); if ($loss > 0): ?>
          <dt class="col-sm-4">Difference</dt><dd class="col-sm-8 text-danger"><?= e(format_kg(\App\Helpers\Weight::toDecimal($loss))) ?> lost in transit<?= $t['receive_note'] ? ': ' . e($t['receive_note']) : '' ?></dd><?php endif; ?>
      <?php endif; ?>
      <?php if ($t['status'] === 'cancelled'): ?><dt class="col-sm-4">Cancelled</dt><dd class="col-sm-8"><?= e(format_datetime($t['cancelled_at'])) ?> by <?= e($t['cancelled_by_name'] ?? '—') ?>: <?= e($t['cancel_reason']) ?></dd><?php endif; ?>
    </dl>
  </div></div></div>
  <div class="col-lg-5">
    <?php if ($canReceive): ?>
    <div class="card mb-3"><div class="card-body"><h2 class="h6">Confirm receipt</h2>
      <form method="post" action="<?= e(url('/inventory/transfers/' . $t['id'] . '/receive')) ?>" data-loading>
        <?= csrf_field() ?>
        <?= component('field', ['name' => 'received_kg', 'label' => 'Weight received (kg)', 'required' => true, 'value' => (string) $t['qty_sent_kg'], 'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off']]) ?>
        <?= component('field', ['name' => 'note', 'label' => 'Note', 'hint' => 'Required if less arrived than was sent.']) ?>
        <button class="btn btn-primary" type="submit"><i class="bi bi-box-arrow-in-down"></i> Confirm receipt</button>
      </form></div></div>
    <?php endif; ?>
    <?php if ($canCancel): ?>
    <div class="card"><div class="card-body"><h2 class="h6">Cancel this transfer</h2>
      <form method="post" action="<?= e(url('/inventory/transfers/' . $t['id'] . '/cancel')) ?>" onsubmit="return confirm('Cancel this transfer and return the stock to your hub?');">
        <?= csrf_field() ?>
        <textarea class="form-control mb-2" name="reason" rows="2" placeholder="Reason (required)" required aria-label="Reason for cancelling"></textarea>
        <button class="btn btn-outline-danger" type="submit">Cancel transfer</button>
      </form></div></div>
    <?php endif; ?>
  </div>
</div>
