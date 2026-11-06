<?php /** @var bool $forced */ ?>
<div class="card" style="max-width:520px"><div class="card-body">
  <?php if ($forced): ?>
    <div class="alert alert-warning">You are using a temporary password. Choose your own password to continue.</div>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/account/password')) ?>" data-loading novalidate>
    <?= csrf_field() ?>
    <?= component('field', ['name' => 'current_password', 'label' => 'Current (or temporary) password', 'type' => 'password', 'required' => true, 'attrs' => ['autocomplete' => 'current-password']]) ?>
    <?= component('field', ['name' => 'password', 'label' => 'New password', 'type' => 'password', 'required' => true, 'attrs' => ['autocomplete' => 'new-password'], 'hint' => 'At least 8 characters (6 for waste pickers).']) ?>
    <?= component('field', ['name' => 'password_confirmation', 'label' => 'Repeat the new password', 'type' => 'password', 'required' => true, 'attrs' => ['autocomplete' => 'new-password']]) ?>
    <button class="btn btn-primary" type="submit">Change password</button>
  </form>
</div></div>
