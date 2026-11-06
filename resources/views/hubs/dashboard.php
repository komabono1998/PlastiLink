<?php /** @var array $myHubs @var int $pickersTotal @var int $pickersPending */ ?>
<?= component('welcome', [
    'user' => auth_user(), 'blurb' => 'Hub operations',
    'coming' => ['Recording and weighing collections (Phase 4)', 'Hub stock and daily summaries (Phase 5)'],
]) ?>

<div class="row g-3 mt-1 mb-4">
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'shop', 'value' => (string) count($myHubs), 'label' => 'My hubs']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'person-badge', 'value' => (string) $pickersTotal, 'label' => 'Pickers at my hubs']) ?></div>
  <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'hourglass-split', 'value' => (string) $pickersPending, 'label' => 'Waiting for approval']) ?></div>
  <?php if (can('pickers.register')): ?>
  <div class="col-6 col-lg-3 d-flex"><a class="btn btn-primary w-100 align-self-stretch" href="<?= e(url('/pickers/register')) ?>"><i class="bi bi-person-plus"></i> Register a picker</a></div>
  <?php endif; ?>
</div>

<?php if (!$myHubs): ?>
  <?= component('alert', ['type' => 'warning', 'message' => 'You are not assigned to a hub yet. Ask the administrator to assign you.']) ?>
<?php else: ?>
  <div class="card"><div class="card-body">
    <h2 class="h6">My hubs</h2>
    <ul class="mb-0">
      <?php foreach ($myHubs as $h): ?><li><a href="<?= e(url('/hubs/' . $h['id'])) ?>"><?= e($h['name']) ?></a> · <?= e($h['neighbourhood']) ?></li><?php endforeach; ?>
    </ul>
  </div></div>
<?php endif; ?>
