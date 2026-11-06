<?php /** @var array $rows @var array $pg @var array $f @var bool $mine */ ?>
<form class="row g-2 mb-3" method="get" action="<?= e(url('/invoices')) ?>">
  <div class="col-12 col-md-4"><label class="visually-hidden" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Invoice, order<?= $mine ? '' : ' or company' ?>"></div>
  <div class="col-8 col-md-4"><label class="visually-hidden" for="status">Status</label>
    <select class="form-select" id="status" name="status"><option value="">Any status</option>
      <?php foreach (['issued', 'partially_paid', 'paid', 'overdue', 'void'] as $s): ?><option value="<?= e($s) ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?></select></div>
  <div class="col-4 col-md-2"><button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-search"></i></button></div>
</form>
<div class="card">
  <?php if (!$rows): ?><?= component('empty-state', ['icon' => 'file-earmark-text', 'title' => 'No invoices yet', 'text' => 'An invoice is issued automatically when an order is complete.']) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Invoice</th><?php if (!$mine): ?><th>Recycler</th><?php endif; ?><th>Order</th><th class="text-end">Amount</th><th class="text-end">Outstanding</th><th>Due</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $i): $out = \App\Services\InvoiceService::outstanding($i); $od = \App\Services\InvoiceService::isOverdue($i); ?>
      <tr><td class="text-nowrap"><a class="fw-semibold" href="<?= e(url('/invoices/' . $i['id'])) ?>"><?= e($i['invoice_number']) ?></a></td>
        <?php if (!$mine): ?><td><?= e($i['business_name']) ?></td><?php endif; ?>
        <td class="small"><?= e($i['order_reference']) ?></td><td class="text-end text-nowrap"><?= e(format_ugx($i['amount_ugx'])) ?></td><td class="text-end text-nowrap"><?= e(format_ugx($out)) ?></td>
        <td class="small text-nowrap"><?= e(date('d M Y', strtotime($i['due_date']))) ?></td>
        <td><?= component('status-badge', ['status' => $i['status']]) ?><?= $od ? ' ' . component('status-badge', ['status' => 'overdue']) : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= component('pagination', ['p' => $pg]) ?>
  <?php endif; ?>
</div>
