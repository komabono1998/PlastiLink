<?php /** @var array $neighbourhoods @var array $hubs @var string $consentText */ ?>
<section class="container py-4">
  <div class="mx-auto" style="max-width:520px">
    <div class="card"><div class="card-body p-4">
      <h1 class="h4 mb-1">Register as a waste picker</h1>
      <p class="text-muted">Fill in your details. A hub manager will approve your account, then you can sign in.</p>
      <form method="post" action="<?= e(url('/register')) ?>" data-loading novalidate>
        <?= csrf_field() ?>
        <?= component('field', ['name' => 'full_name', 'label' => 'Full name', 'required' => true, 'attrs' => ['autocomplete' => 'name']]) ?>
        <?= component('field', ['name' => 'phone', 'label' => 'Phone number', 'type' => 'tel', 'required' => true, 'attrs' => ['inputmode' => 'tel', 'autocomplete' => 'tel'], 'hint' => 'For example 0771234567. You will sign in with this.']) ?>
        <?= component('field', ['name' => 'neighbourhood_id', 'label' => 'Where do you collect?', 'type' => 'select', 'required' => true, 'options' => ['' => 'Choose…'] + $neighbourhoods]) ?>
        <?= component('field', ['name' => 'preferred_hub_id', 'label' => 'Preferred collection hub', 'type' => 'select', 'options' => ['' => 'Not sure yet'] + $hubs]) ?>
        <?= component('field', ['name' => 'national_id', 'label' => 'National ID number (optional)', 'hint' => 'You do not have to give this.']) ?>
        <?= component('field', ['name' => 'password', 'label' => 'Choose a password', 'type' => 'password', 'required' => true, 'attrs' => ['autocomplete' => 'new-password'], 'hint' => 'At least 6 characters.']) ?>
        <?= component('field', ['name' => 'password_confirmation', 'label' => 'Repeat the password', 'type' => 'password', 'required' => true, 'attrs' => ['autocomplete' => 'new-password']]) ?>
        <div class="alert alert-light border small"><?= e($consentText) ?></div>
        <?= component('field', ['name' => 'consent', 'type' => 'checkbox', 'label' => 'I have read and agree (data-use agreement)', 'required' => true]) ?>
        <button class="btn btn-primary btn-lg w-100" type="submit">Register</button>
      </form>
      <p class="text-center mt-3 mb-0">Already registered? <a href="<?= e(url('/login')) ?>">Sign in</a></p>
    </div></div>
  </div>
</section>
