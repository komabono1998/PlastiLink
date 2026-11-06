<?php /** @var array $r @var ?string $temp */
$back = '/recyclers/' . $r['id'];
$act = static function (string $decision, string $label, string $cls, string $confirm = '') use ($r, $back): string {
    return '<form method="post" action="' . e(url('/recyclers/' . $r['id'] . '/decision')) . '" class="m-0"'
        . ($confirm !== '' ? ' onsubmit="return confirm(\'' . e($confirm) . '\');"' : '') . '>'
        . csrf_field() . '<input type="hidden" name="decision" value="' . e($decision) . '"><input type="hidden" name="back" value="' . e($back) . '">'
        . '<button class="btn ' . $cls . '" type="submit">' . $label . '</button></form>';
};
?>
<?php if ($temp): ?><?= component('temp-password', ['temp' => $temp, 'who' => $r['business_name']]) ?><?php endif; ?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card"><div class="card-body">
      <div class="d-flex justify-content-between align-items-start gap-2">
        <div><h2 class="h5 mb-0"><?= e($r['business_name']) ?></h2><div class="text-muted small">Contact: <?= e($r['contact_person']) ?></div></div>
        <?= component('status-badge', ['status' => $r['status']]) ?>
      </div>
      <dl class="row mt-3 mb-0 small">
        <dt class="col-sm-4">Registration no.</dt><dd class="col-sm-8"><?= e($r['registration_number'] ?? '—') ?></dd>
        <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= e($r['email']) ?></dd>
        <dt class="col-sm-4">Phone</dt><dd class="col-sm-8"><?= e(display_phone($r['phone_e164'])) ?></dd>
        <dt class="col-sm-4">Address</dt><dd class="col-sm-8"><?= e($r['address']) ?></dd>
        <dt class="col-sm-4">Materials</dt><dd class="col-sm-8"><?= e($r['materials'] ?? '—') ?></dd>
        <dt class="col-sm-4">Registered</dt><dd class="col-sm-8"><?= e(format_datetime($r['created_at'])) ?></dd>
        <?php if ($r['decided_at']): ?><dt class="col-sm-4">Last decision</dt><dd class="col-sm-8"><?= e(ucfirst($r['status'])) ?> by <?= e($r['decided_by_name'] ?? 'unknown') ?>, <?= e(format_datetime($r['decided_at'])) ?><?= $r['decision_reason'] ? '<br>Reason: ' . e($r['decision_reason']) : '' ?></dd><?php endif; ?>
      </dl>
    </div></div>
  </div>
  <?php if (can('recyclers.approve')): ?>
  <div class="col-lg-5"><div class="card"><div class="card-body">
    <h2 class="h6">Decision</h2>
    <div class="d-flex flex-wrap gap-2">
      <?php if (in_array($r['status'], ['pending', 'rejected'], true)): ?><?= $act('approve', '<i class="bi bi-check-circle"></i> Approve', 'btn-primary') ?><?php endif; ?>
      <?php if ($r['status'] === 'approved'): ?><?= $act('suspend', '<i class="bi bi-slash-circle"></i> Suspend', 'btn-outline-danger', 'Suspend this recycler? They will be signed out.') ?><?php endif; ?>
      <?php if ($r['status'] === 'suspended'): ?><?= $act('reactivate', '<i class="bi bi-arrow-counterclockwise"></i> Reactivate', 'btn-primary') ?><?php endif; ?>
    </div>
    <?php if ($r['status'] === 'pending'): ?>
      <hr>
      <form method="post" action="<?= e(url('/recyclers/' . $r['id'] . '/decision')) ?>">
        <?= csrf_field() ?><input type="hidden" name="decision" value="reject"><input type="hidden" name="back" value="<?= e($back) ?>">
        <label class="form-label" for="reason">Reject this registration</label>
        <textarea class="form-control mb-2" id="reason" name="reason" rows="2" placeholder="Reason (required)" required></textarea>
        <button class="btn btn-outline-danger" type="submit">Reject</button>
      </form>
    <?php endif; ?>
  </div></div></div>
  <?php endif; ?>
</div>
