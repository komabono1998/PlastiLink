<?php /** @var string $content */ /** @var string|null $title */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="PlastiLink">
  <meta name="pl-sw" content="<?= e(url('/sw.js')) ?>">
  <link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
  <link rel="apple-touch-icon" href="<?= e(asset('img/icons/apple-touch-icon.png')) ?>">
  <title><?= e(($title ?? '') !== '' ? $title . ' · ' : '') ?><?= e(config('app.name')) ?></title>
  <meta name="description" content="<?= e(config('app.tagline')) ?>">
  <meta name="theme-color" content="#086B52">
  <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/plastilink.css')) ?>">
</head>
<body class="d-flex flex-column min-vh-100">
  <a class="visually-hidden-focusable btn btn-accent m-2" href="#main">Skip to content</a>

  <nav class="site-nav navbar navbar-expand-md navbar-dark">
    <div class="container">
      <a class="brand navbar-brand" href="<?= e(url('/')) ?>"><span class="brand-plasti">Plasti</span><span class="brand-link">Link</span></a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="nav">
        <ul class="navbar-nav ms-auto align-items-md-center gap-md-2">
          <li class="nav-item"><a class="nav-link" href="<?= e(url('/')) ?>#how-it-works">How it works</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= e(url('/')) ?>#targets">Programme targets</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= e(url('/')) ?>#faq">FAQ</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= e(url('/health')) ?>">System status</a></li>
          <?php if ($navUser = auth_user()): ?>
            <li class="nav-item"><a class="btn btn-lime btn-sm" href="<?= e(url('/dashboard')) ?>"><i class="bi bi-speedometer2"></i> My dashboard</a></li>
          <?php else: ?>
            <li class="nav-item"><a class="btn btn-lime btn-sm" href="<?= e(url('/login')) ?>"><i class="bi bi-box-arrow-in-right"></i> Sign in</a></li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </nav>

  <main id="main" class="flex-grow-1">
    <?php $flash = flash_messages(); if ($flash !== ''): ?><div class="container pt-3"><?= $flash ?></div><?php endif; ?>
    <?= $content ?>
  </main>

  <footer class="site-footer pt-5 pb-4 mt-5">
    <div class="container">
      <div class="row g-4">
        <div class="col-md-5">
          <span class="brand brand-on-dark d-inline-block mb-2"><span class="brand-plasti">Plasti</span><span class="brand-link">Link</span></span>
          <p class="small mb-0 footer-muted"><?= e(config('app.tagline')) ?> Connecting waste pickers, collection hubs and recyclers in Gulu City, Uganda.</p>
        </div>
        <div class="col-6 col-md-3">
          <h2 class="h6 text-white">Explore</h2>
          <ul class="list-unstyled small mb-0">
            <li><a href="<?= e(url('/')) ?>#how-it-works">How it works</a></li>
            <li><a href="<?= e(url('/')) ?>#targets">Programme targets</a></li>
            <li><a href="<?= e(url('/')) ?>#faq">FAQ</a></li>
          </ul>
        </div>
        <div class="col-6 col-md-4">
          <h2 class="h6 text-white">Get started</h2>
          <ul class="list-unstyled small mb-0">
            <li><a href="<?= e(url('/register')) ?>">Register as a waste picker</a></li>
            <li><a href="<?= e(url('/register/recycler')) ?>">Register as a recycler</a></li>
            <li><a href="<?= e(url('/login')) ?>">Sign in</a></li>
          </ul>
        </div>
      </div>
      <hr class="footer-rule">
      <small class="footer-muted">&copy; <?= e(date('Y')) ?> <?= e(config('app.name')) ?> · Gulu City, Uganda</small>
    </div>
  </footer>

  <script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
  <script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
