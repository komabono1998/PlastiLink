<?php /** @var array $fromHubs @var array $toHubs @var array $cats @var string $token @var string $tab */ ?>
<?= component('inventory-tabs', ['tab' => $tab]) ?>
<div class="card" style="max-width:600px"><div class="card-body">
  <?php if (!$fromHubs): ?>
    <?= component('alert', ['type' => 'warning', 'message' => 'You are not assigned to an active hub, so you cannot send stock.']) ?>
  <?php else: ?>
  <p class="text-muted small">The stock leaves your hub immediately and is shown as "in transit" until the receiving hub confirms the weight that arrived.</p>
  <form method="post" action="<?= e(url('/inventory/transfers')) ?>" data-loading novalidate>
    <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
    <?= component('field', ['name' => 'from_hub_id', 'label' => 'Send from', 'type' => 'select', 'required' => true, 'options' => (count($fromHubs) === 1 ? [] : ['' => 'Choose…']) + $fromHubs]) ?>
    <?= component('field', ['name' => 'to_hub_id', 'label' => 'Send to', 'type' => 'select', 'required' => true, 'options' => ['' => 'Choose…'] + $toHubs]) ?>
    <?= component('field', ['name' => 'plastic_category_id', 'label' => 'Material', 'type' => 'select', 'required' => true, 'options' => (count($cats) === 1 ? [] : ['' => 'Choose…']) + $cats]) ?>
    <?= component('field', ['name' => 'quantity_kg', 'label' => 'Weight sent (kg)', 'required' => true, 'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off']]) ?>
    <?= component('field', ['name' => 'notes', 'label' => 'Notes', 'hint' => 'Optional, for example the vehicle or driver.']) ?>
    <button class="btn btn-primary" type="submit">Send stock</button>
    <a class="btn btn-outline-primary" href="<?= e(url('/inventory/transfers')) ?>">Cancel</a>
  </form>
  <?php endif; ?>
</div></div>
