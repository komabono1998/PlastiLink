<?php /** @var array $rows */ ?>
<div class="card">
  <div class="card-body pb-0"><h2 class="h6">Latest 100 events</h2></div>
  <?php if (!$rows): ?>
    <?= component('empty-state', ['icon' => 'journal-text', 'title' => 'No audit events yet']) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>When (EAT)</th><th>Action</th><th>User</th><th>IP</th><th>Details</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="text-nowrap"><?= e(format_datetime($r['created_at'], 'd M Y, H:i:s')) ?></td>
        <td><span class="badge badge-soft"><?= e($r['action']) ?></span></td>
        <td><?= e($r['full_name'] ?? '—') ?></td>
        <td class="text-nowrap"><?= e($r['ip_address']) ?></td>
        <td class="small text-muted"><?= e($r['details'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
