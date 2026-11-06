<?php /** @var array|null $hub @var array $neighbourhoods */
$editing = $hub !== null;
$v = static fn(string $k) => $editing ? (string) ($hub[$k] ?? '') : null;
?>
<div class="card" style="max-width:640px"><div class="card-body">
  <form method="post" action="<?= e(url($editing ? '/hubs/' . $hub['id'] : '/hubs')) ?>" data-loading novalidate>
    <?= csrf_field() ?>
    <?= component('field', ['name' => 'name', 'label' => 'Hub name', 'required' => true, 'value' => $v('name')]) ?>
    <?= component('field', ['name' => 'neighbourhood_id', 'label' => 'Neighbourhood', 'type' => 'select', 'required' => true,
        'options' => ['' => 'Choose…'] + $neighbourhoods, 'value' => $v('neighbourhood_id')]) ?>
    <?= component('field', ['name' => 'address_note', 'label' => 'Address note', 'value' => $v('address_note'), 'hint' => 'Landmark or directions, for example "Opposite the market".']) ?>
    <div class="row">
      <div class="col-6"><?= component('field', ['name' => 'latitude', 'label' => 'Latitude', 'value' => $v('latitude'), 'attrs' => ['inputmode' => 'decimal'], 'hint' => 'Optional, for maps']) ?></div>
      <div class="col-6"><?= component('field', ['name' => 'longitude', 'label' => 'Longitude', 'value' => $v('longitude'), 'attrs' => ['inputmode' => 'decimal']]) ?></div>
    </div>
    <p class="mb-3"><button class="btn btn-sm btn-outline-secondary" type="button" data-geo-pick data-lat="#f-latitude" data-lng="#f-longitude" data-map="#geo-map" data-src="<?= e(asset('vendor/leaflet/leaflet.js')) ?>" data-css="<?= e(asset('vendor/leaflet/leaflet.css')) ?>"><i class="bi bi-geo-alt"></i> Pick on map</button></p>
    <?= component('geo-picker', ['id' => 'geo-map']) ?>
    <?= component('field', ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true,
        'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'value' => $editing ? $hub['status'] : 'active']) ?>
    <button class="btn btn-primary" type="submit"><?= $editing ? 'Save changes' : 'Register hub' ?></button>
    <a class="btn btn-outline-primary" href="<?= e(url($editing ? '/hubs/' . $hub['id'] : '/hubs')) ?>">Cancel</a>
  </form>
</div></div>
