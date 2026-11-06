<?php /** @var string $temp @var string $who */ ?>
<div class="alert alert-warning" role="alert">
  <div class="fw-bold mb-1"><i class="bi bi-key" aria-hidden="true"></i> Temporary password for <?= e($who) ?></div>
  <div class="fs-4 font-monospace user-select-all"><?= e($temp) ?></div>
  <div class="small mt-1">Shown <strong>once</strong>. Write it down and give it to the person now. They must choose a new password at first sign-in. If you leave this page it cannot be shown again (you can create a new one).</div>
</div>
