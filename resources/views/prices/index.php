<?php /** @var array $current @var array $history @var array $pg @var array $cats @var array $hubs */ ?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-body pb-0"><h2 class="h6">Prices in force now</h2></div>
      <div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th>Material</th><th>Applies to</th><th class="text-end">Price per kg</th><th>Since</th></tr></thead>
        <tbody>
        <?php foreach ($current as $row): ?>
          <tr><td><?= e($row['category']) ?></td><td><?= e($row['scope']) ?></td>
            <td class="text-end"><?= $row['price'] !== null ? '<strong>' . e(format_ugx($row['price'])) . '</strong>' : '<span class="text-danger">Not set</span>' ?></td>
            <td class="small text-nowrap"><?= e(format_datetime($row['since'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>

    <div class="card"><div class="card-body pb-0"><h2 class="h6">Price history</h2><p class="small text-muted mb-0">Prices are never edited. Each change adds a new row.</p></div>
      <?php if (!$history): ?><?= component('empty-state', ['icon' => 'clock-history', 'title' => 'No prices set yet']) ?>
      <?php else: ?>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>When</th><th>Material</th><th>Applies to</th><th class="text-end">Price/kg</th><th>Set by</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
          <tr><td class="small text-nowrap"><?= e(format_datetime($h['effective_from'])) ?></td><td><?= e($h['category_name']) ?></td>
            <td><?= e($h['hub_name'] ?? 'All hubs') ?></td><td class="text-end"><?= e(number_format((int) $h['price_per_kg_ugx'])) ?></td>
            <td><?= e($h['set_by'] ?? '—') ?></td><td class="small"><?= e($h['note'] ?? '') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?= component('pagination', ['p' => $pg]) ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if (can('prices.manage')): ?>
  <div class="col-lg-5">
    <div class="card"><div class="card-body">
      <h2 class="h6">Set a new price</h2>
      <form method="post" action="<?= e(url('/prices')) ?>" data-loading novalidate>
        <?= csrf_field() ?>
        <?= component('field', ['name' => 'plastic_category_id', 'label' => 'Material', 'type' => 'select', 'required' => true, 'options' => (count($cats) === 1 ? [] : ['' => 'Choose…']) + $cats]) ?>
        <?= component('field', ['name' => 'hub_id', 'label' => 'Applies to', 'type' => 'select', 'options' => ['' => 'All hubs (national price)'] + $hubs, 'hint' => 'Choose a hub only to override the national price there.']) ?>
        <?= component('field', ['name' => 'price_per_kg_ugx', 'label' => 'Price per kg (UGX)', 'required' => true, 'attrs' => ['inputmode' => 'numeric', 'autocomplete' => 'off']]) ?>
        <?= component('field', ['name' => 'note', 'label' => 'Note', 'hint' => 'Optional, for example the reason for the change.']) ?>
        <button class="btn btn-primary" type="submit">Save price</button>
      </form>
    </div></div>
  </div>
  <?php endif; ?>
</div>
