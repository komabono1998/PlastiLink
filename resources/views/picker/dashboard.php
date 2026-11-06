<?php /** @var array $summary @var array $recent @var array $pos */ ?>
<?= component('welcome', ['user' => auth_user(), 'blurb' => 'Your collections, earnings and receipts', 'coming' => []]) ?>

<div class="row g-3 mt-1 mb-3">
  <div class="col-6"><?= component('stat-card', ['icon' => 'box-seam', 'value' => format_kg($summary['kg']), 'label' => 'Approved plastic delivered']) ?></div>
  <div class="col-6"><a class="text-decoration-none text-reset" href="<?= e(url('/earnings')) ?>"><?= component('stat-card', ['icon' => 'wallet2', 'value' => format_ugx($pos['available']), 'label' => 'Available to pay out', 'hint' => $pos['held'] > 0 ? format_ugx($pos['held']) . ' in requests' : 'Tap to request a payout']) ?></a></div>
</div>

<div class="card"><div class="card-body pb-0 d-flex justify-content-between"><h2 class="h6">Latest collections</h2>
  <a href="<?= e(url('/collections')) ?>">See all</a></div>
  <?php if (!$recent): ?>
    <?= component('empty-state', ['icon' => 'box-seam', 'title' => 'No collections yet', 'text' => 'Take your plastic to a PlastiLink hub. It will be weighed and shown here.']) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Date</th><th class="text-end">Weight</th><th class="text-end">Value</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $c): ?>
      <tr><td class="small text-nowrap"><?= e(format_datetime($c['recorded_at'], 'd M, H:i')) ?></td>
        <td class="text-end text-nowrap"><?= e(format_kg($c['net_weight_kg'])) ?></td>
        <td class="text-end text-nowrap"><?= e(format_ugx($c['total_value_ugx'])) ?></td>
        <td><?= component('status-badge', ['status' => $c['status']]) ?></td>
        <td class="text-end"><?= $c['receipt_id'] ? '<a href="' . e(url('/receipts/' . $c['receipt_id'])) . '">Receipt</a>' : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
