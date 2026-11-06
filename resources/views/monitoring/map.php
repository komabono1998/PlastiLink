<?php
/** @var array $f @var array $geo @var array $points @var array $meta @var string $qs @var bool $canExport */
use App\Helpers\ReportFormat;
$m = (array) config('app.integrations.maps', []);
$hasPoints = $points['hubs'] || $points['recyclers'] || $points['hotspots'];
$loc = static fn($v) => $v === null ? '—' : number_format((float) $v, 4);
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <p class="text-muted mb-0">Where plastic is being recovered. Recovery is counted per neighbourhood; people and individual collections are never placed on the map.</p>
  <?php if ($canExport): ?><a class="btn btn-outline-primary btn-sm" href="<?= e(url('/reports/geographic' . ($qs !== '' ? '?' . $qs : ''))) ?>"><i class="bi bi-download"></i> Report and downloads</a><?php endif; ?>
</div>

<form class="card card-body mb-3" method="get" action="<?= e(url('/monitoring/map')) ?>">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-3"><label class="form-label" for="from">From</label><input class="form-control" id="from" name="from" type="date" value="<?= e($f['from']) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label" for="to">To</label><input class="form-control" id="to" name="to" type="date" value="<?= e($f['to']) ?>"></div>
    <div class="col-12 col-md-4"><label class="form-label" for="hub_id">Hub</label>
      <select class="form-select" id="hub_id" name="hub_id"><option value=""><?= $f['hubs'] === null ? 'All hubs' : 'All my hubs' ?></option>
        <?php foreach ($f['hub_options'] as $id => $n): ?><option value="<?= e($id) ?>"<?= (int) $f['hub_id'] === (int) $id ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
    <div class="col-12 col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-funnel"></i> Show</button></div>
  </div>
</form>
<div class="small text-muted mb-3"><strong><?= e($meta['period']) ?></strong> · East Africa Time · approved collections only</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'box-seam', 'value' => ReportFormat::display($geo['totals']['kg'], 'kg'), 'label' => 'Plastic recovered']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'receipt', 'value' => ReportFormat::display((int) $geo['totals']['collections'], 'int'), 'label' => 'Approved collections']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'shop', 'value' => ReportFormat::display(count($geo['hubs']), 'int'), 'label' => 'Hubs shown']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'geo', 'value' => ReportFormat::display((int) $geo['totals']['unlocated'], 'int'), 'label' => 'Places without coordinates']) ?></div>
</div>

<div class="card mb-3"><div class="card-body">
  <div class="d-flex flex-wrap align-items-center gap-3">
    <button class="btn btn-primary btn-sm" type="button" data-map-toggle data-src="<?= e(asset('vendor/leaflet/leaflet.js')) ?>" data-css="<?= e(asset('vendor/leaflet/leaflet.css')) ?>"><i class="bi bi-map"></i> <span>Show map</span></button>
    <div class="d-flex flex-wrap gap-3 small" role="group" aria-label="Map layers">
      <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" data-layer="hotspots" checked> Recovery by neighbourhood</label>
      <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" data-layer="hubs" checked> Hubs</label>
      <?php if ($f['hubs'] === null): ?><label class="form-check-label"><input class="form-check-input me-1" type="checkbox" data-layer="recyclers" checked> Recyclers</label><?php endif; ?>
    </div>
  </div>
  <?php if (!$hasPoints): ?><p class="small text-muted mt-2 mb-0"><i class="bi bi-info-circle"></i> Nothing here has coordinates yet. Add a latitude and longitude to hubs, neighbourhoods and recyclers (administrators can do this on their pages) and they will appear on the map. The tables below work without them.</p><?php endif; ?>
  <div id="monitor-map" hidden class="mt-3" data-points="<?= e(json_encode($points, JSON_UNESCAPED_UNICODE)) ?>" data-tile="<?= e((string) ($m['tile_url'] ?? '')) ?>"
       data-attribution="<?= e((string) ($m['attribution'] ?? '')) ?>" data-center="<?= e(json_encode($m['center'] ?? [2.7746, 32.2990])) ?>">
    <div class="monitor-map-canvas" style="height:min(70vh,520px)" role="application" aria-label="Map of hubs, recyclers and plastic recovery by neighbourhood. The same information is in the tables below."></div>
    <p class="small text-muted mt-2 mb-0">Circles show kilograms recovered per neighbourhood; the larger the circle, the more plastic. Background map: OpenStreetMap contributors. The same figures are in the tables below.</p>
  </div>
