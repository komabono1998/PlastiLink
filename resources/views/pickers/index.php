<?php /** @var array $pickers @var array $pg @var array $f @var array $neighbourhoods @var array $hubs */ ?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-start mb-3">
  <form class="row g-2 flex-grow-1" method="get" action="<?= e(url('/pickers')) ?>">
    <div class="col-12 col-md-3"><label class="visually-hidden" for="q">Search</label>
      <input class="form-control" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Name, picker ID or phone"></div>
    <div class="col-6 col-md-2"><label class="visually-hidden" for="status">Status</label>
      <select class="form-select" id="status" name="status"><option value="">Any status</option>
        <?php foreach (['active', 'pending', 'suspended'] as $s): ?><option value="<?= e($s) ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-3"><label class="visually-hidden" for="neighbourhood_id">Neighbourhood</label>
      <select class="form-select" id="neighbourhood_id" name="neighbourhood_id"><option value="">All neighbourhoods</option>
        <?php foreach ($neighbourhoods as $id => $n): ?><option value="<?= e($id) ?>"<?= (string) $f['neighbourhood_id'] === (string) $id ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="visually-hidden" for="hub_id">Hub</label>
      <select class="form-select" id="hub_id" name="hub_id"><option value="">All hubs</option>
        <?php foreach ($hubs as $id => $n): ?><option value="<?= e($id) ?>"<?= (string) $f['hub_id'] === (string) $id ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-search"></i> Filter</button></div>
  </form>
  <?php if (can('pickers.register')): ?><a class="btn btn-primary" href="<?= e(url('/pickers/register')) ?>"><i class="bi bi-person-plus"></i> Register picker</a><?php endif; ?>
</div>

<div class="card">
  <?php if (!$pickers): ?>
    <?= component('empty-state', ['icon' => 'person-badge', 'title' => 'No waste pickers found', 'text' => 'Registered pickers will appear here.']) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <?php
    $manage = can('pickers.manage');
    $qs = http_build_query(array_filter($f + ['page' => $pg['page'] ?? 1], static fn($x) => $x !== '' && $x !== null));
    $back = $qs !== '' ? '/pickers?' . $qs : '/pickers';
    $statusBtn = static function (array $p, string $decision, string $label, string $cls, string $confirm = '') use ($back): string {
        return '<form method="post" action="' . e(url('/pickers/' . $p['id'] . '/decision')) . '" class="d-inline"'
            . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field()
            . '<input type="hidden" name="decision" value="' . e($decision) . '"><input type="hidden" name="back" value="' . e($back) . '">'
            . '<button class="btn btn-sm ' . $cls . '" type="submit">' . $label . '</button></form>';
    };
    ?>
    <thead><tr><th>Picker ID</th><th>Name</th><th>Phone</th><th>Neighbourhood</th><th>Hub</th><th>Status</th><th>Verified</th><?php if ($manage): ?><th class="text-end th-actions">Actions</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($pickers as $p): ?>
      <tr>
        <td class="text-nowrap"><?= e($p['picker_code']) ?></td>
        <td><a class="fw-semibold" href="<?= e(url('/pickers/' . $p['id'])) ?>"><?= e($p['full_name']) ?></a></td>
        <td class="text-nowrap small"><?= e(display_phone($p['phone_e164'])) ?></td>
        <td><?= e($p['neighbourhood']) ?></td>
        <td><?= e($p['hub_name'] ?? '—') ?></td>
        <td><?= component('status-badge', ['status' => $p['account_status']]) ?></td>
        <td><?= component('status-badge', ['status' => $p['verification_status']]) ?></td>
        <?php if ($manage): ?>
        <td class="text-end text-nowrap row-actions"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/pickers/' . $p['id'] . '/edit')) ?>"><i class="bi bi-pencil-square"></i> Edit</a>
          <?php if ($p['account_status'] === 'pending'): ?><?= $statusBtn($p, 'approve', 'Approve', 'btn-primary') ?>
          <?php elseif ($p['account_status'] === 'active'): ?><?= $statusBtn($p, 'suspend', 'Suspend', 'btn-outline-danger', 'Suspend ' . $p['full_name'] . '? They will be signed out.') ?>
          <?php elseif ($p['account_status'] === 'suspended'): ?><?= $statusBtn($p, 'reactivate', 'Reactivate', 'btn-outline-primary') ?><?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= component('pagination', ['p' => $pg]) ?>
  <?php endif; ?>
</div>
