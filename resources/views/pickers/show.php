<?php /** @var array $p @var ?string $temp @var bool $canSeeNationalId */
$back = '/pickers/' . $p['id'];
$st = $p['account_status'];
$act = static function (string $decision, string $label, string $cls, string $confirm = '') use ($p, $back): string {
    return '<form method="post" action="' . e(url('/pickers/' . $p['id'] . '/decision')) . '" class="m-0"'
        . ($confirm !== '' ? ' onsubmit="return confirm(\'' . e($confirm) . '\');"' : '') . '>'
        . csrf_field() . '<input type="hidden" name="decision" value="' . e($decision) . '"><input type="hidden" name="back" value="' . e($back) . '">'
        . '<button class="btn ' . $cls . '" type="submit">' . $label . '</button></form>';
};
?>
<?php if ($temp): ?><?= component('temp-password', ['temp' => $temp, 'who' => $p['full_name']]) ?><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card"><div class="card-body">
      <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
        <div><h2 class="h5 mb-0"><?= e($p['full_name']) ?></h2><div class="text-muted"><?= e($p['picker_code']) ?></div></div>
        <div class="d-flex gap-1"><?= component('status-badge', ['status' => $st]) ?><?= component('status-badge', ['status' => $p['verification_status']]) ?></div>
      </div>
      <dl class="row mt-3 mb-0 small">
        <dt class="col-sm-4">Phone</dt><dd class="col-sm-8"><?= e(display_phone($p['phone_e164'])) ?></dd>
        <dt class="col-sm-4">Neighbourhood</dt><dd class="col-sm-8"><?= e($p['neighbourhood']) ?></dd>
        <dt class="col-sm-4">Preferred hub</dt><dd class="col-sm-8"><?= e($p['hub_name'] ?? 'No preference') ?></dd>
        <?php if ($canSeeNationalId): ?><dt class="col-sm-4">National ID</dt><dd class="col-sm-8"><?= e($p['national_id'] ?? 'Not provided') ?></dd><?php endif; ?>
        <dt class="col-sm-4">Registered</dt><dd class="col-sm-8"><?= e(format_datetime($p['account_created'])) ?> · <?= e($p['registered_by_name'] ? 'by ' . $p['registered_by_name'] : 'self-registered') ?></dd>
        <dt class="col-sm-4">Consent</dt><dd class="col-sm-8">Version <?= e($p['consent_version']) ?>, <?= e(format_datetime($p['consented_at'])) ?></dd>
        <?php if ($p['review_note']): ?><dt class="col-sm-4">Review note</dt><dd class="col-sm-8"><?= e($p['review_note']) ?></dd><?php endif; ?>
      </dl>
    </div></div>
  </div>

  <?php if (can('pickers.manage')): ?>
  <div class="col-lg-5">
    <div class="card"><div class="card-body">
      <h2 class="h6">Actions</h2>
      <div class="d-flex flex-wrap gap-2">
        <?php if ($st === 'pending'): ?><?= $act('approve', '<i class="bi bi-check-circle"></i> Approve', 'btn-primary') ?><?php endif; ?>
        <?php if ($st === 'active'): ?><?= $act('suspend', '<i class="bi bi-slash-circle"></i> Suspend', 'btn-outline-danger', 'Suspend this picker? They will be signed out.') ?><?php endif; ?>
        <?php if ($st === 'suspended'): ?><?= $act('reactivate', '<i class="bi bi-arrow-counterclockwise"></i> Reactivate', 'btn-primary') ?><?php endif; ?>
        <?= $act($p['verification_status'] === 'verified' ? 'unverify' : 'verify', $p['verification_status'] === 'verified' ? 'Remove verification' : '<i class="bi bi-patch-check"></i> Mark verified', 'btn-outline-primary') ?>
        <?= $act('reset_password', '<i class="bi bi-key"></i> New temporary password', 'btn-outline-primary', 'Create a new temporary password for this picker?') ?>
      </div>
      <?php if ($st === 'pending'): ?>
        <hr>
        <form method="post" action="<?= e(url('/pickers/' . $p['id'] . '/decision')) ?>">
          <?= csrf_field() ?><input type="hidden" name="decision" value="reject"><input type="hidden" name="back" value="<?= e($back) ?>">
          <label class="form-label" for="reason">Reject this registration</label>
          <textarea class="form-control mb-2" id="reason" name="reason" rows="2" placeholder="Reason (required)" required></textarea>
          <button class="btn btn-outline-danger" type="submit">Reject</button>
        </form>
      <?php endif; ?>
    </div></div>
  </div>
  <?php endif; ?>
</div>
