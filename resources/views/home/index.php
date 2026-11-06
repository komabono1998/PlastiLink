<?php /** @var array|null $targets */ ?>
<section class="hero py-5">
  <div class="container py-4">
    <div class="row">
      <div class="col-lg-8">
        <span class="badge text-bg-warning mb-3">Gulu City, Northern Uganda</span>
        <h1 class="display-5 fw-bold"><?= e(config('app.tagline')) ?></h1>
        <p class="lead mt-3">PlastiLink connects waste pickers, collection hubs and recycling companies, so plastic bottles are sold, not dumped, and every kilogram earns transparent income paid by mobile money.</p>
        <p class="small mb-0 opacity-75"><i class="bi bi-tools"></i> Platform foundation (Phase 1). Sign-in and dashboards arrive in the next phases.</p>
      </div>
    </div>
  </div>
</section>

<section class="container py-5" id="how-it-works">
  <h2 class="h3 mb-4">How it works</h2>
  <div class="row g-3">
    <?php
    $steps = [
        ['bag-check',  'Collect',    'Waste pickers gather and sort PET bottles in their neighbourhood.'],
        ['speedometer2', 'Weigh & record', 'Hub operators weigh the plastic and record the transaction with a unique reference.'],
        ['cash-coin',  'Get paid',   'Pickers receive mobile money and a digital receipt for every sale.'],
        ['recycle',    'Recycle',    'Recycling companies buy verified supply at transparent prices.'],
    ];
    foreach ($steps as [$icon, $name, $text]): ?>
      <div class="col-sm-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
          <span class="stat-icon mb-3"><i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i></span>
          <h3 class="h6"><?= e($name) ?></h3>
          <p class="small text-muted mb-0"><?= e($text) ?></p>
        </div></div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="container" id="targets">
  <h2 class="h3 mb-1">Programme targets</h2>
  <p class="text-muted">12-month goals set in the project plan. Live results will replace these once collections begin.</p>
  <?php if ($targets): ?>
    <div class="row g-3">
      <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'people',        'value' => (string) $targets['pickers'],   'label' => 'Registered waste pickers', 'hint' => 'Pilot: ' . $targets['pilot']]) ?></div>
      <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'shop',          'value' => (string) $targets['hubs'],      'label' => 'Collection hubs']) ?></div>
      <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'building',      'value' => (string) $targets['recyclers'], 'label' => 'Recycling companies', 'hint' => 'at least']) ?></div>
      <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'box-seam',      'value' => number_format((int) $targets['kg']) . ' kg', 'label' => 'Plastic collected and sold']) ?></div>
    </div>
  <?php else: ?>
    <?= component('alert', ['type' => 'warning', 'message' => 'Programme targets are temporarily unavailable.']) ?>
  <?php endif; ?>
</section>
