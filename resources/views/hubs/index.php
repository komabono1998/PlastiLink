<?php /** @var array $hubs */ ?>
<div class="d-flex justify-content-end mb-3">
  <?php if (can('hubs.manage')): ?><a class="btn btn-primary" href="<?= e(url('/hubs/create')) ?>"><i class="bi bi-plus-lg"></i> Register a hub</a><?php endif; ?>
</div>
<div class="card">
  <?php if (!$hubs): ?>
    <?= component('empty-state', ['icon' => 'shop', 'title' => 'No hubs to show', 'text' => can('hubs.manage') ? 'Register the first collection hub.' : 'You have not been assigned to a hub yet. Ask the administrator.']) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Code</th><th>Hub</th><th>Neighbourhood</th><th class="text-end">Staff</th><th class="text-end">Pickers</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($hubs as $h): ?>
      <tr>
        <td class="text-muted"><?= e($h['code']) ?></td>
        <td><a class="fw-semibold" href="<?= e(url('/hubs/' . $h['id'])) ?>"><?= e($h['name']) ?></a></td>
        <td><?= e($h['neighbourhood']) ?></td>
        <td class="text-end"><?= e($h['staff']) ?></td>
        <td class="text-end"><?= e($h['pickers']) ?></td>
        <td><?= component('status-badge', ['status' => $h['status']]) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
