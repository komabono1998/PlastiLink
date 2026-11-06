<?php /** @var array $rows @var array $pg @var array $f @var bool $isPicker */ ?>
<form class="row g-2 mb-3" method="get" action="<?= e(url('/payouts')) ?>">
  <div class="col-12 col-md-4"><label class="visually-hidden" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Reference<?= $isPicker ? '' : ', picker or ID' ?>"></div>
  <div class="col-8 col-md-4"><label class="visually-hidden" for="status">Status</label>
    <select class="form-select" id="status" name="status"><option value="">Any status</option>
      <?php foreach (['pending_approval', 'approved', 'paid', 'rejected', 'cancelled'] as $s): ?><option value="<?= e($s) ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?></select></div>
  <div class="col-4 col-md-2"><button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-search"></i></button></div>
</form>
<div class="card">
  <?php if (!$rows): ?><?= component('empty-state', ['icon' => 'cash-stack', 'title' => 'No payout requests']) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Reference</th><?php if (!$isPicker): ?><th>Picker</th><?php endif; ?><th class="text-end">Amount</th><th>Method</th><th>Requested</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $q): ?>
      <tr><td class="text-nowrap"><a class="fw-semibold" href="<?= e(url('/payouts/' . $q['id'])) ?>"><?= e($q['reference']) ?></a></td>
        <?php if (!$isPicker): ?><td><?= e($q['picker_name']) ?><div class="small text-muted"><?= e($q['picker_code']) ?></div></td><?php endif; ?>
        <td class="text-end text-nowrap"><?= e(format_ugx($q['amount_ugx'])) ?></td><td><?= e($q['method'] === 'cash' ? 'Cash' : 'Mobile money') ?></td>
        <td class="small text-nowrap"><?= e(format_datetime($q['requested_at'], 'd M, H:i')) ?></td><td><?= component('status-badge', ['status' => $q['status']]) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= component('pagination', ['p' => $pg]) ?>
  <?php endif; ?>
</div>
