<section class="container py-5">
  <div class="mx-auto" style="max-width:440px">
    <div class="card"><div class="card-body p-4">
      <h1 class="h4 mb-1">Sign in</h1>
      <p class="text-muted">Use your phone number or email address.</p>

      <form method="post" action="<?= e(url('/login')) ?>" data-loading novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="identifier">Phone number or email</label>
          <input class="form-control form-control-lg" id="identifier" name="identifier" type="text"
                 autocomplete="username" autocapitalize="off" spellcheck="false" required autofocus
                 value="<?= e(old('identifier')) ?>" placeholder="0771234567 or email">
        </div>
        <div class="mb-3">
          <label class="form-label" for="password">Password</label>
          <div class="input-group">
            <input class="form-control form-control-lg" id="password" name="password" type="password"
                   autocomplete="current-password" required>
            <button class="btn btn-outline-primary" type="button" data-toggle-password="#password"
                    aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
          </div>
        </div>
        <button class="btn btn-primary btn-lg w-100" type="submit">Sign in</button>
      </form>

      <p class="text-center mt-3 mb-0"><a href="<?= e(url('/forgot-password')) ?>">Forgot your password?</a></p>
    </div></div>
  </div>
</section>
