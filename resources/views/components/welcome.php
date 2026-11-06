<?php
/**
 * @var array $user     signed-in user
 * @var string[] $coming module names scheduled for later phases
 * @var string $blurb
 */
?>
<div class="card mb-4"><div class="card-body d-flex flex-wrap align-items-center gap-3">
  <span class="stat-icon"><i class="bi bi-person-check" aria-hidden="true"></i></span>
  <div class="flex-grow-1">
    <h2 class="h5 mb-0">Welcome, <?= e($user['full_name']) ?></h2>
    <div class="text-muted"><?= e($user['role_name']) ?><?php if (!empty($blurb)): ?> · <?= e($blurb) ?><?php endif; ?></div>
  </div>
  <?php if (!empty($user['last_login_at'])): ?>
    <div class="small text-muted">Last sign-in: <?= e(format_datetime($user['last_login_at'])) ?></div>
  <?php endif; ?>
</div></div>

<?php if (!empty($coming)): ?>
<div class="card"><div class="card-body">
  <h2 class="h6">Coming in later phases</h2>
  <p class="small text-muted mb-2">Your account is ready. These tools are not available yet:</p>
  <ul class="mb-0">
    <?php foreach ($coming as $c): ?><li><?= e($c) ?></li><?php endforeach; ?>
  </ul>
</div></div>
<?php endif; ?>
