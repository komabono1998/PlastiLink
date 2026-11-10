<?php /** @var array $i @var array $payments @var int $outstanding @var bool $overdue @var bool $canRecord @var bool $canVoid @var string $token @var string $role */ ?>
<div class="d-flex justify-content-end mb-2 no-print"><button class="btn btn-outline-primary" type="button" data-print><i class="bi bi-printer"></i> Print</button></div>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-body">
      <div class="text-center mb-3 d-none d-print-block"><strong>PlastiLink · Invoice</strong></div>
      <div class="d-flex justify-content-between gap-2 flex-wrap"><div><h2 class="h5 mb-0"><?= e($i['invoice_number']) ?></h2><div class="small text-muted">Order <?= e($i['order_reference']) ?></div></div>
        <div><?= component('status-badge', ['status' => $i['status']]) ?><?= $overdue ? ' ' . component('status-badge', ['status' => 'overdue']) : '' ?></div></div>
      <dl class="row mt-3 mb-0">
        <dt class="col-sm-4">Bill to</dt><dd class="col-sm-8"><?= e($i['business_name']) ?></dd>
        <dt class="col-sm-4">Supplied from</dt><dd class="col-sm-8"><?= e($i['hub_name']) ?></dd>
        <dt class="col-sm-4">Issued</dt><dd class="col-sm-8"><?= e(format_datetime($i['issued_at'], 'd M Y')) ?></dd>
        <dt class="col-sm-4">Due</dt><dd class="col-sm-8"><?= e(date('d M Y', strtotime($i['due_date']))) ?></dd>
      </dl>
      <table class="table table-sm mt-3 mb-0"><tbody>
        <tr><td>Plastic received and confirmed</td><td class="text-end"><?= e(format_kg($i['received_kg'])) ?></td></tr>
        <tr><td>Agreed price per kg</td><td class="text-end"><?= e(format_ugx($i['price_per_kg_ugx'])) ?></td></tr>
        <tr class="table-light"><td class="fw-bold">Invoice total</td><td class="text-end fw-bold"><?= e(format_ugx($i['amount_ugx'])) ?></td></tr>
        <tr><td>Paid (confirmed)</td><td class="text-end text-success"><?= e(format_ugx($i['paid_ugx'])) ?></td></tr>
        <tr><td class="fw-bold">Outstanding</td><td class="text-end fw-bold <?= $overdue ? 'text-danger' : '' ?>"><?= e(format_ugx($outstanding)) ?></td></tr>
      </tbody></table>
      <?php if ($i['status'] === 'void'): ?><div class="alert alert-secondary mt-3 mb-0">Voided <?= e(format_datetime($i['voided_at'])) ?>: <?= e($i['void_reason']) ?></div><?php endif; ?>
    </div></div>

    <div class="card no-print"><div class="card-body pb-0"><h2 class="h6">Payments</h2></div>
      <?php if (!$payments): ?><?= component('empty-state', ['icon' => 'credit-card', 'title' => 'No payments recorded']) ?>
      <?php else: ?>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Reference</th><th>Date</th><th class="text-end">Amount</th><th>Method</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($payments as $p): ?>
          <tr><td><a href="<?= e(url('/payments/' . $p['id'])) ?>"><?= e($p['reference']) ?></a></td><td class="small"><?= $p['received_on'] ? e(date('d M Y', strtotime($p['received_on']))) : '' ?></td>
            <td class="text-end text-nowrap"><?= e(format_ugx($p['amount_ugx'])) ?></td><td><?= e(ucfirst(str_replace('_', ' ', $p['method']))) ?></td><td><?= component('status-badge', ['status' => $p['status']]) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-5 no-print">
    <?php if ($canRecord): ?>
    <div class="card mb-3"><div class="card-body"><h2 class="h6">Record money received</h2>
      <p class="small text-muted">It counts only after a different person confirms it.</p>
      <form method="post" action="<?= e(url('/invoices/' . $i['id'] . '/payment')) ?>" data-loading novalidate>
        <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
        <?= component('field', ['name' => 'amount_ugx', 'label' => 'Amount (UGX)', 'required' => true, 'attrs' => ['inputmode' => 'numeric', 'autocomplete' => 'off']]) ?>
        <?= component('field', ['name' => 'method', 'label' => 'Method', 'type' => 'select', 'required' => true, 'options' => ['' => 'Choose…', 'mobile_money' => 'Mobile money', 'bank' => 'Bank transfer', 'cash' => 'Cash']]) ?>
        <?= component('field', ['name' => 'provider_reference', 'label' => 'Transaction / bank reference', 'hint' => 'Required for mobile money and bank.', 'attrs' => ['autocomplete' => 'off']]) ?>
        <?= component('field', ['name' => 'received_on', 'label' => 'Date received', 'type' => 'date', 'required' => true, 'value' => old('received_on', \App\Helpers\Dates::todayYmd())]) ?>
        <button class="btn btn-primary" type="submit">Record payment</button>
      </form></div></div>
    <?php endif; ?>
    <?php if ($canVoid): ?>
    <div class="card"><div class="card-body"><h2 class="h6">Void this invoice</h2>
      <p class="small text-muted">Only while nothing has been paid. The reason is audited.</p>
      <form method="post" action="<?= e(url('/invoices/' . $i['id'] . '/void')) ?>" data-confirm="Void this invoice?"><?= csrf_field() ?>
        <textarea class="form-control mb-2" name="reason" rows="2" placeholder="Reason (required, at least 5 characters)" required aria-label="Reason for voiding"></textarea><button class="btn btn-outline-danger" type="submit">Void invoice</button></form></div></div>
    <?php endif; ?>
  </div>
</div>
