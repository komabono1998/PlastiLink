<?php
/**
 * Dashboard layout: sidebar + topbar. Used by all authenticated modules from Phase 2.
 * @var string $content
 * @var string|null $title
 * @var array<int, array{href:string, icon:string, label:string}> $nav
 */
$nav = $nav ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= e(($title ?? '') !== '' ? $title . ' · ' : '') ?><?= e(config('app.name')) ?></title>
  <meta name="robots" content="noindex">
  <meta name="theme-color" content="#086B52">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="PlastiLink">
  <meta name="pl-sw" content="<?= e(url('/sw.js')) ?>">
  <link rel="icon" href="<?= e(url('/favicon.ico')) ?>" sizes="48x48">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('img/brand/favicon-32.png')) ?>">
  <link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
  <link rel="apple-touch-icon" href="<?= e(asset('img/icons/apple-touch-icon.png')) ?>">
  <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/plastilink.css')) ?>">
</head>
<body class="app-body">
<div class="dash">
  <div class="sidebar-backdrop"></div>
  <aside class="sidebar" aria-label="Main navigation">
    <a class="sidebar-brand d-block" href="<?= e(url('/')) ?>" aria-label="PlastiLink home">
      <img class="logo-on-dark" src="<?= e(asset('img/brand/logo-reversed.png')) ?>" width="192" height="40" alt="PlastiLink">
      <img class="logo-on-light" src="<?= e(asset('img/brand/logo.png')) ?>" width="192" height="40" alt="" aria-hidden="true">
    </a>
    <div class="sidebar-panel">
      <?php if (can('collections.record')): ?>
        <a class="btn btn-light rounded-pill w-100 fw-semibold mb-3" href="<?= e(url('/collections/new')) ?>"><i class="bi bi-plus-lg"></i> Record collection</a>
      <?php elseif (can('pickers.register')): ?>
        <a class="btn btn-light rounded-pill w-100 fw-semibold mb-3" href="<?= e(url('/pickers/register')) ?>"><i class="bi bi-person-plus"></i> Register picker</a>
      <?php endif; ?>
      <nav class="nav flex-column gap-1">
        <?php foreach ($nav as $item): ?>
          <a class="nav-link<?= !empty($item['active']) ? ' active' : '' ?>" href="<?= e($item['href']) ?>"<?= !empty($item['active']) ? ' aria-current="page"' : '' ?>><i class="bi bi-<?= e($item['icon']) ?>" aria-hidden="true"></i><?= e($item['label']) ?></a>
        <?php endforeach; ?>
      </nav>
    </div>
  </aside>

  <div class="dash-main">
    <header class="dash-topbar d-flex align-items-center gap-3 px-3 px-lg-4 py-2">
      <button class="btn btn-outline-primary d-lg-none" type="button" data-sidebar-toggle aria-label="Open menu" aria-expanded="false"><i class="bi bi-list"></i></button>
      <h1 class="h5 mb-0 text-truncate flex-grow-1 flex-md-grow-0"><?= e($title ?? '') ?></h1>
      <?php if (count($nav) > 3): ?>
      <form class="topbar-search d-none d-md-flex" role="search" data-prevent-submit>
        <i class="bi bi-search" aria-hidden="true"></i>
        <input class="form-control" type="search" list="nav-jump" data-nav-jump placeholder="Jump to a page…" aria-label="Jump to a page" autocomplete="off">
        <datalist id="nav-jump"><?php foreach ($nav as $item): ?><option value="<?= e($item['label']) ?>" data-href="<?= e($item['href']) ?>"></option><?php endforeach; ?></datalist>
      </form>
      <?php endif; ?>
      <div class="ms-auto d-flex align-items-center gap-2 flex-shrink-0">
        <?php if ($authUser = auth_user()): ?>
          <div class="text-end lh-sm d-none d-sm-block">
            <div class="fw-semibold small"><?= e($authUser['full_name']) ?></div>
            <div class="small text-muted"><?= e($authUser['role_name']) ?></div>
          </div>
          <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(trim((string) $authUser['full_name']), 0, 1))) ?></span>
          <form method="post" action="<?= e(url('/logout')) ?>" class="m-0">
            <?= csrf_field() ?>
            <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Sign out</button>
          </form>
        <?php else: ?>
          <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/')) ?>"><i class="bi bi-house"></i> Home</a>
        <?php endif; ?>
      </div>
    </header>
    <main class="p-3 p-lg-4"><?= flash_messages() ?><?= $content ?></main>
  </div>
</div>

<?php if (count($nav) > 1): ?>
<!-- Phone navigation bar: the first few menu items plus the full menu -->
<nav class="bottom-nav d-lg-none" aria-label="Quick navigation">
  <?php foreach (array_slice($nav, 0, 4) as $item): ?>
    <a class="bn-item<?= !empty($item['active']) ? ' active' : '' ?>" href="<?= e($item['href']) ?>"<?= !empty($item['active']) ? ' aria-current="page"' : '' ?>><i class="bi bi-<?= e($item['icon']) ?>" aria-hidden="true"></i><span><?= e($item['label']) ?></span></a>
  <?php endforeach; ?>
  <button class="bn-item" type="button" data-sidebar-toggle aria-label="Open full menu"><i class="bi bi-grid-3x3-gap" aria-hidden="true"></i><span>Menu</span></button>
</nav>
<?php endif; ?>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
