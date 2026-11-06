<?= component('welcome', [
    'user' => auth_user(),
    'blurb' => 'Your collections and earnings will appear here.',
    'coming' => ['Collection history', 'Earnings and digital receipts', 'Income statements'],
]) ?>