</div></div>

<div class="card mb-3">
  <div class="card-body pb-0"><h2 class="h6 mb-0">Recovery by neighbourhood (hotspots)</h2></div>
  <?php if (!$geo['hotspots']): ?><?= component('empty-state', ['icon' => 'geo-alt', 'title' => 'No neighbourhoods to show']) ?><?php else: ?>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Neighbourhood</th><th class="text-end">Collections</th><th class="text-end">Net weight</th><th class="text-end">Share</th><th class="text-end">Pickers</th><th>Location</th></tr></thead>
    <tbody>
    <?php foreach ($geo['hotspots'] as $i => $n): ?>
      <tr>
        <td><?= $i === 0 && (float) $n['kg'] > 0 ? '<i class="bi bi-fire text-danger" title="Top neighbourhood"></i> ' : '' ?><?= e($n['name']) ?></td>
        <td class="text-end"><?= e(ReportFormat::display((int) $n['collections'], 'int')) ?></td>
        <td class="text-end text-nowrap"><?= e(ReportFormat::display($n['kg'], 'kg')) ?></td>
        <td class="text-end"><?= e(ReportFormat::display($n['share'], 'pct')) ?></td>
        <td class="text-end"><?= e(ReportFormat::display((int) $n['pickers'], 'int')) ?></td>
        <td class="small text-nowrap"><?= $n['latitude'] === null ? '<span class="text-muted">location not set</span>' : e($loc($n['latitude']) . ', ' . $loc($n['longitude'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="card mb-3">
  <div class="card-body pb-0"><h2 class="h6 mb-0">Hubs</h2></div>
  <?php if (!$geo['hubs']): ?><?= component('empty-state', ['icon' => 'shop', 'title' => 'No hubs to show']) ?><?php else: ?>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Hub</th><th>Neighbourhood</th><th>Status</th><th class="text-end">Collections</th><th class="text-end">Net weight</th><th class="text-end">Stock on hand</th><th>Location</th></tr></thead>
    <tbody>
    <?php foreach ($geo['hubs'] as $h): ?>
      <tr>
        <td><a href="<?= e(url('/hubs/' . $h['id'])) ?>"><?= e($h['name']) ?></a></td><td><?= e($h['neighbourhood']) ?></td><td><?= component('status-badge', ['status' => $h['status']]) ?></td>
        <td class="text-end"><?= e(ReportFormat::display((int) $h['collections'], 'int')) ?></td><td class="text-end text-nowrap"><?= e(ReportFormat::display($h['kg'], 'kg')) ?></td>
        <td class="text-end text-nowrap"><?= e(ReportFormat::display($h['on_hand'], 'kg')) ?></td>
        <td class="small text-nowrap"><?= $h['latitude'] === null ? '<span class="text-muted">location not set</span>' : e($loc($h['latitude']) . ', ' . $loc($h['longitude'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php if ($f['hubs'] === null): ?>
<div class="card mb-3">
  <div class="card-body pb-0"><h2 class="h6 mb-0">Recycling companies</h2></div>
  <?php if (!$geo['recyclers']): ?><?= component('empty-state', ['icon' => 'building', 'title' => 'No approved recyclers yet']) ?><?php else: ?>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Recycler</th><th>Address</th><th>Location</th></tr></thead>
    <tbody>
    <?php foreach ($geo['recyclers'] as $r): ?>
      <tr><td><a href="<?= e(url('/recyclers/' . $r['id'])) ?>"><?= e($r['name']) ?></a></td><td class="small"><?= e($r['address']) ?></td>
        <td class="small text-nowrap"><?= $r['latitude'] === null ? '<span class="text-muted">location not set</span>' : e($loc($r['latitude']) . ', ' . $loc($r['longitude'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>
