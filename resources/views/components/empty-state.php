<?php /** @var string $icon */ /** @var string $title */ /** @var string|null $text */ ?>
<div class="empty-state">
  <i class="bi bi-<?= e($icon ?? 'inbox') ?>" aria-hidden="true"></i>
  <h2 class="h6 mt-2 mb-1"><?= e($title) ?></h2>
  <?php if (!empty($text)): ?><p class="mb-0"><?= e($text) ?></p><?php endif; ?>
</div>
