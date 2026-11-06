<?php /** @var array $items */ ?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card"><div class="card-body">
      <h2 class="h6">Add a neighbourhood</h2>
      <form method="post" action="<?= e(url('/neighbourhoods')) ?>" novalidate>
        <?= csrf_field() ?>
        <?= component('field', ['name' => 'name', 'label' => 'Name', 'required' => true]) ?>
        <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg"></i> Add</button>
      </form>
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card">
      <div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th>Name</th><th>Location (map centre)</th><th class="text-end">Hubs</th><th class="text-end">Pickers</th><th>Status</th><th class="position-relative"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($items as $n): ?>
          <tr>
            <td>
              <form method="post" action="<?= e(url('/neighbourhoods/' . $n['id'])) ?>" class="d-flex gap-2 m-0">
                <?= csrf_field() ?>
                <label class="visually-hidden" for="n<?= e($n['id']) ?>">Name</label>
                <input class="form-control form-control-sm" id="n<?= e($n['id']) ?>" name="name" value="<?= e($n['name']) ?>" maxlength="100">
                <button class="btn btn-sm btn-outline-primary" type="submit">Rename</button>
              </form>
            </td>
            <td>
              <form method="post" action="<?= e(url('/neighbourhoods/' . $n['id'])) ?>" class="d-flex flex-wrap gap-1 m-0">
                <?= csrf_field() ?><input type="hidden" name="action" value="location">
                <label class="visually-hidden" for="lat<?= e($n['id']) ?>">Latitude</label>
                <input class="form-control form-control-sm" style="width:6.5rem" id="lat<?= e($n['id']) ?>" name="latitude" inputmode="decimal" placeholder="Latitude" value="<?= e($n['latitude'] ?? '') ?>">
                <label class="visually-hidden" for="lng<?= e($n['id']) ?>">Longitude</label>
                <input class="form-control form-control-sm" style="width:6.5rem" id="lng<?= e($n['id']) ?>" name="longitude" inputmode="decimal" placeholder="Longitude" value="<?= e($n['longitude'] ?? '') ?>">
                <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                <button class="btn btn-sm btn-outline-secondary" type="button" data-geo-pick data-lat="#lat<?= e($n['id']) ?>" data-lng="#lng<?= e($n['id']) ?>" data-map="#geo-map" data-src="<?= e(asset('vendor/leaflet/leaflet.js')) ?>" data-css="<?= e(asset('vendor/leaflet/leaflet.css')) ?>"><i class="bi bi-geo-alt"></i> Pick</button>
              </form>
            </td>
            <td class="text-end"><?= e($n['hubs']) ?></td>
            <td class="text-end"><?= e($n['pickers']) ?></td>
            <td><?= component('status-badge', ['status' => $n['is_active'] ? 'active' : 'inactive']) ?></td>
            <td class="text-end">
              <form method="post" action="<?= e(url('/neighbourhoods/' . $n['id'])) ?>" class="m-0">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle">
                <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $n['is_active'] ? 'Deactivate' : 'Activate' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?= component('geo-picker', ['id' => 'geo-map']) ?>
  </div>
</div>
