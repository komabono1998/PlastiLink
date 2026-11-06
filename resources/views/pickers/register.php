<?php /** @var array $neighbourhoods @var array $hubs @var string $consentText @var bool $hubRequired */ ?>
<div class="card" style="max-width:640px"><div class="card-body">
  <p class="text-muted">Register a waste picker in person. The account is approved straight away; a temporary password is shown once after saving.</p>
  <form method="post" action="<?= e(url('/pickers')) ?>" data-loading novalidate>
    <?= csrf_field() ?>
    <?= component('field', ['name' => 'full_name', 'label' => 'Full name', 'required' => true]) ?>
    <?= component('field', ['name' => 'phone', 'label' => 'Phone number', 'type' => 'tel', 'required' => true, 'attrs' => ['inputmode' => 'tel', 'autocomplete' => 'off'], 'hint' => 'For example 0771234567. Used to sign in and to receive payments.']) ?>
    <?= component('field', ['name' => 'neighbourhood_id', 'label' => 'Neighbourhood', 'type' => 'select', 'required' => true, 'options' => ['' => 'Choose…'] + $neighbourhoods]) ?>
    <?= component('field', ['name' => 'preferred_hub_id', 'label' => 'Preferred collection hub', 'type' => 'select', 'required' => $hubRequired,
        'options' => ['' => $hubRequired ? 'Choose…' : 'No preference'] + $hubs]) ?>
    <?= component('field', ['name' => 'national_id', 'label' => 'National ID number (optional)', 'hint' => 'Not required. Only collect it if the picker agrees.', 'attrs' => ['autocomplete' => 'off']]) ?>
    <div class="alert alert-light border small"><?= e($consentText) ?></div>
    <?= component('field', ['name' => 'consent', 'type' => 'checkbox', 'label' => 'The picker has read and agreed to the above (data-use agreement)', 'required' => true]) ?>
    <button class="btn btn-primary" type="submit">Register picker</button>
    <a class="btn btn-outline-primary" href="<?= e(url('/pickers')) ?>">Cancel</a>
  </form>
</div></div>
