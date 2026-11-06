<?php
/** @var array $targets @var array $values @var array $labels @var array $trend @var int $months @var bool $canEdit @var bool $canExport */
use App\Helpers\ReportFormat;
$chart = ['type' => 'bar', 'title' => 'Net kg collected per month', 'labels' => array_column($trend, 'label'),
    'datasets' => [['label' => 'Net kg', 'data' => array_map(static fn($r) => round((float) $r['kg'], 2), $trend)]]];
$tot = ['kg' => array_sum(array_column($trend, 'kg')), 'val' => array_sum(array_column($trend, 'val')), 'paid' => array_sum(array_column($trend, 'paid')), 'sold' => array_sum(array_column($trend, 'sold')), 'joined' => array_sum(array_column($trend, 'joined'))];
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <p class="text-muted mb-0">Progress against the programme targets, from recorded data only. Nothing on this page is estimated.</p>
  <?php if ($canExport): ?><a class="btn btn-outline-primary btn-sm" href="<?= e(url('/reports/programme')) ?>"><i class="bi bi-download"></i> Report and downloads</a><?php endif; ?>
</div>

<div class="row g-3 mb-3">
  <?php foreach ($targets as $t): ?>
    <div class="col-md-6 col-xl-4">
      <div class="card h-100"><div class="card-body">
        <div class="small text-muted"><?= e($t['label']) ?></div>
        <div class="d-flex justify-content-between align-items-baseline">
          <span class="fs-4 fw-bold"><?= e(ReportFormat::display($t['actual'], 'num')) ?></span>
          <span class="text-muted small">of <?= $t['target'] > 0 ? e(number_format($t['target'])) : 'no target set' ?></span>
        </div>
        <?php $p = $t['progress']; ?>
        <div class="progress mt-2" role="progressbar" aria-label="<?= e($t['label']) ?>" aria-valuenow="<?= e((int) round((float) $p)) ?>" aria-valuemin="0" aria-valuemax="100" style="height:.6rem">
          <div class="progress-bar" style="width:<?= e(number_format((float) ($p ?? 0), 1, '.', '')) ?>%"></div>
        </div>
        <div class="small text-muted mt-1"><?= $p === null ? 'Set a target to track progress' : e(number_format($p, 1)) . '%' ?></div>
      </div></div>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($canEdit): ?>
<div class="card mb-3"><div class="card-body">
  <h2 class="h6">Programme targets</h2>
  <form method="post" action="<?= e(url('/monitoring/targets')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="row g-2">
      <?php foreach ($labels as $key => $label): $name = str_replace('.', '_', $key); ?>
        <div class="col-md-6 col-xl-4"><label class="form-label small" for="t-<?= e($name) ?>"><?= e($label) ?></label>
          <input class="form-control" id="t-<?= e($name) ?>" name="<?= e($name) ?>" inputmode="numeric" value="<?= e((string) $values[$key]) ?>"></div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-primary mt-3" type="submit">Save targets</button>
    <span class="small text-muted ms-2">Every change is recorded in the audit trail with the old and new value.</span>
  </form>
</div></div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
  <h2 class="h6 mb-0">Month by month</h2>
  <div class="btn-group btn-group-sm" role="group" aria-label="Months shown">
    <?php foreach ([6, 12, 24] as $n): ?><a class="btn btn-outline-primary<?= $months === $n ? ' active' : '' ?>" href="<?= e(url('/monitoring/programme?months=' . $n)) ?>"><?= e($n) ?> months</a><?php endforeach; ?>
  </div>
</div>
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'box-seam', 'value' => ReportFormat::display($tot['kg'], 'kg'), 'label' => 'Plastic collected']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'truck', 'value' => ReportFormat::display($tot['sold'], 'kg'), 'label' => 'Sold and delivered']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'cash-coin', 'value' => ReportFormat::display($tot['paid'], 'ugx'), 'label' => 'Paid out to pickers']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'person-plus', 'value' => ReportFormat::display($tot['joined'], 'int'), 'label' => 'New pickers']) ?></div>
</div>

<div class="card mb-3"><div class="card-body">
  <button class="btn btn-outline-primary btn-sm" type="button" data-chart-toggle data-src="<?= e(asset('vendor/chartjs/chart.umd.js')) ?>"><i class="bi bi-bar-chart-line"></i> Show chart</button>
  <div id="chart-panel" hidden data-chart="<?= e(json_encode($chart, JSON_UNESCAPED_UNICODE)) ?>" class="mt-3">
    <canvas role="img" aria-label="<?= e($chart['title']) ?>" height="120"></canvas>
    <p class="small text-muted mb-0">The same figures are in the table below.</p>
  </div>
</div></div>

<div class="card mb-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Month</th><th class="text-end">Collections</th><th class="text-end">Net weight</th><th class="text-end">Sold and delivered</th><th class="text-end">Pickers earning</th><th class="text-end">New pickers</th><th class="text-end">Hubs collecting</th><th class="text-end">Earned</th><th class="text-end">Paid out</th></tr></thead>
  <tbody>
  <?php foreach (array_reverse($trend) as $r): ?>
    <tr><td><?= e($r['label']) ?></td><td class="text-end"><?= e(ReportFormat::display($r['collections'], 'int')) ?></td><td class="text-end text-nowrap"><?= e(ReportFormat::display($r['kg'], 'kg')) ?></td>
      <td class="text-end text-nowrap"><?= e(ReportFormat::display($r['sold'], 'kg')) ?></td><td class="text-end"><?= e(ReportFormat::display($r['pickers'], 'int')) ?></td><td class="text-end"><?= e(ReportFormat::display($r['joined'], 'int')) ?></td>
      <td class="text-end"><?= e(ReportFormat::display($r['hubs'], 'int')) ?></td><td class="text-end text-nowrap"><?= e(ReportFormat::display($r['val'], 'ugx')) ?></td><td class="text-end text-nowrap"><?= e(ReportFormat::display($r['paid'], 'ugx')) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<p class="small text-muted"><i class="bi bi-info-circle"></i> Months are East Africa Time. "Pickers earning" counts pickers with at least one approved collection that month. Bottle and CO₂ estimates appear in the report only if the programme has configured conversion factors and a source.</p>
