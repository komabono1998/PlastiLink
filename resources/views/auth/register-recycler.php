<?php /** @var string $consentText */ ?>
<section class="container py-4">
  <div class="mx-auto" style="max-width:560px">
    <div class="card"><div class="card-body p-4">
      <h1 class="h4 mb-1">Register your recycling company</h1>
      <p class="text-muted">The PlastiLink team reviews every company before it can buy plastic.</p>
      <form method="post" action="<?= e(url('/register/recycler')) ?>" data-loading novalidate>
        <?= csrf_field() ?>
        <?= component('field', ['name' => 'business_name', 'label' => 'Business name', 'required' => true]) ?>
        <?= component('field', ['name' => 'registration_number', 'label' => 'Business registration number', 'hint' => 'Optional']) ?>
        <?= component('field', ['name' => 'contact_person', 'label' => 'Contact person', 'required' => true, 'attrs' => ['autocomplete' => 'name']]) ?>
        <?= component('field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'attrs' => ['autocomplete' => 'email']]) ?>
        <?= component('field', ['name' => 'phone', 'label' => 'Phone number', 'type' => 'tel', 'required' => true, 'attrs' => ['inputmode' => 'tel', 'autocomplete' => 'tel']]) ?>
        <?= component('field', ['name' => 'address', 'label' => 'Business address', 'required' => true]) ?>
        <?= component('field', ['name' => 'materials', 'label' => 'Materials you buy', 'value' => old('materials', 'PET bottles')]) ?>
        <?= component('field', ['name' => 'password', 'label' => 'Choose a password', 'type' => 'password', 'required' => true, 'attrs' => ['autocomplete' => 'new-password'], 'hint' => 'At least 8 characters.']) ?>
        <?= component('field', ['name' => 'password_confirmation', 'label' => 'Repeat the password', 'type' => 'password', 'required' => true, 'attrs' => ['autocomplete' => 'new-password']]) ?>
        <div class="alert alert-light border small"><?= e($consentText) ?></div>
        <?= component('field', ['name' => 'consent', 'type' => 'checkbox', 'label' => 'I have read and agree (data-use agreement)', 'required' => true]) ?>
        <button class="btn btn-primary btn-lg w-100" type="submit">Submit registration</button>
      </form>
      <p class="text-center mt-3 mb-0">Already registered? <a href="<?= e(url('/login')) ?>">Sign in</a></p>
    </div></div>
  </div>
</section>
