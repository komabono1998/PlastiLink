<?= component('welcome', [
    'user' => auth_user(),
    'blurb' => 'Recycler marketplace',
    'coming' => ['Browse available plastic', 'Price offers and purchase orders', 'Delivery scheduling and purchase history'],
]) ?>
