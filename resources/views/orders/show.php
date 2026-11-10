<?php
/** @var array $o @var array $offers @var ?array $latest @var array $dispatches @var array $act @var string $token @var int $maxRounds */
$mine = $act['side'] === 'recycler';
$agreed = $o['agreed_qty_kg'] !== null;
$oid = (int) $o['id'];
$post = function (string $path, string $inner, string $confirm = '') use ($oid): string {
    return '<form method="post" action="' . e(url('/orders/' . $oid . '/' . $path)) . '"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . ' data-loading>'
        . csrf_field() . $inner . '</form>';
};
$remaining = $agreed ? \App\Helpers\Weight::fromDecimal((string) $o['agreed_qty_kg']) - \App\Helpers\Weight::fromDecimal((string) $o['dispatched_kg']) : 0;
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-body">
      <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
        <div><h2 class="h5 mb-0"><?= e($o['reference']) ?></h2><div class="small text-muted"><?= e($o['business_name']) ?> · <?= e($o['hub_name']) ?> (<?= e($o['neighbourhood']) ?>)</div></div>
        <?= component('status-badge', ['status' => $o['status']]) ?>
      </div>
      <dl class="row mt-3 mb-0">
        <dt class="col-sm-4">Material</dt><dd class="col-sm-8"><?= e($o['category_name']) ?></dd>
        <?php if ($agreed): ?>
          <dt class="col-sm-4">Agreed quantity</dt><dd class="col-sm-8 fw-bold"><?= e(format_kg($o['agreed_qty_kg'])) ?></dd>
          <dt class="col-sm-4">Agreed price</dt><dd class="col-sm-8"><?= e(format_ugx($o['item_price'])) ?> per kg</dd>
          <dt class="col-sm-4">Agreed value</dt><dd class="col-sm-8 fs-5 fw-bold"><?= e(format_ugx($o['line_total_ugx'])) ?></dd>
          <dt class="col-sm-4">Dispatched</dt><dd class="col-sm-8"><?= e(format_kg($o['dispatched_kg'])) ?> of <?= e(format_kg($o['agreed_qty_kg'])) ?></dd>
          <dt class="col-sm-4">Confirmed received</dt><dd class="col-sm-8"><?= e(format_kg($o['received_kg'])) ?></dd>
          <dt class="col-sm-4">Delivery date</dt><dd class="col-sm-8"><?= $o['scheduled_for'] ? e(date('d M Y', strtotime($o['scheduled_for']))) . ($o['schedule_note'] ? ' · ' . e($o['schedule_note']) : '') : 'Not scheduled yet' ?></dd>
          <?php if ((float) $o['short_closed_kg'] > 0): ?><dt class="col-sm-4">Closed short</dt><dd class="col-sm-8 text-danger"><?= e(format_kg($o['short_closed_kg'])) ?> was not delivered</dd><?php endif; ?>
        <?php elseif ($latest): ?>
          <dt class="col-sm-4">Latest offer</dt><dd class="col-sm-8"><?= e(format_kg($latest['qty_kg'])) ?> at <?= e(format_ugx($latest['price_per_kg_ugx'])) ?>/kg = <strong><?= e(format_ugx($latest['total_ugx'])) ?></strong>
            <div class="small text-muted">from the <?= e($latest['offered_by_side'] === 'recycler' ? 'recycler' : 'hub') ?>, round <?= e($latest['round_no']) ?> of <?= e($maxRounds) ?></div></dd>
        <?php endif; ?>
        <?php if ($o['status'] === 'negotiating' && $o['expires_at']): ?><dt class="col-sm-4">Offer valid until</dt><dd class="col-sm-8"><?= e(format_datetime($o['expires_at'])) ?></dd><?php endif; ?>
        <?php if ($o['end_note'] && $o['status'] !== 'completed'): ?><dt class="col-sm-4">Reason</dt><dd class="col-sm-8"><?= e($o['end_note']) ?></dd><?php endif; ?>
        <?php if ($o['status'] === 'completed' && $o['end_note']): ?><dt class="col-sm-4">Closing note</dt><dd class="col-sm-8"><?= e($o['end_note']) ?></dd><?php endif; ?>
      </dl>
    </div></div>

    <div class="card mb-3"><div class="card-body pb-0"><h2 class="h6">Negotiation history</h2></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>#</th><th>From</th><th class="text-end">Quantity</th><th class="text-end">Price/kg</th><th class="text-end">Total</th><th>Note</th><th>Result</th></tr></thead>
        <tbody>
        <?php foreach ($offers as $f): ?>
          <tr><td><?= e($f['round_no']) ?></td><td class="small"><?= e($f['offered_by_side'] === 'recycler' ? 'Recycler' : 'Hub') ?><div class="text-muted"><?= e($f['by_name']) ?>, <?= e(format_datetime($f['created_at'], 'd M, H:i')) ?></div></td>
            <td class="text-end text-nowrap"><?= e(format_kg($f['qty_kg'])) ?></td><td class="text-end"><?= e(number_format((int) $f['price_per_kg_ugx'])) ?></td><td class="text-end text-nowrap"><?= e(number_format((int) $f['total_ugx'])) ?></td>
            <td class="small"><?= e($f['note'] ?? '') ?><?= $f['delivery_date'] ? '<div class="text-muted">Date: ' . e(date('d M Y', strtotime($f['delivery_date']))) . '</div>' : '' ?></td>
            <td><?= e(ucfirst($f['status'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>

    <?php if ($dispatches): ?>
    <div class="card"><div class="card-body pb-0"><h2 class="h6">Loads dispatched</h2></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Reference</th><th>Sent</th><th class="text-end">Loaded</th><th class="text-end">Received</th><th>Status</th><th>Vehicle</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($dispatches as $d): ?>
          <tr><td class="text-nowrap"><?= can('inventory.view') ? '<a href="' . e(url('/inventory/dispatches/' . $d['id'])) . '">' . e($d['reference']) . '</a>' : e($d['reference']) ?></td>
            <td class="small text-nowrap"><?= e(format_datetime($d['dispatched_at'], 'd M, H:i')) ?></td>
            <td class="text-end text-nowrap"><?= e(format_kg($d['qty_kg'])) ?></td>
            <td class="text-end text-nowrap"><?= $d['received_kg'] !== null ? e(format_kg($d['received_kg'])) : '—' ?></td>
            <td><?= component('status-badge', ['status' => $d['status']]) ?></td><td class="small"><?= e(trim(($d['vehicle'] ?? '') . ' ' . ($d['driver'] ?? '')) ?: '—') ?></td>
            <td class="text-end"><?php if ($act['receive'] && $d['status'] === 'dispatched'): ?>
              <?= $post('receive/' . $d['id'],
                  '<div class="d-flex gap-1"><input class="form-control form-control-sm" style="width:90px" name="received_kg" value="' . e($d['qty_kg']) . '" inputmode="decimal" aria-label="Weight received for ' . e($d['reference']) . '">'
                  . '<input class="form-control form-control-sm" name="note" placeholder="Note if less" aria-label="Note"><button class="btn btn-sm btn-primary" type="submit">Confirm</button></div>') ?>
            <?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-5">
    <?php if ($o['status'] === 'negotiating'): ?>
      <?php if ($act['waiting']): ?>
        <?= component('alert', ['type' => 'info', 'message' => $mine ? 'Waiting for the hub to respond to your offer.' : 'Waiting for the recycler to respond to your counter-offer.']) ?>
      <?php endif; ?>
      <?php if ($act['accept']): ?>
      <div class="card mb-3"><div class="card-body"><h2 class="h6">Accept the latest offer</h2>
        <p class="small text-muted">Accepting locks these terms and reserves the plastic for this order.</p>
        <?= $post('accept', '<button class="btn btn-primary" type="submit"><i class="bi bi-check-circle"></i> Accept ' . e(format_kg($latest['qty_kg'])) . ' at ' . e(format_ugx($latest['price_per_kg_ugx'])) . '/kg</button>', 'Accept this offer? The terms will be locked.') ?>
      </div></div>
      <?php endif; ?>
      <?php if ($act['counter']): ?>
      <div class="card mb-3"><div class="card-body"><h2 class="h6">Make a counter-offer</h2>
        <?= $post('counter',
            component('field', ['name' => 'quantity_kg', 'label' => 'Quantity (kg)', 'required' => true, 'value' => old('quantity_kg', (string) $latest['qty_kg']), 'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off']])
            . component('field', ['name' => 'price_per_kg_ugx', 'label' => 'Price per kg (UGX)', 'required' => true, 'value' => old('price_per_kg_ugx', (string) $latest['price_per_kg_ugx']), 'attrs' => ['inputmode' => 'numeric', 'autocomplete' => 'off']])
            . component('field', ['name' => 'delivery_date', 'label' => 'Delivery or pick-up date', 'type' => 'date', 'value' => old('delivery_date', (string) ($latest['delivery_date'] ?? ''))])
            . component('field', ['name' => 'note', 'label' => 'Note'])
            . '<button class="btn btn-outline-primary" type="submit">Send counter-offer</button>') ?>
      </div></div>
      <?php elseif ($act['accept']): ?>
        <p class="small text-muted">This is the final round (<?= e($maxRounds) ?>), so you can accept or end the negotiation.</p>
      <?php endif; ?>
      <?php if ($act['reject'] || $act['withdraw']): ?>
      <div class="card"><div class="card-body"><h2 class="h6"><?= $act['reject'] ? 'Reject this request' : 'Withdraw your offer' ?></h2>
        <?= $post('end', '<textarea class="form-control mb-2" name="reason" rows="2" ' . ($act['reject'] ? 'required placeholder="Reason (required)"' : 'placeholder="Reason (optional)"') . ' aria-label="Reason"></textarea><button class="btn btn-outline-danger" type="submit">' . ($act['reject'] ? 'Reject' : 'Withdraw') . '</button>') ?>
      </div></div>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($act['schedule']): ?>
    <div class="card mb-3"><div class="card-body"><h2 class="h6">Delivery date</h2>
      <?= $post('schedule',
          component('field', ['name' => 'delivery_date', 'label' => 'Date', 'type' => 'date', 'required' => true, 'value' => old('delivery_date', (string) ($o['scheduled_for'] ?? ''))])
          . component('field', ['name' => 'note', 'label' => 'Note', 'value' => old('note', (string) ($o['schedule_note'] ?? '')), 'hint' => 'For example the time window or who will collect.'])
          . '<button class="btn btn-outline-primary" type="submit">Save date</button>') ?>
    </div></div>
    <?php endif; ?>

    <?php if ($act['dispatch']): ?>
    <div class="card mb-3"><div class="card-body"><h2 class="h6">Dispatch a load</h2>
      <p class="small text-muted">Still to dispatch: <strong><?= e(format_kg(\App\Helpers\Weight::toDecimal($remaining))) ?></strong>. The stock leaves the hub and the reservation shrinks by the same amount.</p>
      <?= $post('dispatch',
          '<input type="hidden" name="token" value="' . e($token) . '">'
          . component('field', ['name' => 'quantity_kg', 'label' => 'Weight loaded (kg)', 'required' => true, 'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off']])
          . component('field', ['name' => 'vehicle', 'label' => 'Vehicle', 'hint' => 'Optional'])
          . component('field', ['name' => 'driver', 'label' => 'Driver', 'hint' => 'Optional'])
          . '<button class="btn btn-primary" type="submit"><i class="bi bi-truck"></i> Dispatch</button>') ?>
    </div></div>
    <?php endif; ?>

    <?php if ($act['close']): ?>
    <div class="card mb-3"><div class="card-body"><h2 class="h6">Close the order short</h2>
      <p class="small text-muted">Use when the rest will not be delivered. The undelivered <?= e(format_kg(\App\Helpers\Weight::toDecimal($remaining))) ?> becomes available again.</p>
      <?= $post('close', '<textarea class="form-control mb-2" name="note" rows="2" required placeholder="Why (required)" aria-label="Why"></textarea><button class="btn btn-outline-danger" type="submit">Close order short</button>', 'Close this order short? The remaining plastic will be released.') ?>
    </div></div>
    <?php endif; ?>

    <?php if ($act['cancel']): ?>
    <div class="card"><div class="card-body"><h2 class="h6">Cancel this order</h2>
      <p class="small text-muted">Only possible before anything is dispatched. The reserved plastic becomes available again.</p>
      <?= $post('cancel', '<textarea class="form-control mb-2" name="reason" rows="2" required placeholder="Reason (required)" aria-label="Reason"></textarea><button class="btn btn-outline-danger" type="submit">Cancel order</button>', 'Cancel this order?') ?>
    </div></div>
    <?php endif; ?>
  </div>
</div>
