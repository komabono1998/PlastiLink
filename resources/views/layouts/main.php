<?php /** @var string $content */ /** @var string|null $title */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(($title ?? '') !== '' ? $title . ' · ' : '') ?><?= e(config('app.name')) ?></title>
  <meta name="description" content="<?= e(config('app.tagline')) ?>">
  <meta name="theme-color" content="#086B52">
  <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/plastilink.css')) ?>">
</head>
<body class="d-flex flex-column min-vh-100">
  <a class="visually-hidden-focusable btn btn-accent m-2" href="#main">Skip to content</a>

  <nav class="site-nav navbar navbar-expand-md">
    <div class="container">
      <a class="brand navbar-brand" href="<?= e(url('/')) ?>"><span class="brand-plasti">Plasti</span><span class="brand-link">Link</span></a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="nav">
        <ul class="navbar-nav ms-auto align-items-md-center gap-md-2">
          <li class="nav-item"><a class="nav-link" href="<?= e(url('/')) ?>#how-it-works">How it works</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= e(url('/')) ?>#targets">Programme targets</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= e(url('/health')) ?>">System status</a></li>
        </ul>
      </div>
    </div>
  </nav>

  <main id="main" class="flex-grow-1"><?= $content ?></main>

  <footer class="site-footer py-4 mt-5">
    <div class="container d-flex flex-column flex-md-row justify-content-between gap-2">
      <span class="brand brand-on-dark"><span class="brand-plasti">Plasti</span><span class="brand-link">Link</span></span>
      <small>&copy; <?= e(date('Y')) ?> <?= e(config('app.name')) ?> · Gulu City, Uganda · <?= e(config('app.tagline')) ?></small>
    </div>
  </footer>

  <script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
  <script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
