<?php /** @var array $stock */ ?>
<p class="text-muted">Plastic available to buy now at PlastiLink hubs. Quantities already reserved for other orders are not shown. Choose a hub to make an offer.</p>
<div class="card">
  <?php if (!$stock): ?>
    <?= component('empty-state', ['icon' => 'shop-window', 'title' => 'Nothing available right now', 'text' => 'Stock appears here as hubs approve collections. Check again soon.']) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Hub</th><th>Neighbourhood</th><th>Material</th><th class="text-end">Available</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($stock as $s): ?>
      <tr>
        <td class="fw-semibold"><?= e($s['hub_name']) ?></td><td><?= e($s['neighbourhood']) ?></td><td><?= e($s['category_name']) ?></td>
        <td class="text-end fw-bold text-nowrap"><?= e(format_kg($s['available_kg'])) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-primary" href="<?= e(url('/orders/new?hub=' . $s['hub_id'] . '&cat=' . $s['plastic_category_id'])) ?>">Make an offer</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
