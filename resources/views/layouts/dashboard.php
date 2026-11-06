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
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(($title ?? '') !== '' ? $title . ' · ' : '') ?><?= e(config('app.name')) ?></title>
  <meta name="robots" content="noindex">
  <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/plastilink.css')) ?>">
</head>
<body>
<div class="dash">
  <div class="sidebar-backdrop"></div>
  <aside class="sidebar p-3" aria-label="Main navigation">
    <a class="brand brand-on-dark d-block mb-4 px-2" href="<?= e(url('/')) ?>"><span class="brand-plasti">Plasti</span><span class="brand-link">Link</span></a>
    <nav class="nav flex-column gap-1">
      <?php foreach ($nav as $item): ?>
        <a class="nav-link<?= !empty($item['active']) ? ' active' : '' ?>" href="<?= e($item['href']) ?>"<?= !empty($item['active']) ? ' aria-current="page"' : '' ?>><i class="bi bi-<?= e($item['icon']) ?>" aria-hidden="true"></i><?= e($item['label']) ?></a>
      <?php endforeach; ?>
    </nav>
  </aside>

  <div class="dash-main">
    <header class="dash-topbar d-flex align-items-center gap-3 px-3 py-2">
      <button class="btn btn-outline-primary d-lg-none" type="button" data-sidebar-toggle aria-label="Open menu" aria-expanded="false"><i class="bi bi-list"></i></button>
      <h1 class="h5 mb-0"><?= e($title ?? '') ?></h1>
      <div class="ms-auto d-flex align-items-center gap-2">
        <?php if ($authUser = auth_user()): ?>
          <div class="text-end lh-sm d-none d-sm-block">
            <div class="fw-semibold small"><?= e($authUser['full_name']) ?></div>
            <div class="small text-muted"><?= e($authUser['role_name']) ?></div>
          </div>
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
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
