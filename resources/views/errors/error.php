<?php
/** @var int $status */ /** @var string $title */ /** @var string $message */ /** @var Throwable|null $debug */
?>
<section class="container py-5 text-center" style="max-width:640px">
  <div class="display-1 fw-bold" style="color:var(--pl-primary)"><?= e($status) ?></div>
  <h1 class="h3"><?= e($title) ?></h1>
  <p class="text-muted"><?= e($message) ?></p>
  <a class="btn btn-primary" href="<?= e(url('/')) ?>"><i class="bi bi-house"></i> Back to home</a>

  <?php if (!empty($debug)): ?>
    <div class="alert alert-warning text-start mt-4 small">
      <strong>Debug (visible because app.debug is on):</strong><br>
      <?= e(get_class($debug)) ?>: <?= e($debug->getMessage()) ?><br>
      <?= e($debug->getFile()) ?>:<?= e($debug->getLine()) ?>
    </div>
  <?php endif; ?>
</section>
