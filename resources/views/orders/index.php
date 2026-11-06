<?php /** @var array $rows @var array $pg @var array $f @var string $side @var array $stats */
$mine = $side === 'recycler';
?>
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><a class="text-decoration-none text-reset" href="<?= e(url('/orders?awaiting=1')) ?>"><?= component('stat-card', ['icon' => 'hourglass-split', 'value' => (string) $stats['awaiting'], 'label' => $mine ? 'Waiting for your answer' : 'Requests waiting for a response']) ?></a></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'chat-dots', 'value' => (string) $stats['negotiating'], 'label' => 'Negotiations open']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'truck', 'value' => (string) $stats['active'], 'label' => 'Orders being delivered']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'box-seam', 'value' => format_kg($stats['received_kg']), 'label' => 'Confirmed received', 'hint' => format_ugx($stats['received_value']) . ' at agreed prices']) ?></div>
</div>

<div class="d-flex flex-wrap gap-2 justify-content-between mb-3">
  <form class="row g-2 flex-grow-1" method="get" action="<?= e(url('/orders')) ?>">
    <div class="col-12 col-md-4"><label class="visually-hidden" for="q">Search</label>
      <input class="form-control" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="<?= $mine ? 'Order reference' : 'Reference or company' ?>"></div>
    <div class="col-8 col-md-4"><label class="visually-hidden" for="status">Status</label>
      <select class="form-select" id="status" name="status"><option value="">Any status</option>
        <?php foreach (['negotiating', 'confirmed', 'in_delivery', 'completed', 'rejected', 'withdrawn', 'cancelled', 'expired'] as $s): ?><option value="<?= e($s) ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?></select></div>
    <div class="col-4 col-md-2"><button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-search"></i></button></div>
  </form>
  <?php if ($mine): ?><a class="btn btn-primary" href="<?= e(url('/marketplace')) ?>"><i class="bi bi-shop-window"></i> Browse stock</a><?php endif; ?>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <?= component('empty-state', ['icon' => 'receipt-cutoff', 'title' => 'No orders yet', 'text' => $mine ? 'Browse the marketplace and make your first offer.' : 'Purchase requests from recyclers will appear here.']) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Reference</th><?php if (!$mine): ?><th>Recycler</th><?php endif; ?><th>Hub</th><th>Material</th><th class="text-end">Quantity</th><th class="text-end">Price/kg</th><th class="text-end">Value</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $o):
        $agreed = $o['agreed_qty_kg'] !== null;
        $qty = $agreed ? $o['agreed_qty_kg'] : $o['offer_qty_kg'];
        $price = $agreed ? $o['item_price'] : $o['offer_price'];
        $total = $agreed ? $o['line_total_ugx'] : $o['offer_total'];
        $turn = $o['status'] === 'negotiating' && $o['last_offer_by'] !== $side; ?>
      <tr>
        <td class="text-nowrap"><a class="fw-semibold" href="<?= e(url('/orders/' . $o['id'])) ?>"><?= e($o['reference']) ?></a></td>
        <?php if (!$mine): ?><td><?= e($o['business_name']) ?></td><?php endif; ?>
        <td><?= e($o['hub_name']) ?></td><td><?= e($o['category_name']) ?></td>
        <td class="text-end text-nowrap"><?= e(format_kg($qty)) ?></td><td class="text-end text-nowrap"><?= e(number_format((int) $price)) ?></td><td class="text-end text-nowrap"><?= e(format_ugx($total)) ?></td>
        <td><?= component('status-badge', ['status' => $o['status']]) ?><?= $turn ? ' <span class="badge text-bg-primary">Your turn</span>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= component('pagination', ['p' => $pg]) ?>
  <?php endif; ?>
</div>
