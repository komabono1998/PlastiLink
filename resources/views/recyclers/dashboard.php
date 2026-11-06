<?php /** @var array $stats @var array $recent @var int $available @var int $owing @var int $overdueInvoices */ ?>
<?= component('welcome', ['user' => auth_user(), 'blurb' => 'Recycler marketplace', 'coming' => ['Purchase reports (Phase 8)']]) ?>

<div class="d-flex flex-wrap gap-2 mt-1 mb-3">
  <a class="btn btn-primary btn-lg" href="<?= e(url('/marketplace')) ?>"><i class="bi bi-shop-window"></i> Browse available plastic<?= $available ? ' (' . e($available) . ')' : '' ?></a>
  <a class="btn btn-outline-primary btn-lg" href="<?= e(url('/orders')) ?>"><i class="bi bi-receipt-cutoff"></i> My orders</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><a class="text-decoration-none text-reset" href="<?= e(url('/orders?awaiting=1')) ?>"><?= component('stat-card', ['icon' => 'hourglass-split', 'value' => (string) $stats['awaiting'], 'label' => 'Waiting for your answer']) ?></a></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'chat-dots', 'value' => (string) $stats['negotiating'], 'label' => 'Negotiations open']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'truck', 'value' => (string) $stats['active'], 'label' => 'Orders being delivered']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'box-seam', 'value' => format_kg($stats['received_kg']), 'label' => 'Confirmed received', 'hint' => format_ugx($stats['received_value']) . ' at agreed prices']) ?></div>
</div>

<div class="row g-3 mb-4">
  <div class="col-6"><a class="text-decoration-none text-reset" href="<?= e(url('/invoices')) ?>"><?= component('stat-card', ['icon' => 'file-earmark-text', 'value' => format_ugx($owing), 'label' => 'You owe PlastiLink', 'hint' => 'See your invoices']) ?></a></div>
  <div class="col-6"><a class="text-decoration-none text-reset" href="<?= e(url('/invoices?status=overdue')) ?>"><?= component('stat-card', ['icon' => 'alarm', 'value' => (string) $overdueInvoices, 'label' => 'Overdue invoices']) ?></a></div>
</div>

<div class="card"><div class="card-body pb-0 d-flex justify-content-between"><h2 class="h6">Latest orders</h2><a href="<?= e(url('/orders')) ?>">See all</a></div>
  <?php if (!$recent): ?>
    <?= component('empty-state', ['icon' => 'receipt-cutoff', 'title' => 'No orders yet', 'text' => 'Browse the marketplace and make your first offer.']) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Reference</th><th>Hub</th><th class="text-end">Quantity</th><th>Delivery</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $o): $q = $o['agreed_qty_kg'] ?? $o['offer_qty_kg']; ?>
      <tr><td><a class="fw-semibold" href="<?= e(url('/orders/' . $o['id'])) ?>"><?= e($o['reference']) ?></a></td><td><?= e($o['hub_name']) ?></td>
        <td class="text-end text-nowrap"><?= e(format_kg($q)) ?></td>
        <td class="small text-nowrap"><?= $o['scheduled_for'] ? e(date('d M', strtotime($o['scheduled_for']))) : '—' ?></td>
        <td><?= component('status-badge', ['status' => $o['status']]) ?><?= ($o['status'] === 'negotiating' && $o['last_offer_by'] === 'seller') ? ' <span class="badge text-bg-primary">Your turn</span>' : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
