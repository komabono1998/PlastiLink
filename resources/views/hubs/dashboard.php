<?= component('welcome', [
    'user' => auth_user(),
    'blurb' => 'Hub operations',
    'coming' => ['Recording and weighing collections', 'Collection approvals and receipts', 'Hub stock and daily summaries'],
]) ?>
