<?php
/** @var string $type success|danger|warning|info */
/** @var string $message */
$type = $type ?? 'info';
$icons = ['success' => 'check-circle', 'danger' => 'x-octagon', 'warning' => 'exclamation-triangle', 'info' => 'info-circle'];
?>
<div class="alert alert-<?= e($type) ?> d-flex align-items-start gap-2" role="<?= $type === 'danger' ? 'alert' : 'status' ?>">
  <i class="bi bi-<?= e($icons[$type] ?? 'info-circle') ?>" aria-hidden="true"></i>
  <div><?= e($message) ?></div>
</div>
