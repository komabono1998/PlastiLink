<?php /** @var string $id */
$m = (array) config('app.integrations.maps', []);
?>
<div id="<?= e($id) ?>" class="geo-picker mt-3" hidden data-tile="<?= e((string) ($m['tile_url'] ?? '')) ?>" data-attribution="<?= e((string) ($m['attribution'] ?? '')) ?>" data-center="<?= e(json_encode($m['center'] ?? [2.7746, 32.2990])) ?>">
  <p class="small text-muted mb-1"><i class="bi bi-hand-index"></i> Click the map to fill in the latitude and longitude, then save. The street background comes from OpenStreetMap.</p>
  <div class="geo-picker-map" style="height:320px" role="application" aria-label="Map for choosing a location"></div>
</div>
