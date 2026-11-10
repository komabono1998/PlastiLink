<?php /** @var array $p @var array $neighbourhoods @var array $hubs @var bool $hubRequired @var bool $canSeeNationalId */
// After a failed save, show what was typed; otherwise the stored value
$v = static fn(string $k): string => old($k) !== '' ? old($k) : (string) ($p[$k] ?? '');
?>
<div class="card" style="max-width:640px"><div class="card-body">
  <p class="text-muted small mb-3"><?= e($p['picker_code']) ?> · Account status and verification are changed from the picker's page or the list.</p>
  <form method="post" action="<?= e(url('/pickers/' . $p['id'] . '/update')) ?>" data-loading novalidate>
    <?= csrf_field() ?>
    <?= component('field', ['name' => 'full_name', 'label' => 'Full name', 'required' => true, 'value' => $v('full_name')]) ?>
    <?= component('field', ['name' => 'phone', 'label' => 'Phone number', 'type' => 'tel', 'required' => true, 'value' => old('phone') !== '' ? old('phone') : display_phone($p['phone_e164']),
        'attrs' => ['inputmode' => 'tel', 'autocomplete' => 'off'], 'hint' => 'Used to sign in and to receive payments. Changing it changes where mobile-money payouts go.']) ?>
    <?= component('field', ['name' => 'neighbourhood_id', 'label' => 'Neighbourhood', 'type' => 'select', 'required' => true,
        'options' => ['' => 'Choose…'] + $neighbourhoods, 'value' => $v('neighbourhood_id')]) ?>
    <?= component('field', ['name' => 'preferred_hub_id', 'label' => 'Preferred collection hub', 'type' => 'select', 'required' => $hubRequired,
        'options' => ['' => $hubRequired ? 'Choose…' : 'No preference'] + $hubs, 'value' => $v('preferred_hub_id')]) ?>
    <?= component('field', ['name' => 'address_details', 'label' => 'Address details', 'value' => $v('address_details'), 'hint' => 'Landmark or directions. Optional.']) ?>
    <?php if ($canSeeNationalId): ?>
      <?= component('field', ['name' => 'national_id', 'label' => 'National ID number', 'value' => $v('national_id'), 'attrs' => ['autocomplete' => 'off'], 'hint' => 'Optional. Only keep it if the picker agreed.']) ?>
    <?php endif; ?>
    <?= component('field', ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'value' => $v('notes'), 'hint' => 'Staff-only notes. Do not record anything sensitive.']) ?>
    <button class="btn btn-primary" type="submit">Save changes</button>
    <a class="btn btn-outline-primary" href="<?= e(url('/pickers/' . $p['id'])) ?>">Cancel</a>
  </form>
</div></div>
