<?php /** @var array{page:int,pages:int,total:int,per:int} $p */ ?>
<?php if ($p['pages'] > 1): ?>
<nav aria-label="Pages" class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3">
  <span class="small text-muted"><?= e($p['total']) ?> records · page <?= e($p['page']) ?> of <?= e($p['pages']) ?></span>
  <ul class="pagination pagination-sm mb-0">
    <li class="page-item<?= $p['page'] <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= e(page_url($p['page'] - 1)) ?>">Previous</a></li>
    <li class="page-item<?= $p['page'] >= $p['pages'] ? ' disabled' : '' ?>"><a class="page-link" href="<?= e(page_url($p['page'] + 1)) ?>">Next</a></li>
  </ul>
</nav>
<?php elseif ($p['total'] > 0): ?>
<div class="small text-muted p-3"><?= e($p['total']) ?> record<?= $p['total'] === 1 ? '' : 's' ?></div>
<?php endif; ?>
