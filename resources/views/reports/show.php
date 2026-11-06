<?php
/** @var string $cls @var string $key @var array $f @var array $result @var array $meta @var int $total @var string $qs @var bool $canExport */
use App\Helpers\ReportFormat;
$filters = $cls::filters();
$exportBase = '/reports/' . $key . '/export/';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <p class="text-muted mb-0"><?= e($cls::description()) ?></p>
  <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/reports')) ?>"><i class="bi bi-arrow-left"></i> All reports</a>
</div>

<form class="card card-body mb-3" method="get" action="<?= e(url('/reports/' . $key)) ?>">
  <div class="row g-2 align-items-end">
    <?php if (in_array('range', $filters, true)): ?>
      <div class="col-6 col-md-2"><label class="form-label" for="from">From</label><input class="form-control" id="from" name="from" type="date" value="<?= e($f['from']) ?>"></div>
      <div class="col-6 col-md-2"><label class="form-label" for="to">To</label><input class="form-control" id="to" name="to" type="date" value="<?= e($f['to']) ?>"></div>
    <?php endif; ?>
    <?php if (in_array('hub', $filters, true)): ?>
      <div class="col-6 col-md-2"><label class="form-label" for="hub_id">Hub</label>
        <select class="form-select" id="hub_id" name="hub_id"><option value=""><?= $f['hubs'] === null ? 'All hubs' : 'All my hubs' ?></option>
          <?php foreach ($f['hub_options'] as $id => $n): ?><option value="<?= e($id) ?>"<?= (int) $f['hub_id'] === (int) $id ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <?php if (in_array('neighbourhood', $filters, true)): ?>
      <div class="col-6 col-md-2"><label class="form-label" for="neighbourhood_id">Neighbourhood</label>
        <select class="form-select" id="neighbourhood_id" name="neighbourhood_id"><option value="">All</option>
          <?php foreach ($f['nb_options'] as $id => $n): ?><option value="<?= e($id) ?>"<?= (int) $f['neighbourhood_id'] === (int) $id ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <?php if (in_array('group', $filters, true)): ?>
      <div class="col-6 col-md-2"><label class="form-label" for="group">Group by</label>
        <select class="form-select" id="group" name="group"><?php foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $f['group'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <?php if (in_array('inactive_days', $filters, true)): ?>
      <div class="col-6 col-md-2"><label class="form-label" for="inactive_days">Quiet after (days)</label><input class="form-control" id="inactive_days" name="inactive_days" type="number" min="1" max="365" value="<?= e($f['inactive_days']) ?>"></div>
    <?php endif; ?>
    <div class="col-12 col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-funnel"></i> Show report</button></div>
  </div>
</form>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div class="small text-muted"><strong><?= e($meta['period']) ?></strong><?= $meta['filters'] !== '' ? ' · ' . e($meta['filters']) : '' ?> · generated <?= e($meta['generated']) ?></div>
  <?php if ($canExport): ?>
  <div class="d-flex flex-wrap gap-2">
    <?php foreach (['csv' => ['filetype-csv', 'CSV'], 'xlsx' => ['file-earmark-excel', 'Excel'], 'pdf' => ['file-earmark-pdf', 'PDF']] as $fmt => [$icon, $label]): ?>
      <a class="btn btn-outline-primary btn-sm" href="<?= e(url($exportBase . $fmt) . ($qs !== '' ? '?' . $qs : '')) ?>"><i class="bi bi-<?= e($icon) ?>"></i> <?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="row g-3 mb-3">
  <?php foreach ($result['summary'] as $s): ?>
    <div class="col-6 col-lg-4 col-xl-3"><?= component('stat-card', ['icon' => 'bar-chart', 'value' => ReportFormat::display($s['value'], $s['type']), 'label' => $s['label']]) ?></div>
  <?php endforeach; ?>
</div>

<?php if (!empty($result['chart']) && !empty($result['chart']['labels'])): ?>
<div class="card mb-3"><div class="card-body">
  <button class="btn btn-outline-primary btn-sm" type="button" data-chart-toggle data-src="<?= e(asset('vendor/chartjs/chart.umd.js')) ?>"><i class="bi bi-bar-chart-line"></i> Show chart</button>
  <div id="chart-panel" hidden data-chart="<?= e(json_encode($result['chart'], JSON_UNESCAPED_UNICODE)) ?>" class="mt-3">
    <canvas role="img" aria-label="<?= e($result['chart']['title']) ?>" height="120"></canvas>
    <p class="small text-muted mb-0">The same figures are in the tables below.</p>
  </div>
</div></div>
<?php endif; ?>

<?php foreach ($result['tables'] as $t): ?>
  <div class="card mb-3">
    <div class="card-body pb-0"><h2 class="h6 mb-0"><?= e($t['title']) ?></h2></div>
    <?php if (!$t['rows']): ?>
      <?= component('empty-state', ['icon' => 'table', 'title' => 'No data for this selection', 'text' => 'Try a wider date range or different filters.']) ?>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead><tr><?php foreach ($t['columns'] as $c): ?><th<?= ReportFormat::isNumeric($c['type']) ? ' class="text-end"' : '' ?>><?= e($c['label']) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($t['rows'] as $row): ?>
        <tr><?php foreach ($t['columns'] as $c): ?><td class="<?= ReportFormat::isNumeric($c['type']) ? 'text-end text-nowrap' : '' ?>"><?= e(ReportFormat::display($row[$c['key']] ?? null, $c['type'])) ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
      <?php if (!empty($t['totals'])): ?>
      <tfoot><tr class="fw-bold table-light"><?php foreach ($t['columns'] as $i => $c): ?><td class="<?= ReportFormat::isNumeric($c['type']) ? 'text-end text-nowrap' : '' ?>"><?= $i === 0 ? 'Total' : (array_key_exists($c['key'], $t['totals']) ? e(ReportFormat::display($t['totals'][$c['key']], $c['type'])) : '') ?></td><?php endforeach; ?></tr></tfoot>
      <?php endif; ?>
    </table></div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php foreach ($result['notes'] ?? [] as $n): ?><p class="small text-muted mb-1"><i class="bi bi-info-circle"></i> <?= e($n) ?></p><?php endforeach; ?>
<?php if ($total > 0): ?><p class="small text-muted"><?= e(number_format($total)) ?> data row<?= $total === 1 ? '' : 's' ?> in this report.</p><?php endif; ?>
