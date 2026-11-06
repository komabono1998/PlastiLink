<section class="container py-5">
  <div class="mx-auto" style="max-width:440px">
    <div class="card"><div class="card-body p-4">
      <h1 class="h4 mb-1">Forgot your password?</h1>
      <p class="text-muted">Enter your phone number or email. If we find your account we will send a reset link.</p>
      <form method="post" action="<?= e(url('/forgot-password')) ?>" data-loading novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="identifier">Phone number or email</label>
          <input class="form-control form-control-lg" id="identifier" name="identifier" type="text"
                 autocomplete="username" autocapitalize="off" required autofocus>
        </div>
        <button class="btn btn-primary btn-lg w-100" type="submit">Send reset link</button>
      </form>
      <p class="text-center mt-3 mb-0"><a href="<?= e(url('/login')) ?>">Back to sign in</a></p>
    </div></div>
  </div>
</section>
