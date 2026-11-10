<?php /** @var array $r @var array $d */
$voided = !empty($r['voided_at']);
?>
<div class="d-flex justify-content-between align-items-center gap-2 mb-3 no-print">
  <a class="btn btn-outline-primary" href="<?= e(url('/collections/' . $r['collection_id'])) ?>"><i class="bi bi-arrow-left"></i> Back</a>
  <button class="btn btn-primary" type="button" data-print><i class="bi bi-printer"></i> Print</button>
</div>

<div class="card receipt mx-auto" style="max-width:520px"><div class="card-body p-4">
  <?php if ($voided): ?>
    <div class="alert alert-danger text-center fw-bold mb-3">VOID<div class="small fw-normal">Voided <?= e(format_datetime($r['voided_at'])) ?>: <?= e($r['void_reason']) ?></div></div>
  <?php endif; ?>
  <div class="text-center mb-3">
    <div class="brand"><span class="brand-plasti">Plasti</span><span class="brand-link">Link</span></div>
    <div class="text-muted small"><?= e(config('app.tagline')) ?></div>
    <h2 class="h5 mt-2 mb-0">Collection receipt</h2>
    <div class="font-monospace"><?= e($r['receipt_number']) ?></div>
  </div>
  <dl class="row small mb-3">
    <dt class="col-5">Collection ref.</dt><dd class="col-7"><?= e($d['reference'] ?? '') ?></dd>
    <dt class="col-5">Date</dt><dd class="col-7"><?= e(format_datetime($d['recorded_at'] ?? null)) ?></dd>
    <dt class="col-5">Waste picker</dt><dd class="col-7"><?= e($d['picker_name'] ?? '') ?> (<?= e($d['picker_code'] ?? '') ?>)</dd>
    <dt class="col-5">Hub</dt><dd class="col-7"><?= e($d['hub_name'] ?? '') ?>, <?= e($d['neighbourhood'] ?? '') ?></dd>
    <dt class="col-5">Material</dt><dd class="col-7"><?= e($d['material'] ?? '') ?></dd>
  </dl>
  <table class="table table-sm mb-3">
    <tbody>
      <tr><td>Gross weight</td><td class="text-end"><?= e(format_kg($d['gross_kg'] ?? 0)) ?></td></tr>
      <tr><td>Tare weight</td><td class="text-end"><?= e(format_kg($d['tare_kg'] ?? 0)) ?></td></tr>
      <tr><td class="fw-bold">Net weight</td><td class="text-end fw-bold"><?= e(format_kg($d['net_kg'] ?? 0)) ?></td></tr>
      <tr><td>Price per kg</td><td class="text-end"><?= e(format_ugx($d['price_per_kg'] ?? 0)) ?></td></tr>
      <tr class="table-light"><td class="fw-bold fs-5">Total value</td><td class="text-end fw-bold fs-5"><?= e(format_ugx($d['total_ugx'] ?? 0)) ?></td></tr>
    </tbody>
  </table>
  <div class="small text-muted">Weighed by <?= e($d['recorded_by'] ?? '') ?> · Approved by <?= e($d['approved_by'] ?? '') ?><br>Issued <?= e(format_datetime($r['issued_at'])) ?></div>
  <p class="small text-muted mt-3 mb-0">This receipt records the value of plastic delivered. Payment is confirmed separately.</p>
</div></div>
