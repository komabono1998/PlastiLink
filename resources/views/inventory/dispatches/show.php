<?php /** @var array $d @var string $tab */ ?>
<div class="no-print"><?= component('inventory-tabs', ['tab' => $tab]) ?></div>
<div class="row g-3">
  <div class="col-lg-7"><div class="card"><div class="card-body">
    <div class="text-center mb-3 d-none d-print-block"><strong>PlastiLink · Delivery note</strong></div>
    <div class="d-flex justify-content-between gap-2"><div><h2 class="h5 mb-0"><?= e($d['reference']) ?></h2><div class="small text-muted">Delivery note</div></div>
      <span class="badge text-bg-secondary align-self-start"><?= e(ucfirst($d['status'])) ?></span></div>
    <dl class="row mt-3 mb-0">
      <dt class="col-sm-4">From hub</dt><dd class="col-sm-8"><?= e($d['hub_name']) ?></dd>
      <dt class="col-sm-4">To recycler</dt><dd class="col-sm-8"><?= e($d['recycler_name']) ?></dd>
      <dt class="col-sm-4">Material</dt><dd class="col-sm-8"><?= e($d['category_name']) ?></dd>
      <dt class="col-sm-4">Weight loaded</dt><dd class="col-sm-8 fs-5 fw-bold"><?= e(format_kg($d['qty_kg'])) ?></dd>
      <dt class="col-sm-4">Vehicle / driver</dt><dd class="col-sm-8"><?= e(trim(($d['vehicle'] ?? '') . ' ' . ($d['driver'] ? '· ' . $d['driver'] : '')) ?: '—') ?></dd>
      <dt class="col-sm-4">Dispatched</dt><dd class="col-sm-8"><?= e(format_datetime($d['dispatched_at'])) ?> by <?= e($d['dispatched_by_name']) ?></dd>
      <?php if ($d['notes']): ?><dt class="col-sm-4">Notes</dt><dd class="col-sm-8"><?= e($d['notes']) ?></dd><?php endif; ?>
      <?php if ($d['status'] === 'delivered'): ?><dt class="col-sm-4">Delivered</dt><dd class="col-sm-8"><?= e(format_datetime($d['delivered_at'])) ?> (recorded by <?= e($d['delivered_by_name'] ?? '—') ?>)<?= $d['delivery_note'] ? '<br>' . e($d['delivery_note']) : '' ?></dd><?php endif; ?>
      <?php if ($d['status'] === 'cancelled'): ?><dt class="col-sm-4">Cancelled</dt><dd class="col-sm-8"><?= e(format_datetime($d['cancelled_at'])) ?> by <?= e($d['cancelled_by_name'] ?? '—') ?>: <?= e($d['cancel_reason']) ?></dd><?php endif; ?>
    </dl>
    <div class="d-none d-print-block mt-5 small">Hub signature: ____________________ &nbsp;&nbsp; Recycler signature: ____________________</div>
    <hr class="no-print"><button class="btn btn-outline-primary no-print" type="button" data-print><i class="bi bi-printer"></i> Print delivery note</button>
  </div></div></div>

  <?php if ($d['status'] === 'dispatched' && can('inventory.manage')): ?>
  <div class="col-lg-5 no-print">
    <div class="card mb-3"><div class="card-body"><h2 class="h6">Mark as delivered</h2>
      <form method="post" action="<?= e(url('/inventory/dispatches/' . $d['id'] . '/delivered')) ?>">
        <?= csrf_field() ?>
        <label class="form-label" for="note">Note (optional)</label><input class="form-control mb-2" id="note" name="note" maxlength="255">
        <button class="btn btn-primary" type="submit"><i class="bi bi-check-circle"></i> Delivered</button>
      </form></div></div>
    <div class="card"><div class="card-body"><h2 class="h6">Cancel dispatch</h2>
      <form method="post" action="<?= e(url('/inventory/dispatches/' . $d['id'] . '/cancel')) ?>" data-confirm="Cancel this dispatch and return the stock to the hub?">
        <?= csrf_field() ?>
        <textarea class="form-control mb-2" name="reason" rows="2" placeholder="Reason (required)" required aria-label="Reason for cancelling"></textarea>
        <button class="btn btn-outline-danger" type="submit">Cancel dispatch</button>
      </form></div></div>
  </div>
  <?php endif; ?>
</div>
