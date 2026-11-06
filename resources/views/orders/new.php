<?php /** @var array $stock @var array $hubs @var array $cats @var string $token @var array $pre */ ?>
<div class="card" style="max-width:600px"><div class="card-body">
  <?php if (!$stock): ?>
    <?= component('alert', ['type' => 'info', 'message' => 'There is no plastic available to buy right now. Check the marketplace again soon.']) ?>
  <?php else: ?>
  <p class="text-muted small">Your offer goes to the hub. They can accept it, counter it, or turn it down. An offer stays open for <?= e(\App\Services\OrderService::OFFER_HOURS) ?> hours.</p>
  <form method="post" action="<?= e(url('/orders')) ?>" data-loading novalidate>
    <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
    <?= component('field', ['name' => 'hub_id', 'label' => 'Hub', 'type' => 'select', 'required' => true, 'options' => ['' => 'Choose…'] + $hubs, 'value' => old('hub_id', $pre['hub'] ? (string) $pre['hub'] : '')]) ?>
    <?= component('field', ['name' => 'plastic_category_id', 'label' => 'Material', 'type' => 'select', 'required' => true, 'options' => (count($cats) === 1 ? [] : ['' => 'Choose…']) + $cats, 'value' => old('plastic_category_id', $pre['cat'] ? (string) $pre['cat'] : '')]) ?>
    <?= component('field', ['name' => 'quantity_kg', 'label' => 'Quantity (kg)', 'required' => true, 'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off']]) ?>
    <?= component('field', ['name' => 'price_per_kg_ugx', 'label' => 'Your price per kg (UGX)', 'required' => true, 'attrs' => ['inputmode' => 'numeric', 'autocomplete' => 'off']]) ?>
    <?= component('field', ['name' => 'delivery_date', 'label' => 'Preferred delivery or pick-up date', 'type' => 'date', 'hint' => 'Optional']) ?>
    <?= component('field', ['name' => 'note', 'label' => 'Note to the hub', 'hint' => 'Optional']) ?>
    <button class="btn btn-primary" type="submit">Send offer</button>
    <a class="btn btn-outline-primary" href="<?= e(url('/marketplace')) ?>">Cancel</a>
  </form>
  <?php endif; ?>
</div></div>
