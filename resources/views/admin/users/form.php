<?php /** @var array|null $user @var array $roles @var bool $isStaff */
$editing = $user !== null;
$action = $editing ? '/users/' . $user['id'] : '/users';
?>
<div class="card" style="max-width:640px"><div class="card-body">
  <form method="post" action="<?= e(url($action)) ?>" data-loading novalidate>
    <?= csrf_field() ?>
    <?= component('field', ['name' => 'full_name', 'label' => 'Full name', 'required' => true, 'value' => $editing ? ($user['full_name']) : null]) ?>
    <?= component('field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $editing ? ($user['email'] ?? '') : null, 'hint' => 'Email or phone number is required (both is fine).']) ?>
    <?= component('field', ['name' => 'phone', 'label' => 'Phone number', 'type' => 'tel', 'value' => $editing ? ($user['phone_e164'] ?? '') : null, 'hint' => 'For example 0771234567', 'attrs' => ['inputmode' => 'tel']]) ?>
    <?php if ($isStaff): ?>
      <?= component('field', ['name' => 'role', 'label' => 'Role', 'type' => 'select', 'required' => true, 'options' => $roles, 'value' => $editing ? $user['role_slug'] : null]) ?>
    <?php else: ?>
      <p class="text-muted small">Role: <?= e($user['role_name']) ?> (cannot be changed here).</p>
    <?php endif; ?>
    <?php if (!$editing): ?>
      <p class="small text-muted">A random temporary password will be created and shown once after saving. The person must change it at first sign-in.</p>
    <?php endif; ?>
    <button class="btn btn-primary" type="submit"><?= $editing ? 'Save changes' : 'Create account' ?></button>
    <a class="btn btn-outline-primary" href="<?= e(url($editing ? '/users/' . $user['id'] : '/users')) ?>">Cancel</a>
  </form>
</div></div>
