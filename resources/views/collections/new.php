<?php /** @var array $hubs @var array $cats @var array $prices @var string $token */
$oldPickerId = old('picker_id');
$oldLabel = old('picker_label');
?>
<div class="row g-3">
<div class="col-lg-7">
<div class="card"><div class="card-body">
  <?php if (!$hubs): ?>
    <?= component('alert', ['type' => 'warning', 'message' => 'You are not assigned to an active hub, so you cannot record collections yet. Ask the administrator.']) ?>
  <?php else: ?>
  <form method="post" action="<?= e(url('/collections')) ?>" data-collection-form data-loading novalidate
        data-search-url="<?= e(url('/pickers/search')) ?>" data-prices="<?= e(json_encode($prices)) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <input type="hidden" name="picker_id" value="<?= e($oldPickerId) ?>">
    <input type="hidden" name="picker_label" value="<?= e($oldLabel) ?>">

    <div class="mb-3">
      <label class="form-label" for="picker_search">Waste picker <span class="text-danger" aria-hidden="true">*</span></label>
      <input class="form-control form-control-lg<?= field_error('picker') !== '' ? ' is-invalid' : '' ?>" id="picker_search" type="search" autocomplete="off"
             placeholder="Type a name, picker ID or phone">
      <div class="list-group mt-1" id="picker_results" role="listbox" aria-label="Matching pickers" hidden></div>
      <div class="alert alert-success py-2 mt-2 mb-0" id="picker_chosen" role="status"<?= $oldPickerId === '' ? ' hidden' : '' ?>><?= $oldPickerId !== '' ? 'Selected: ' . e($oldLabel) : '' ?></div>
      <?php if (field_error('picker') !== ''): ?><div class="invalid-feedback d-block"><?= e(field_error('picker')) ?></div><?php endif; ?>
      <noscript><div class="form-text">Without JavaScript, type the picker ID below.</div></noscript>
      <div class="form-text">If search does not work on this phone, type the picker ID instead:</div>
      <input class="form-control mt-1" name="picker_code" type="text" placeholder="PLP-000123" value="<?= e(old('picker_code')) ?>" autocomplete="off" aria-label="Picker ID">
    </div>

    <?= component('field', ['name' => 'hub_id', 'label' => 'Hub', 'type' => 'select', 'required' => true,
        'options' => (count($hubs) === 1 ? [] : ['' => 'Choose…']) + $hubs]) ?>
    <?= component('field', ['name' => 'plastic_category_id', 'label' => 'Material', 'type' => 'select', 'required' => true,
        'options' => (count($cats) === 1 ? [] : ['' => 'Choose…']) + $cats]) ?>
    <div class="row">
      <div class="col-6"><?= component('field', ['name' => 'gross_weight_kg', 'label' => 'Gross weight (kg)', 'required' => true,
          'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'placeholder' => '12.5']]) ?></div>
      <div class="col-6"><?= component('field', ['name' => 'tare_weight_kg', 'label' => 'Tare / sack (kg)',
          'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'placeholder' => '0'], 'hint' => 'Optional']) ?></div>
    </div>
    <div class="alert alert-light border" id="calc" aria-live="polite">Enter the weights to see the net weight and value.</div>
    <?= component('field', ['name' => 'notes', 'label' => 'Notes', 'hint' => 'Optional']) ?>
    <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-check2-circle"></i> Save weighing</button>
    <a class="btn btn-outline-primary btn-lg" href="<?= e(url('/collections')) ?>">Cancel</a>
  </form>
  <?php endif; ?>
</div></div>
</div>
<div class="col-lg-5">
  <div class="card"><div class="card-body">
    <h2 class="h6">Prices in force</h2>
    <?php foreach ($hubs as $hid => $hname): foreach ($cats as $cid => $cname): ?>
      <div class="d-flex justify-content-between small py-1 border-bottom">
        <span><?= e($hname) ?> · <?= e($cname) ?></span>
        <strong><?= $prices[$hid][$cid] !== null ? e(format_ugx($prices[$hid][$cid])) . '/kg' : '<span class="text-danger">not set</span>' ?></strong>
      </div>
    <?php endforeach; endforeach; ?>
    <p class="small text-muted mt-2 mb-0">The price is fixed onto the collection when it is weighed. A later price change never alters it.</p>
  </div></div>
</div>
</div>
