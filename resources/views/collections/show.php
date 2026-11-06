<?php /** @var array $c @var array $weighings @var bool $ownRecord */
$pending = $c['status'] === 'pending';
$form = static fn(string $action, string $inner, string $confirm = ''): string =>
    '<form method="post" action="' . e(url('/collections/' . $c['id'] . '/' . $action)) . '"' . ($confirm !== '' ? ' onsubmit="return confirm(\'' . e($confirm) . '\');"' : '') . '>' . csrf_field() . $inner . '</form>';
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card"><div class="card-body">
      <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
        <div><h2 class="h5 mb-0"><?= e($c['reference']) ?></h2><div class="text-muted small">Recorded <?= e(format_datetime($c['recorded_at'])) ?> by <?= e($c['recorded_by_name']) ?></div></div>
        <?= component('status-badge', ['status' => $c['status']]) ?>
      </div>
      <dl class="row mt-3 mb-0">
        <dt class="col-sm-4">Picker</dt><dd class="col-sm-8"><?= e($c['picker_name']) ?> <span class="text-muted">(<?= e($c['picker_code']) ?>)</span></dd>
        <dt class="col-sm-4">Hub</dt><dd class="col-sm-8"><?= e($c['hub_name']) ?> · <?= e($c['neighbourhood']) ?></dd>
        <dt class="col-sm-4">Material</dt><dd class="col-sm-8"><?= e($c['category_name']) ?></dd>
        <dt class="col-sm-4">Gross weight</dt><dd class="col-sm-8"><?= e(format_kg($c['gross_weight_kg'])) ?></dd>
        <dt class="col-sm-4">Tare weight</dt><dd class="col-sm-8"><?= e(format_kg($c['tare_weight_kg'])) ?></dd>
        <dt class="col-sm-4">Net weight</dt><dd class="col-sm-8 fw-bold"><?= e(format_kg($c['net_weight_kg'])) ?></dd>
        <dt class="col-sm-4">Price per kg</dt><dd class="col-sm-8"><?= e(format_ugx($c['price_per_kg_ugx'])) ?></dd>
        <dt class="col-sm-4">Total value</dt><dd class="col-sm-8 fs-5 fw-bold"><?= e(format_ugx($c['total_value_ugx'])) ?></dd>
        <?php if ($c['notes']): ?><dt class="col-sm-4">Notes</dt><dd class="col-sm-8"><?= e($c['notes']) ?></dd><?php endif; ?>
        <?php if ($c['decided_at']): ?><dt class="col-sm-4"><?= e(ucfirst($c['status'] === 'rejected' ? 'Rejected' : 'Approved')) ?></dt>
          <dd class="col-sm-8"><?= e($c['decided_by_name'] ?? '—') ?>, <?= e(format_datetime($c['decided_at'])) ?><?= $c['decision_reason'] ? '<br>Reason: ' . e($c['decision_reason']) : '' ?></dd><?php endif; ?>
        <?php if ($c['reversed_at']): ?><dt class="col-sm-4">Reversed</dt>
          <dd class="col-sm-8"><?= e($c['reversed_by_name'] ?? '—') ?>, <?= e(format_datetime($c['reversed_at'])) ?><br>Reason: <?= e($c['reversal_reason']) ?></dd><?php endif; ?>
      </dl>
      <?php if ($c['receipt_id']): ?>
        <hr><a class="btn btn-outline-primary" href="<?= e(url('/receipts/' . $c['receipt_id'])) ?>"><i class="bi bi-receipt"></i> View receipt <?= e($c['receipt_number']) ?><?= $c['receipt_voided_at'] ? ' (voided)' : '' ?></a>
      <?php endif; ?>
    </div></div>

    <div class="card mt-3"><div class="card-body">
      <h2 class="h6">Weighing history</h2>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>#</th><th>When</th><th>By</th><th class="text-end">Gross</th><th class="text-end">Tare</th><th class="text-end">Net</th><th class="text-end">Price/kg</th><th class="text-end">Value</th><th>Reason</th></tr></thead>
        <tbody>
        <?php foreach ($weighings as $w): ?>
          <tr><td><?= e($w['sequence_no']) ?></td><td class="small text-nowrap"><?= e(format_datetime($w['weighed_at'])) ?></td><td><?= e($w['weighed_by_name']) ?></td>
            <td class="text-end"><?= e(format_kg($w['gross_weight_kg'])) ?></td><td class="text-end"><?= e(format_kg($w['tare_weight_kg'])) ?></td><td class="text-end"><?= e(format_kg($w['net_weight_kg'])) ?></td>
            <td class="text-end"><?= e(number_format((int) $w['price_per_kg_ugx'])) ?></td><td class="text-end"><?= e(number_format((int) $w['total_value_ugx'])) ?></td><td class="small"><?= e($w['reason'] ?? 'First weighing') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div></div>
  </div>

  <div class="col-lg-5">
    <?php if ($pending && can('collections.approve')): ?>
    <div class="card mb-3"><div class="card-body">
      <h2 class="h6">Verification</h2>
      <?php if ($ownRecord): ?>
        <p class="small text-muted">You recorded this collection, so another person must approve it. You may still reject (cancel) it if it was a mistake.</p>
      <?php else: ?>
        <?= $form('approve', '<button class="btn btn-primary" type="submit"><i class="bi bi-check-circle"></i> Approve and issue receipt</button>') ?>
      <?php endif; ?>
      <hr>
      <?= $form('reject', '<label class="form-label" for="rej">Reject this collection</label><textarea class="form-control mb-2" id="rej" name="reason" rows="2" placeholder="Reason (required)" required></textarea><button class="btn btn-outline-danger" type="submit">Reject</button>') ?>
    </div></div>
    <?php endif; ?>

    <?php if ($pending && can('collections.record')): ?>
    <div class="card mb-3"><div class="card-body">
      <h2 class="h6">Re-weigh</h2>
      <p class="small text-muted">Saves a new weighing and keeps the old one. The price in force now is applied.</p>
      <?= $form('reweigh',
          component('field', ['name' => 'gross_weight_kg', 'label' => 'Gross weight (kg)', 'required' => true, 'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off']])
          . component('field', ['name' => 'tare_weight_kg', 'label' => 'Tare (kg)', 'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off']])
          . component('field', ['name' => 'reason', 'label' => 'Reason for re-weighing', 'required' => true])
          . '<button class="btn btn-outline-primary" type="submit">Save new weighing</button>') ?>
    </div></div>
    <?php endif; ?>

    <?php if ($c['status'] === 'approved' && can('collections.reverse')): ?>
    <div class="card"><div class="card-body">
      <h2 class="h6">Reverse this collection</h2>
      <p class="small text-muted">For genuine errors only. The record stays, its receipt is voided and the reason is audited.</p>
      <?= $form('reverse', '<textarea class="form-control mb-2" name="reason" rows="2" placeholder="Reason (required)" required aria-label="Reason for reversal"></textarea><button class="btn btn-outline-danger" type="submit">Reverse</button>', 'Reverse this approved collection? This cannot be undone.') ?>
    </div></div>
    <?php endif; ?>
  </div>
</div>
