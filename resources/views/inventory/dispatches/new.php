<?php /** @var array $hubs @var array $cats @var array $recyclers @var string $token @var string $tab */ ?>
<?= component('inventory-tabs', ['tab' => $tab]) ?>
<div class="card" style="max-width:600px"><div class="card-body">
  <?php if (!$hubs): ?>
    <?= component('alert', ['type' => 'warning', 'message' => 'You are not assigned to an active hub, so you cannot dispatch stock.']) ?>
  <?php elseif (!$recyclers): ?>
    <?= component('alert', ['type' => 'warning', 'message' => 'There is no approved recycler yet. Recyclers must be approved before stock can be dispatched to them.']) ?>
  <?php else: ?>
  <form method="post" action="<?= e(url('/inventory/dispatches')) ?>" data-loading novalidate>
    <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
    <?= component('field', ['name' => 'hub_id', 'label' => 'Dispatch from hub', 'type' => 'select', 'required' => true, 'options' => (count($hubs) === 1 ? [] : ['' => 'Choose…']) + $hubs]) ?>
    <?= component('field', ['name' => 'recycler_id', 'label' => 'Recycler', 'type' => 'select', 'required' => true, 'options' => ['' => 'Choose…'] + $recyclers]) ?>
    <?= component('field', ['name' => 'plastic_category_id', 'label' => 'Material', 'type' => 'select', 'required' => true, 'options' => (count($cats) === 1 ? [] : ['' => 'Choose…']) + $cats]) ?>
    <?= component('field', ['name' => 'quantity_kg', 'label' => 'Weight loaded (kg)', 'required' => true, 'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off']]) ?>
    <div class="row">
      <div class="col-6"><?= component('field', ['name' => 'vehicle', 'label' => 'Vehicle', 'hint' => 'Optional, e.g. number plate']) ?></div>
      <div class="col-6"><?= component('field', ['name' => 'driver', 'label' => 'Driver', 'hint' => 'Optional']) ?></div>
    </div>
    <?= component('field', ['name' => 'notes', 'label' => 'Notes', 'hint' => 'Optional']) ?>
    <button class="btn btn-primary" type="submit">Dispatch stock</button>
    <a class="btn btn-outline-primary" href="<?= e(url('/inventory/dispatches')) ?>">Cancel</a>
  </form>
  <?php endif; ?>
</div></div>
