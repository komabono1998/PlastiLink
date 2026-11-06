<?php /** @var array $items */ ?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card"><div class="card-body">
      <h2 class="h6">Add a neighbourhood</h2>
      <form method="post" action="<?= e(url('/neighbourhoods')) ?>" novalidate>
        <?= csrf_field() ?>
        <?= component('field', ['name' => 'name', 'label' => 'Name', 'required' => true]) ?>
        <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg"></i> Add</button>
      </form>
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card">
      <div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th>Name</th><th class="text-end">Hubs</th><th class="text-end">Pickers</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($items as $n): ?>
          <tr>
            <td>
              <form method="post" action="<?= e(url('/neighbourhoods/' . $n['id'])) ?>" class="d-flex gap-2 m-0">
                <?= csrf_field() ?>
                <label class="visually-hidden" for="n<?= e($n['id']) ?>">Name</label>
                <input class="form-control form-control-sm" id="n<?= e($n['id']) ?>" name="name" value="<?= e($n['name']) ?>" maxlength="100">
                <button class="btn btn-sm btn-outline-primary" type="submit">Rename</button>
              </form>
            </td>
            <td class="text-end"><?= e($n['hubs']) ?></td>
            <td class="text-end"><?= e($n['pickers']) ?></td>
            <td><?= component('status-badge', ['status' => $n['is_active'] ? 'active' : 'inactive']) ?></td>
            <td class="text-end">
              <form method="post" action="<?= e(url('/neighbourhoods/' . $n['id'])) ?>" class="m-0">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle">
                <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $n['is_active'] ? 'Deactivate' : 'Activate' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>
