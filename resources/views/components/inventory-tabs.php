<?php /** @var string $tab */
$tabs = ['balances' => ['/inventory', 'Stock balances'], 'movements' => ['/inventory/movements', 'Movements'], 'transfers' => ['/inventory/transfers', 'Transfers'],
         'dispatches' => ['/inventory/dispatches', 'Dispatches'], 'adjustments' => ['/inventory/adjustments', 'Adjustments']];
?>
<ul class="nav nav-pills flex-nowrap overflow-auto mb-3 pb-1" aria-label="Inventory sections">
  <?php foreach ($tabs as $key => [$path, $label]): ?>
    <li class="nav-item"><a class="nav-link text-nowrap<?= $tab === $key ? ' active' : '' ?>" href="<?= e(url($path)) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
  <?php endforeach; ?>
</ul>
