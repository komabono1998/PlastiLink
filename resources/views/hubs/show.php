<?php /** @var array $hub @var array $staff @var array $candidates */ ?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card"><div class="card-body">
      <div class="d-flex justify-content-between gap-2">
        <div><h2 class="h5 mb-0"><?= e($hub['name']) ?></h2><div class="text-muted small"><?= e($hub['code']) ?></div></div>
        <?= component('status-badge', ['status' => $hub['status']]) ?>
      </div>
      <dl class="row mt-3 mb-0 small">
        <dt class="col-5">Neighbourhood</dt><dd class="col-7"><?= e($hub['neighbourhood']) ?></dd>
        <dt class="col-5">Address note</dt><dd class="col-7"><?= e($hub['address_note'] ?? '—') ?></dd>
        <dt class="col-5">Coordinates</dt><dd class="col-7"><?= $hub['latitude'] !== null ? e($hub['latitude'] . ', ' . $hub['longitude']) : '—' ?></dd>
      </dl>
      <?php if (can('hubs.manage')): ?>
        <hr><a class="btn btn-outline-primary" href="<?= e(url('/hubs/' . $hub['id'] . '/edit')) ?>"><i class="bi bi-pencil"></i> Edit hub</a>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="col-lg-7">
    <div class="card"><div class="card-body">
      <h2 class="h6">Hub staff</h2>
      <?php if (!$staff): ?>
        <?= component('empty-state', ['icon' => 'person-plus', 'title' => 'No staff assigned', 'text' => 'Assign a Hub Manager and operators below.']) ?>
      <?php else: ?>
        <div class="table-responsive"><table class="table align-middle">
          <thead><tr><th>Name</th><th>Role</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($staff as $s): ?>
            <tr>
              <td><?= can('users.view') ? '<a href="' . e(url('/users/' . $s['user_id'])) . '">' . e($s['full_name']) . '</a>' : e($s['full_name']) ?></td>
              <td><?= e(ucfirst($s['hub_role'])) ?></td>
              <td><?= component('status-badge', ['status' => $s['status']]) ?></td>
              <td class="text-end">
                <?php if (can('hubs.manage')): ?>
                <form method="post" action="<?= e(url('/hubs/' . $hub['id'] . '/unassign')) ?>" class="m-0" onsubmit="return confirm('Remove this person from the hub?');">
                  <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= e($s['user_id']) ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>

      <?php if (can('hubs.manage')): ?>
      <hr>
      <h3 class="h6">Assign staff</h3>
      <?php if (!$candidates): ?>
        <p class="small text-muted mb-0">No unassigned active Hub Managers or Hub Operators. <a href="<?= e(url('/users/create')) ?>">Add staff</a> first.</p>
      <?php else: ?>
        <form method="post" action="<?= e(url('/hubs/' . $hub['id'] . '/assign')) ?>" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <div class="col-sm-4"><label class="form-label" for="hub_role">As</label>
            <select class="form-select" id="hub_role" name="hub_role">
              <?php foreach (['manager' => 'Hub Manager', 'operator' => 'Hub Operator'] as $k => $l): if (!empty($candidates[$k])): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endif; endforeach; ?>
            </select></div>
          <div class="col-sm-5"><label class="form-label" for="user_id">Person</label>
            <select class="form-select" id="user_id" name="user_id">
              <?php foreach ($candidates as $k => $list): foreach ($list as $uid => $name): ?><option value="<?= e($uid) ?>"><?= e($name) ?> (<?= e($k) ?>)</option><?php endforeach; endforeach; ?>
            </select></div>
          <div class="col-sm-3"><button class="btn btn-primary w-100" type="submit">Assign</button></div>
        </form>
        <p class="form-text">The person's role must match (Hub Managers as manager, Hub Operators as operator).</p>
      <?php endif; endif; ?>
    </div></div>
  </div>
</div>
