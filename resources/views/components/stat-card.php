<?php /** @var string $label */ /** @var string $value */ /** @var string $icon */ /** @var string|null $hint */ ?>
<div class="card stat-card h-100">
  <div class="card-body d-flex align-items-center gap-3">
    <span class="stat-icon"><i class="bi bi-<?= e($icon ?? 'graph-up') ?>" aria-hidden="true"></i></span>
    <div>
      <div class="stat-value"><?= e($value) ?></div>
      <div class="stat-label"><?= e($label) ?></div>
      <?php if (!empty($hint)): ?><div class="small text-muted"><?= e($hint) ?></div><?php endif; ?>
    </div>
  </div>
</div>
