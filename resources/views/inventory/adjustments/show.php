<?php /** @var array $a @var string $tab @var bool $own @var bool $canDecide */
$neg = str_starts_with((string) $a['change_kg'], '-');
?>
<?= component('inventory-tabs', ['tab' => $tab]) ?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card"><div class="card-body">
    <div class="d-flex justify-content-between gap-2"><div><h2 class="h5 mb-0"><?= e($a['reference']) ?></h2><div class="small text-muted"><?= e(\App\Services\AdjustmentService::KINDS[$a['kind']] ?? $a['kind']) ?></div></div>
      <?= component('status-badge', ['status' => $a['status']]) ?></div>
    <dl class="row mt-3 mb-0">
      <dt class="col-sm-4">Hub</dt><dd class="col-sm-8"><?= e($a['hub_name']) ?></dd>
      <dt class="col-sm-4">Material</dt><dd class="col-sm-8"><?= e($a['category_name']) ?></dd>
      <?php if ($a['kind'] === 'count'): ?>
        <dt class="col-sm-4">System balance</dt><dd class="col-sm-8"><?= e(format_kg($a['system_kg'])) ?> (when counted)</dd>
        <dt class="col-sm-4">Counted</dt><dd class="col-sm-8"><?= e(format_kg($a['counted_kg'])) ?></dd>
      <?php endif; ?>
      <dt class="col-sm-4">Change to stock</dt><dd class="col-sm-8 fs-5 fw-bold <?= $neg ? 'text-danger' : 'text-success' ?>"><?= e(($neg ? '' : '+') . format_kg($a['change_kg'])) ?></dd>
      <dt class="col-sm-4">Reason</dt><dd class="col-sm-8"><?= e($a['reason']) ?></dd>
      <dt class="col-sm-4">Requested</dt><dd class="col-sm-8"><?= e(format_datetime($a['requested_at'])) ?> by <?= e($a['requested_by_name']) ?></dd>
      <?php if ($a['decided_at']): ?><dt class="col-sm-4"><?= e(ucfirst($a['status'])) ?></dt><dd class="col-sm-8"><?= e($a['decided_by_name'] ?? '—') ?>, <?= e(format_datetime($a['decided_at'])) ?><?= $a['decision_note'] ? '<br>' . e($a['decision_note']) : '' ?></dd><?php endif; ?>
    </dl>
  </div></div></div>
  <?php if ($canDecide): ?>
  <div class="col-lg-5"><div class="card"><div class="card-body"><h2 class="h6">Decision</h2>
    <?php if ($own): ?>
      <p class="small text-muted mb-0">You requested this adjustment, so someone else must approve or reject it.</p>
    <?php else: ?>
      <form method="post" action="<?= e(url('/inventory/adjustments/' . $a['id'] . '/decision')) ?>" class="mb-3">
        <?= csrf_field() ?><input type="hidden" name="decision" value="approve">
        <button class="btn btn-primary" type="submit"><i class="bi bi-check-circle"></i> Approve and post to stock</button>
      </form>
      <form method="post" action="<?= e(url('/inventory/adjustments/' . $a['id'] . '/decision')) ?>">
        <?= csrf_field() ?><input type="hidden" name="decision" value="reject">
        <label class="form-label" for="note">Reject this request</label>
        <textarea class="form-control mb-2" id="note" name="note" rows="2" placeholder="Reason (required)" required></textarea>
        <button class="btn btn-outline-danger" type="submit">Reject</button>
      </form>
    <?php endif; ?>
  </div></div></div>
  <?php endif; ?>
</div>
