<?php /** @var array $balances @var array $mismatches @var string $tab */ ?>
<?= component('inventory-tabs', ['tab' => $tab]) ?>

<?php if ($mismatches): ?>
  <?= component('alert', ['type' => 'danger', 'message' => 'Stock check failed: ' . count($mismatches) . ' balance(s) do not match the movement ledger. Do not move stock until an administrator investigates.']) ?>
<?php else: ?>
  <div class="small text-success mb-2"><i class="bi bi-shield-check" aria-hidden="true"></i> Stock check passed: every balance equals the sum of its recorded movements.</div>
<?php endif; ?>

<?php if (can('inventory.manage')): ?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <a class="btn btn-primary" href="<?= e(url('/inventory/transfers/new')) ?>"><i class="bi bi-arrow-left-right"></i> Transfer to a hub</a>
  <a class="btn btn-outline-primary" href="<?= e(url('/inventory/dispatches/new')) ?>"><i class="bi bi-truck"></i> Dispatch to a recycler</a>
  <a class="btn btn-outline-primary" href="<?= e(url('/inventory/adjustments/new')) ?>"><i class="bi bi-clipboard-check"></i> Count / adjust stock</a>
</div>
<?php endif; ?>

<div class="card">
  <?php if (!$balances): ?>
    <?= component('empty-state', ['icon' => 'boxes', 'title' => 'No stock yet', 'text' => 'Stock appears here when collections are approved, or when a manual entry is approved.']) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Hub</th><th>Material</th><th class="text-end">On hand</th><th class="text-end">Reserved</th><th class="text-end">Available</th><th class="text-end">Leaving (in transit)</th><th class="text-end">Arriving (in transit)</th></tr></thead>
    <tbody>
    <?php foreach ($balances as $b): ?>
      <tr>
        <td><?= e($b['hub_name']) ?></td><td><?= e($b['category_name']) ?></td>
        <td class="text-end fw-bold text-nowrap"><?= e(format_kg($b['on_hand_kg'])) ?></td>
        <td class="text-end text-nowrap"><?= e(format_kg($b['reserved_kg'])) ?></td>
        <td class="text-end text-nowrap"><?= e(format_kg(\App\Helpers\Weight::toSignedDecimal(\App\Helpers\Weight::fromDecimal((string) $b['on_hand_kg']) - \App\Helpers\Weight::fromDecimal((string) $b['reserved_kg'])))) ?></td>
        <td class="text-end text-nowrap"><?= e(format_kg($b['transit_out_kg'])) ?></td>
        <td class="text-end text-nowrap"><?= e(format_kg($b['transit_in_kg'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
