<?php /** @var array $rows @var array $pg @var array $f @var array $hubs @var bool $isPicker @var array $summary */ ?>
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'box-seam', 'value' => format_kg($summary['kg']), 'label' => 'Approved weight']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'cash-coin', 'value' => format_ugx($summary['value']), 'label' => $isPicker ? 'Value (not yet paid)' : 'Approved value']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'check2-circle', 'value' => (string) $summary['approved'], 'label' => 'Approved collections']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'hourglass-split', 'value' => (string) $summary['pending'], 'label' => 'Waiting for approval']) ?></div>
</div>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-start mb-3">
  <form class="row g-2 flex-grow-1" method="get" action="<?= e(url('/collections')) ?>">
    <div class="col-12 col-md-3"><label class="visually-hidden" for="q">Search</label>
      <input class="form-control" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Reference<?= $isPicker ? '' : ', picker or ID' ?>"></div>
    <div class="col-6 col-md-2"><label class="visually-hidden" for="status">Status</label>
      <select class="form-select" id="status" name="status"><option value="">Any status</option>
        <?php foreach (['pending', 'approved', 'rejected', 'reversed'] as $s): ?><option value="<?= e($s) ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select></div>
    <?php if (!$isPicker): ?>
    <div class="col-6 col-md-2"><label class="visually-hidden" for="hub_id">Hub</label>
      <select class="form-select" id="hub_id" name="hub_id"><option value="">All hubs</option>
        <?php foreach ($hubs as $id => $n): ?><option value="<?= e($id) ?>"<?= (string) $f['hub_id'] === (string) $id ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="col-6 col-md-2"><label class="visually-hidden" for="from">From</label><input class="form-control" id="from" name="from" type="date" value="<?= e($f['from_raw']) ?>" title="From date"></div>
    <div class="col-6 col-md-2"><label class="visually-hidden" for="to">To</label><input class="form-control" id="to" name="to" type="date" value="<?= e($f['to_raw']) ?>" title="To date"></div>
    <div class="col-12 col-md-1"><button class="btn btn-outline-primary w-100" type="submit" aria-label="Filter"><i class="bi bi-search"></i></button></div>
  </form>
  <?php if (can('collections.record')): ?><a class="btn btn-primary" href="<?= e(url('/collections/new')) ?>"><i class="bi bi-plus-lg"></i> Record collection</a><?php endif; ?>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <?= component('empty-state', ['icon' => 'box-seam', 'title' => 'No collections found', 'text' => $isPicker ? 'Your collections will appear here after you deliver plastic to a hub.' : 'Recorded collections will appear here.']) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Reference</th><th>Date</th><?php if (!$isPicker): ?><th>Picker</th><?php endif; ?><th>Hub</th><th class="text-end">Net weight</th><th class="text-end">Value</th><th>Status</th><?php if (!$isPicker): ?><th class="text-end th-actions">Actions</th><?php endif; ?></tr></thead>
    <tbody>
    <?php $me = (int) (auth_user()['id'] ?? 0); foreach ($rows as $c): ?>
      <tr>
        <td class="text-nowrap"><a class="fw-semibold" href="<?= e(url('/collections/' . $c['id'])) ?>"><?= e($c['reference']) ?></a></td>
        <td class="small text-nowrap"><?= e(format_datetime($c['recorded_at'], 'd M Y, H:i')) ?></td>
        <?php if (!$isPicker): ?><td><?= e($c['picker_name']) ?><div class="small text-muted"><?= e($c['picker_code']) ?></div></td><?php endif; ?>
        <td><?= e($c['hub_name']) ?></td>
        <td class="text-end text-nowrap"><?= e(format_kg($c['net_weight_kg'])) ?></td>
        <td class="text-end text-nowrap"><?= e(format_ugx($c['total_value_ugx'])) ?></td>
        <td><?= component('status-badge', ['status' => $c['status']]) ?></td>
        <?php if (!$isPicker):
            $acts = [];
            if ($c['status'] === 'pending' && can('collections.approve')) {
                if ((int) $c['recorded_by'] !== $me) { // the person who recorded it can never approve it
                    $acts[] = ['label' => 'Approve', 'icon' => 'check-circle', 'class' => 'btn-primary', 'post' => '/collections/' . $c['id'] . '/approve', 'confirm' => 'Approve ' . $c['reference'] . ' and issue the receipt?'];
                }
                $acts[] = ['label' => 'Review', 'href' => '/collections/' . $c['id']];
            } elseif ($c['status'] === 'approved' && can('collections.reverse')) {
                $acts[] = ['label' => 'Reverse', 'icon' => 'arrow-counterclockwise', 'class' => 'btn-outline-danger', 'href' => '/collections/' . $c['id']];
            } else {
                $acts[] = ['label' => 'Open', 'href' => '/collections/' . $c['id']];
            } ?>
        <td class="text-end text-nowrap"><?= component('row-actions', ['actions' => $acts]) ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= component('pagination', ['p' => $pg]) ?>
  <?php endif; ?>
</div>
