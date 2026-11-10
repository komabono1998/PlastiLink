<?php /** @var array $u @var array $hubs @var array $events @var ?string $temp @var bool $self */
$isProfileRole = in_array($u['role_slug'], ['waste_picker', 'recycler'], true);
?>
<?php if ($temp): ?><?= component('temp-password', ['temp' => $temp, 'who' => $u['full_name']]) ?><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card"><div class="card-body">
      <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
        <div>
          <h2 class="h5 mb-1"><?= e($u['full_name']) ?></h2>
          <div class="text-muted"><?= e($u['role_name']) ?></div>
        </div>
        <?= component('status-badge', ['status' => $u['status']]) ?>
      </div>
      <dl class="row mt-3 mb-0 small">
        <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= e($u['email'] ?? '—') ?></dd>
        <dt class="col-sm-4">Phone</dt><dd class="col-sm-8"><?= e(display_phone($u['phone_e164'])) ?></dd>
        <dt class="col-sm-4">Created</dt><dd class="col-sm-8"><?= e(format_datetime($u['created_at'])) ?></dd>
        <dt class="col-sm-4">Last sign-in</dt><dd class="col-sm-8"><?= e(format_datetime($u['last_login_at'])) ?></dd>
        <dt class="col-sm-4">Password changed</dt><dd class="col-sm-8"><?= e(format_datetime($u['password_changed_at'])) ?><?= (int) $u['must_change_password'] ? ' · <span class="text-warning-emphasis">must change at next sign-in</span>' : '' ?></dd>
        <?php if ($hubs): ?><dt class="col-sm-4">Hubs</dt><dd class="col-sm-8"><?= e(implode(', ', $hubs)) ?></dd><?php endif; ?>
      </dl>

      <?php if (can('users.manage')): ?>
      <hr>
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary" href="<?= e(url('/users/' . $u['id'] . '/edit')) ?>"><i class="bi bi-pencil"></i> Edit</a>
        <?php if (!$self): ?>
          <form method="post" action="<?= e(url('/users/' . $u['id'] . '/reset-password')) ?>" class="m-0" data-confirm="Create a new temporary password for this person?">
            <?= csrf_field() ?><button class="btn btn-outline-primary" type="submit"><i class="bi bi-key"></i> Reset password</button>
          </form>
          <?php if (!$isProfileRole): ?>
            <form method="post" action="<?= e(url('/users/' . $u['id'] . '/status')) ?>" class="m-0">
              <?= csrf_field() ?>
              <?php if ($u['status'] === 'active'): ?>
                <input type="hidden" name="status" value="suspended">
                <button class="btn btn-outline-danger" type="submit" data-confirm="Suspend this account? They will be signed out on their next request."><i class="bi bi-slash-circle"></i> Suspend</button>
              <?php else: ?>
                <input type="hidden" name="status" value="active">
                <button class="btn btn-primary" type="submit"><i class="bi bi-check-circle"></i> <?= $u['status'] === 'pending' ? 'Approve' : 'Reactivate' ?></button>
              <?php endif; ?>
            </form>
          <?php else: ?>
            <span class="small text-muted align-self-center">Approve or suspend this account from its
              <?php if ($u['role_slug'] === 'waste_picker'): ?><a href="<?= e(url('/pickers')) ?>?q=<?= e(rawurlencode($u['full_name'])) ?>">picker page</a><?php else: ?><a href="<?= e(url('/recyclers')) ?>?q=<?= e(rawurlencode($u['full_name'])) ?>">recycler page</a><?php endif; ?>.</span>
          <?php endif; ?>
        <?php else: ?>
          <span class="small text-muted align-self-center">This is your own account. Use "Change password" in the menu.</span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="col-lg-5">
    <div class="card h-100"><div class="card-body">
      <h2 class="h6">Recent activity</h2>
      <?php if (!$events): ?><?= component('empty-state', ['icon' => 'journal-text', 'title' => 'No activity yet']) ?>
      <?php else: ?>
        <ul class="list-unstyled small mb-0">
          <?php foreach ($events as $ev): ?>
            <li class="py-1 border-bottom"><span class="badge badge-soft"><?= e($ev['action']) ?></span>
              <span class="text-muted"><?= e(format_datetime($ev['created_at'])) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div></div>
  </div>
</div>
