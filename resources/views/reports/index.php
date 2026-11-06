<?php /** @var array<string,class-string> $reports */
$icons = ['collections' => 'box-seam', 'pickers' => 'person-badge', 'hubs' => 'shop', 'inventory' => 'boxes', 'recyclers' => 'building', 'financial' => 'cash-coin', 'payments' => 'credit-card', 'impact' => 'tree'];
?>
<p class="text-muted">Every report is built from the recorded data and can be downloaded as CSV, Excel or PDF. Downloads are logged.</p>
<?php if (!$reports): ?>
  <?= component('empty-state', ['icon' => 'file-earmark-bar-graph', 'title' => 'No reports available to your role']) ?>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($reports as $key => $cls): ?>
    <div class="col-md-6 col-xl-4">
      <a class="card h-100 text-decoration-none text-reset" href="<?= e(url('/reports/' . $key)) ?>">
        <div class="card-body d-flex gap-3">
          <span class="stat-icon flex-shrink-0"><i class="bi bi-<?= e($icons[$key] ?? 'file-earmark-bar-graph') ?>" aria-hidden="true"></i></span>
          <div><h2 class="h6 mb-1"><?= e($cls::title()) ?></h2><p class="small text-muted mb-0"><?= e($cls::description()) ?></p></div>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
