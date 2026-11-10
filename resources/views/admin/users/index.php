<?php /** @var array $users @var array $pg @var array $f @var array $roles */ ?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <form class="row g-2 flex-grow-1" method="get" action="<?= e(url('/users')) ?>">
    <div class="col-12 col-md-4"><label class="visually-hidden" for="q">Search</label>
      <input class="form-control" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Search name, email or phone"></div>
    <div class="col-6 col-md-3"><label class="visually-hidden" for="role">Role</label>
      <select class="form-select" id="role" name="role"><option value="">All roles</option>
        <?php foreach ($roles as $slug => $name): ?><option value="<?= e($slug) ?>"<?= $f['role'] === $slug ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-6 col-md-3"><label class="visually-hidden" for="status">Status</label>
      <select class="form-select" id="status" name="status"><option value="">All statuses</option>
        <?php foreach (['active', 'pending', 'suspended'] as $s): ?><option value="<?= e($s) ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-12 col-md-2"><button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-search"></i> Filter</button></div>
  </form>
  <?php if (can('users.manage')): ?>
    <a class="btn btn-primary" href="<?= e(url('/users/create')) ?>"><i class="bi bi-plus-lg"></i> Add staff</a>
  <?php endif; ?>
</div>

<div class="card">
  <?php if (!$users): ?>
    <?= component('empty-state', ['icon' => 'people', 'title' => 'No users match', 'text' => 'Try a different search or filter.']) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Name</th><th>Role</th><th>Contact</th><th>Status</th><th>Last sign-in</th><?php if (can('users.manage')): ?><th class="text-end">Actions</th><?php endif; ?></tr></thead>
    <tbody>
    <?php $me = (int) (auth_user()['id'] ?? 0); foreach ($users as $u): ?>
      <tr>
        <td><a class="fw-semibold" href="<?= e(url('/users/' . $u['id'])) ?>"><?= e($u['full_name']) ?></a></td>
        <td><?= e($u['role_name']) ?></td>
        <td class="small"><?= e($u['email'] ?? '') ?><?= ($u['email'] && $u['phone_e164']) ? '<br>' : '' ?><?= e($u['phone_e164'] ? display_phone($u['phone_e164']) : '') ?></td>
        <td><?= component('status-badge', ['status' => $u['status']]) ?></td>
        <td class="small text-nowrap"><?= e(format_datetime($u['last_login_at'])) ?></td>
        <?php if (can('users.manage')):
            $acts = [['label' => 'Edit', 'icon' => 'pencil', 'href' => '/users/' . $u['id'] . '/edit']];
            // Picker and recycler accounts are approved or suspended from their own screens
            $profileRole = in_array($u['role_slug'] ?? '', ['waste_picker', 'recycler'], true);
            if ((int) $u['id'] !== $me && !$profileRole) {
                if ($u['status'] === 'active') {
                    $acts[] = ['label' => 'Suspend', 'icon' => 'slash-circle', 'class' => 'btn-outline-danger', 'post' => '/users/' . $u['id'] . '/status', 'fields' => ['status' => 'suspended'],
                        'confirm' => 'Suspend ' . $u['full_name'] . '? They will be signed out on their next request.'];
                } else {
                    $acts[] = ['label' => $u['status'] === 'pending' ? 'Approve' : 'Reactivate', 'icon' => 'check-circle', 'class' => 'btn-primary', 'post' => '/users/' . $u['id'] . '/status', 'fields' => ['status' => 'active']];
                }
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
