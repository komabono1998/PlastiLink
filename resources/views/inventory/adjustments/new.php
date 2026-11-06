<?php /** @var array $hubs @var array $cats @var array $kinds @var string $token @var string $tab */ ?>
<?= component('inventory-tabs', ['tab' => $tab]) ?>
<div class="card" style="max-width:600px"><div class="card-body">
  <?php if (!$hubs): ?>
    <?= component('alert', ['type' => 'warning', 'message' => 'You are not assigned to an active hub, so you cannot request adjustments.']) ?>
  <?php else: ?>
  <p class="text-muted small">Nothing changes in stock until a <strong>different</strong> person approves this request.</p>
  <form method="post" action="<?= e(url('/inventory/adjustments')) ?>" data-loading novalidate>
    <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
    <?= component('field', ['name' => 'kind', 'label' => 'What is this?', 'type' => 'select', 'required' => true, 'options' => ['' => 'Choose…'] + $kinds,
        'hint' => 'Count: enter the weight you physically counted. Manual entry: stock that is not from a collection. Write-off: rejected, contaminated or lost material.']) ?>
    <?= component('field', ['name' => 'hub_id', 'label' => 'Hub', 'type' => 'select', 'required' => true, 'options' => (count($hubs) === 1 ? [] : ['' => 'Choose…']) + $hubs]) ?>
    <?= component('field', ['name' => 'plastic_category_id', 'label' => 'Material', 'type' => 'select', 'required' => true, 'options' => (count($cats) === 1 ? [] : ['' => 'Choose…']) + $cats]) ?>
    <?= component('field', ['name' => 'quantity_kg', 'label' => 'Weight (kg)', 'required' => true, 'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off'], 'hint' => 'For a count, the total counted. Otherwise the amount to add or remove.']) ?>
    <?= component('field', ['name' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true, 'hint' => 'At least 5 characters. Be specific: this is audited.']) ?>
    <button class="btn btn-primary" type="submit">Submit for approval</button>
    <a class="btn btn-outline-primary" href="<?= e(url('/inventory/adjustments')) ?>">Cancel</a>
  </form>
  <?php endif; ?>
</div></div>
