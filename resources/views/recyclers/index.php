<?php /** @var array $recyclers @var array $pg @var array $f */ ?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-start mb-3">
  <form class="row g-2 flex-grow-1" method="get" action="<?= e(url('/recyclers')) ?>">
    <div class="col-12 col-md-5"><label class="visually-hidden" for="q">Search</label>
      <input class="form-control" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Company, contact or email"></div>
    <div class="col-6 col-md-4"><label class="visually-hidden" for="status">Status</label>
      <select class="form-select" id="status" name="status"><option value="">Any status</option>
        <?php foreach (['pending', 'approved', 'rejected', 'suspended'] as $s): ?><option value="<?= e($s) ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-3"><button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-search"></i> Filter</button></div>
  </form>
  <?php if (can('recyclers.manage')): ?><a class="btn btn-primary" href="<?= e(url('/recyclers/create')) ?>"><i class="bi bi-plus-lg"></i> Register recycler</a><?php endif; ?>
</div>
<div class="card">
  <?php if (!$recyclers): ?>
    <?= component('empty-state', ['icon' => 'building', 'title' => 'No recyclers found']) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Company</th><th>Contact</th><th>Email</th><th>Status</th><th>Registered</th></tr></thead>
    <tbody>
    <?php foreach ($recyclers as $r): ?>
      <tr>
        <td><a class="fw-semibold" href="<?= e(url('/recyclers/' . $r['id'])) ?>"><?= e($r['business_name']) ?></a></td>
        <td><?= e($r['contact_person']) ?></td>
        <td class="small"><?= e($r['email']) ?></td>
        <td><?= component('status-badge', ['status' => $r['status']]) ?></td>
        <td class="small text-nowrap"><?= e(format_datetime($r['created_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= component('pagination', ['p' => $pg]) ?>
  <?php endif; ?>
</div>
