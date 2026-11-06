<?php /** @var string $token */ ?>
<section class="container py-5">
  <div class="mx-auto" style="max-width:440px">
    <div class="card"><div class="card-body p-4">
      <h1 class="h4 mb-1">Set a new password</h1>
      <p class="text-muted">Choose a password you will remember. Staff passwords need at least 8 characters; waste pickers at least 6.</p>
      <form method="post" action="<?= e(url('/reset-password')) ?>" data-loading novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="mb-3">
          <label class="form-label" for="password">New password</label>
          <input class="form-control form-control-lg" id="password" name="password" type="password" autocomplete="new-password" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label" for="password_confirmation">Repeat the new password</label>
          <input class="form-control form-control-lg" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
        </div>
        <button class="btn btn-primary btn-lg w-100" type="submit">Change password</button>
      </form>
    </div></div>
  </div>
</section>
