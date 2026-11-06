<?php /** @var array $pickers @var array $recyclers */
$quick = static function (string $kind, int $id, string $name): string {
    return '<form method="post" action="' . e(url("/$kind/$id/decision")) . '" class="m-0">' . csrf_field()
        . '<input type="hidden" name="decision" value="approve"><input type="hidden" name="back" value="/approvals">'
        . '<button class="btn btn-sm btn-primary" type="submit" aria-label="Approve ' . e($name) . '">Approve</button></form>';
};
?>
<?php if (can('pickers.manage')): ?>
<div class="card mb-4">
  <div class="card-body pb-0"><h2 class="h6">Waste picker registrations waiting for review (<?= count($pickers) ?>)</h2></div>
  <?php if (!$pickers): ?><?= component('empty-state', ['icon' => 'check2-all', 'title' => 'Nothing waiting', 'text' => 'All picker registrations have been reviewed.']) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Name</th><th>Neighbourhood</th><th>Hub</th><th>Registered</th><th class="text-end">Action</th></tr></thead>
    <tbody>
    <?php foreach ($pickers as $p): ?>
      <tr>
        <td><a class="fw-semibold" href="<?= e(url('/pickers/' . $p['id'])) ?>"><?= e($p['full_name']) ?></a></td>
        <td><?= e($p['neighbourhood']) ?></td><td><?= e($p['hub_name'] ?? '—') ?></td>
        <td class="small text-nowrap"><?= e(format_datetime($p['account_created'])) ?></td>
        <td class="text-end"><div class="d-flex gap-1 justify-content-end">
          <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/pickers/' . $p['id'])) ?>">Review</a><?= $quick('pickers', (int) $p['id'], $p['full_name']) ?></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (can('recyclers.approve')): ?>
<div class="card">
  <div class="card-body pb-0"><h2 class="h6">Recycler registrations waiting for review (<?= count($recyclers) ?>)</h2></div>
  <?php if (!$recyclers): ?><?= component('empty-state', ['icon' => 'check2-all', 'title' => 'Nothing waiting', 'text' => 'All recycler registrations have been reviewed.']) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Company</th><th>Contact</th><th>Registered</th><th class="text-end">Action</th></tr></thead>
    <tbody>
    <?php foreach ($recyclers as $r): ?>
      <tr>
        <td><a class="fw-semibold" href="<?= e(url('/recyclers/' . $r['id'])) ?>"><?= e($r['business_name']) ?></a></td>
        <td><?= e($r['contact_person']) ?></td>
        <td class="small text-nowrap"><?= e(format_datetime($r['created_at'])) ?></td>
        <td class="text-end"><div class="d-flex gap-1 justify-content-end">
          <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/recyclers/' . $r['id'])) ?>">Review</a><?= $quick('recyclers', (int) $r['id'], $r['business_name']) ?></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>
