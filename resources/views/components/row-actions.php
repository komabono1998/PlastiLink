<?php
/**
 * Row action buttons for list tables. Each action is either a link (href) or a one-click POST form (post).
 * The server re-checks every permission and rule; this only decides which buttons to show.
 * @var array<int,array{label:string,icon?:string,href?:string,post?:string,fields?:array<string,string>,class?:string,confirm?:string}> $actions
 */
?>
<div class="d-inline-flex flex-wrap gap-1 justify-content-end">
<?php foreach ($actions as $act):
    $cls = 'btn btn-sm ' . ($act['class'] ?? 'btn-outline-primary');
    $label = (!empty($act['icon']) ? '<i class="bi bi-' . e($act['icon']) . '"></i> ' : '') . e($act['label']); ?>
  <?php if (isset($act['href'])): ?>
    <a class="<?= e($cls) ?>" href="<?= e(url($act['href'])) ?>"><?= $label ?></a>
  <?php else: ?>
    <form method="post" action="<?= e(url($act['post'])) ?>" class="d-inline m-0"<?= !empty($act['confirm']) ? ' data-confirm="' . e($act['confirm']) . '"' : '' ?>>
      <?= csrf_field() ?>
      <?php foreach (($act['fields'] ?? []) as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
      <button class="<?= e($cls) ?>" type="submit"><?= $label ?></button>
    </form>
  <?php endif; ?>
<?php endforeach; ?>
</div>
