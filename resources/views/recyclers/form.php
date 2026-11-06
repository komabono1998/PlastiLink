<div class="card" style="max-width:640px"><div class="card-body">
  <p class="text-muted">Register a recycling company. It is approved straight away, and a temporary password is shown once after saving.</p>
  <form method="post" action="<?= e(url('/recyclers')) ?>" data-loading novalidate>
    <?= csrf_field() ?>
    <?= component('field', ['name' => 'business_name', 'label' => 'Business name', 'required' => true]) ?>
    <?= component('field', ['name' => 'registration_number', 'label' => 'Business registration number', 'hint' => 'Optional']) ?>
    <?= component('field', ['name' => 'contact_person', 'label' => 'Contact person', 'required' => true]) ?>
    <?= component('field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true]) ?>
    <?= component('field', ['name' => 'phone', 'label' => 'Phone number', 'type' => 'tel', 'required' => true, 'attrs' => ['inputmode' => 'tel']]) ?>
    <?= component('field', ['name' => 'address', 'label' => 'Business address', 'required' => true]) ?>
    <?= component('field', ['name' => 'materials', 'label' => 'Materials bought', 'value' => old('materials', 'PET bottles')]) ?>
    <?= component('field', ['name' => 'consent', 'type' => 'checkbox', 'label' => 'The company has agreed to the PlastiLink data-use terms', 'required' => true]) ?>
    <button class="btn btn-primary" type="submit">Register recycler</button>
    <a class="btn btn-outline-primary" href="<?= e(url('/recyclers')) ?>">Cancel</a>
  </form>
</div></div>
