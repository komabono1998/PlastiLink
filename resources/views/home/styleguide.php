<?php $colours = [
    'Primary forest green' => '#086B52', 'Secondary green' => '#16A085', 'Soft mint' => '#DFF3EA',
    'Background' => '#F4F7F5', 'Charcoal' => '#202C28', 'Accent amber' => '#F0B44D', 'White' => '#FFFFFF',
]; ?>
<p class="text-muted">Shared components used by every module. Available only outside production.</p>

<section id="colours" class="mb-5">
  <h2 class="h5">Colours</h2>
  <div class="row g-3">
    <?php foreach ($colours as $name => $hex): ?>
      <div class="col-6 col-md-3 col-xl-2"><div class="swatch" style="background:<?= e($hex) ?>"></div><div class="small mt-1 fw-semibold"><?= e($name) ?></div><code><?= e($hex) ?></code></div>
    <?php endforeach; ?>
  </div>
</section>

<section id="buttons" class="mb-5">
  <h2 class="h5">Buttons</h2>
  <div class="d-flex flex-wrap gap-2">
    <button class="btn btn-primary"><i class="bi bi-plus-lg"></i> Primary</button>
    <button class="btn btn-outline-primary">Outline</button>
    <button class="btn btn-accent">Accent</button>
    <button class="btn btn-outline-danger">Danger</button>
    <button class="btn btn-primary" disabled><span class="spinner-pl"></span> Loading</button>
  </div>
</section>

<section id="cards" class="mb-5">
  <h2 class="h5">Stat cards</h2>
  <div class="row g-3">
    <div class="col-md-4"><?= component('stat-card', ['icon' => 'box-seam', 'value' => format_kg(12.5), 'label' => 'Format example: weight']) ?></div>
    <div class="col-md-4"><?= component('stat-card', ['icon' => 'cash-coin', 'value' => format_ugx(12500), 'label' => 'Format example: currency']) ?></div>
    <div class="col-md-4"><?= component('stat-card', ['icon' => 'clock', 'value' => format_datetime('2026-01-01 09:00:00'), 'label' => 'UTC stored, shown in Africa/Kampala']) ?></div>
  </div>
</section>

<section id="forms" class="mb-5">
  <h2 class="h5">Forms (with CSRF + loading state)</h2>
  <form class="card card-body" style="max-width:520px" data-loading onsubmit="return false">
    <?= csrf_field() ?>
    <label class="form-label" for="sg-name">Full name</label>
    <input class="form-control mb-3" id="sg-name" type="text" placeholder="e.g. Akello Grace">
    <label class="form-label" for="sg-phone">Phone number</label>
    <input class="form-control is-invalid" id="sg-phone" type="tel" inputmode="numeric" value="07">
    <div class="invalid-feedback">Enter a phone number such as 0771234567.</div>
    <button class="btn btn-primary mt-3" type="submit">Save</button>
  </form>
</section>

<section id="states" class="mb-5">
  <h2 class="h5">Feedback and empty states</h2>
  <?= component('alert', ['type' => 'success', 'message' => 'Success: collection saved.']) ?>
  <?= component('alert', ['type' => 'danger', 'message' => 'Error: the weight must be greater than zero.']) ?>
  <?= component('alert', ['type' => 'warning', 'message' => 'Warning: stock is running low.']) ?>
  <div class="card"><?= component('empty-state', ['icon' => 'inbox', 'title' => 'No collections yet', 'text' => 'New collections will appear here.']) ?></div>
</section>

<section id="tables" class="mb-5">
  <h2 class="h5">Tables</h2>
  <div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Reference</th><th class="text-end">Net weight</th><th class="text-end">Value</th><th>Status</th></tr></thead>
    <tbody><tr><td colspan="4"><?= component('empty-state', ['icon' => 'table', 'title' => 'Sample table (no data)', 'text' => 'Real rows appear once transactions exist.']) ?></td></tr></tbody>
  </table></div></div>
</section>
