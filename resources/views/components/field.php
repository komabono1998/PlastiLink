<?php
/**
 * Form field with label, hint and server-side error.
 * @var string $name
 * @var string $label
 * @var string $type       text|email|tel|password|number|textarea|select|checkbox
 * @var string|null $value
 * @var string|null $hint
 * @var bool|null $required
 * @var array<string,string>|null $options  for select: value => label
 * @var array<string,string>|null $attrs    extra html attributes (escaped)
 */
$type = $type ?? 'text';
$id = 'f-' . $name;
$err = field_error($name);
$val = $type === 'password' ? '' : ($value ?? old($name));
$attrHtml = '';
foreach (($attrs ?? []) as $k => $v) {
    $attrHtml .= ' ' . e($k) . '="' . e($v) . '"';
}
$cls = 'form-control' . ($err !== '' ? ' is-invalid' : '');
$describedBy = trim(($err !== '' ? $id . '-err ' : '') . (!empty($hint) ? $id . '-hint' : ''));
$aria = $describedBy !== '' ? ' aria-describedby="' . e($describedBy) . '"' : '';
$req = !empty($required) ? ' required' : '';
?>
<div class="mb-3">
<?php if ($type === 'checkbox'): ?>
  <div class="form-check">
    <input class="form-check-input<?= $err !== '' ? ' is-invalid' : '' ?>" type="checkbox" id="<?= e($id) ?>" name="<?= e($name) ?>" value="1"<?= ($val === '1' || ($value ?? '') === '1') ? ' checked' : '' ?><?= $req ?><?= $aria ?>>
    <label class="form-check-label" for="<?= e($id) ?>"><?= e($label) ?></label>
  </div>
<?php else: ?>
  <label class="form-label" for="<?= e($id) ?>"><?= e($label) ?><?= !empty($required) ? ' <span class="text-danger" aria-hidden="true">*</span>' : '' ?></label>
  <?php if ($type === 'textarea'): ?>
    <textarea class="<?= e($cls) ?>" id="<?= e($id) ?>" name="<?= e($name) ?>" rows="3"<?= $req ?><?= $aria ?><?= $attrHtml ?>><?= e($val) ?></textarea>
  <?php elseif ($type === 'select'): ?>
    <select class="form-select<?= $err !== '' ? ' is-invalid' : '' ?>" id="<?= e($id) ?>" name="<?= e($name) ?>"<?= $req ?><?= $aria ?><?= $attrHtml ?>>
      <?php foreach (($options ?? []) as $ov => $ol): ?>
        <option value="<?= e($ov) ?>"<?= (string) $ov === (string) $val ? ' selected' : '' ?>><?= e($ol) ?></option>
      <?php endforeach; ?>
    </select>
  <?php else: ?>
    <input class="<?= e($cls) ?>" id="<?= e($id) ?>" name="<?= e($name) ?>" type="<?= e($type) ?>" value="<?= e($val) ?>"<?= $req ?><?= $aria ?><?= $attrHtml ?>>
  <?php endif; ?>
<?php endif; ?>
  <?php if (!empty($hint)): ?><div class="form-text" id="<?= e($id) ?>-hint"><?= e($hint) ?></div><?php endif; ?>
  <?php if ($err !== ''): ?><div class="invalid-feedback d-block" id="<?= e($id) ?>-err"><?= e($err) ?></div><?php endif; ?>
</div>
