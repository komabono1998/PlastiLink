<?php /** @var array|null $targets */
$tg = $targets ?? null;
$goalKg = $tg && $tg['kg'] !== null ? number_format((int) $tg['kg']) : null;
?>
<!-- Hero -->
<section class="land-hero">
  <div class="container position-relative">
    <div class="row align-items-center g-4 g-lg-5">
      <div class="col-lg-7">
        <span class="land-chip mb-3"><i class="bi bi-geo-alt-fill" aria-hidden="true"></i> Gulu City, Northern Uganda</span>
        <h1>Turning plastic waste into income</h1>
        <p class="lead mt-3 mb-4">PlastiLink connects waste pickers, collection hubs and recycling companies, so plastic bottles are sold, not dumped, and every kilogram earns transparent income.</p>
        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-lime btn-lg" href="<?= e(url('/register')) ?>"><i class="bi bi-person-plus" aria-hidden="true"></i> I am a waste picker</a>
          <a class="btn btn-outline-light btn-lg" href="<?= e(url('/register/recycler')) ?>"><i class="bi bi-building" aria-hidden="true"></i> I am a recycler</a>
        </div>
        <p class="small mt-3 mb-0 text-white-50">Already registered? <a class="link-light" href="<?= e(url('/login')) ?>">Sign in</a></p>
      </div>
      <div class="col-lg-5">
        <figure class="hero-photo mb-0">
          <img src="<?= e(asset('img/gulu-monument.jpg')) ?>" width="1100" height="733" alt="The elephant monument in Gulu City, Uganda" fetchpriority="high">
          <figcaption><i class="bi bi-geo-alt-fill" aria-hidden="true"></i> Gulu City, Uganda</figcaption>
        </figure>
        <div class="hero-card hero-card-overlap">
          <div class="hero-step"><span><i class="bi bi-speedometer2" aria-hidden="true"></i></span> Weighed at a collection hub</div>
          <div class="hero-step"><span><i class="bi bi-receipt" aria-hidden="true"></i></span> Digital receipt for every sale</div>
          <div class="hero-step"><span><i class="bi bi-phone" aria-hidden="true"></i></span> Paid to your registered phone</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Four steps as photo-style cards -->
<section class="container land-pull" id="how-it-works" aria-labelledby="how-h">
  <h2 class="visually-hidden" id="how-h">How it works</h2>
  <div class="row g-3">
    <?php foreach ([
        ['svc-1', 'bag-check', 'Collect', 'Pickers gather and sort PET bottles in their neighbourhood.'],
        ['svc-2', 'speedometer2', 'Weigh and record', 'Hub staff weigh it and record the sale with a unique reference.'],
        ['svc-3', 'cash-coin', 'Get paid', 'A receipt for every sale, and payment to the picker\'s phone.'],
        ['svc-4', 'recycle', 'Recycle', 'Recyclers buy verified supply at transparent prices.'],
    ] as $i => [$cls, $icon, $name, $text]): ?>
      <div class="col-6 col-lg-3">
        <div class="svc-card <?= e($cls) ?>">
          <i class="bi bi-<?= e($icon) ?> svc-bg" aria-hidden="true"></i>
          <div class="position-relative">
            <span class="svc-num">0<?= $i + 1 ?></span>
            <h3 class="h5 mb-1"><?= e($name) ?></h3>
            <p class="small mb-0 svc-text"><?= e($text) ?></p>
          </div>
          <span class="svc-badge" aria-hidden="true"><i class="bi bi-<?= e($icon) ?>"></i></span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- About -->
<section class="container py-5 my-lg-4">
  <div class="row align-items-center g-4 g-lg-5">
    <div class="col-lg-6">
      <div class="about-art">
        <div class="about-tile t1"><img src="<?= e(asset('img/sorting-bottles.jpg')) ?>" width="760" height="506" loading="lazy" alt="A worker sorting plastic bottles into large collection sacks"><span>Sorted by hand</span></div>
        <div class="about-tile t2"><img src="<?= e(asset('img/gulu-street.jpg')) ?>" width="760" height="570" loading="lazy" alt="A street junction in Gulu City"><span>In Gulu City</span></div>
        <div class="about-tile t3"><img src="<?= e(asset('img/pet-bales.jpg')) ?>" width="760" height="570" loading="lazy" alt="Stacked bales of compressed plastic bottles"><span>Ready to recycle</span></div>
        <?php if ($goalKg): ?>
          <div class="about-badge"><small>12-month goal</small><strong><?= e($goalKg) ?></strong><small>kg collected and sold</small></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-lg-6">
      <span class="land-kicker">About PlastiLink</span>
      <h2 class="display-6 fw-bold mb-3">A fairer, traceable way to recycle in Gulu.</h2>
      <p class="text-muted">Plastic collected by waste pickers is weighed at a hub, recorded, and sold on to recycling companies. Everyone in the chain can see what was collected, what it was worth and who was paid.</p>
      <ul class="list-unstyled land-checks mb-4">
        <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Prices set per kilogram and shown before you sell</li>
        <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Every collection approved by a second person</li>
        <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Payouts to the picker's own registered phone</li>
      </ul>
      <a class="btn btn-primary btn-lg" href="<?= e(url('/register')) ?>">Join the programme <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </div>
  </div>
</section>

<!-- Dark feature band -->
<section class="dark-band">
  <div class="container">
    <div class="text-center mb-5">
      <span class="land-chip"><i class="bi bi-stars" aria-hidden="true"></i> Why PlastiLink</span>
      <h2 class="display-6 fw-bold mt-3 mb-0">Built for everyone in the plastic chain</h2>
    </div>
    <div class="row g-4 align-items-center">
      <div class="col-lg-4">
        <?php foreach ([
            ['receipt', 'Receipts for every sale', 'Each approved collection gets a numbered receipt.'],
            ['cash-coin', 'Clear earnings', 'Pickers see what they are owed and what they were paid.'],
            ['shield-check', 'Checked by two people', 'Money and stock changes need a second person to approve.'],
        ] as [$icon, $h, $t]): ?>
          <div class="feat mb-4"><span class="feat-icon"><i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i></span><div><h3 class="h6 mb-1"><?= e($h) ?></h3><p class="small mb-0 text-white-50"><?= e($t) ?></p></div></div>
        <?php endforeach; ?>
      </div>
      <div class="col-lg-4 text-center d-none d-lg-block"><div class="hero-orb mx-auto" aria-hidden="true"><i class="bi bi-recycle"></i></div></div>
      <div class="col-lg-4">
        <?php foreach ([
            ['boxes', 'Stock you can trust', 'Plastic on hand is tracked at every hub, kilogram by kilogram.'],
            ['bag-check', 'Simple buying for recyclers', 'Browse available plastic, make an offer and track delivery.'],
            ['lock', 'Your data is protected', 'Phone numbers and IDs are never shown in reports.'],
        ] as [$icon, $h, $t]): ?>
          <div class="feat mb-4"><span class="feat-icon"><i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i></span><div><h3 class="h6 mb-1"><?= e($h) ?></h3><p class="small mb-0 text-white-50"><?= e($t) ?></p></div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- Targets -->
<section class="container py-5" id="targets">
  <span class="land-kicker">Our goals</span>
  <h2 class="h2 fw-bold mb-1">Programme targets</h2>
  <p class="text-muted">12-month goals set in the project plan. Live results will replace these once collections begin.</p>
  <?php if ($tg): ?>
    <div class="row g-3">
      <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'people',   'value' => (string) $tg['pickers'],   'label' => 'Registered waste pickers', 'hint' => 'Pilot: ' . $tg['pilot']]) ?></div>
      <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'shop',     'value' => (string) $tg['hubs'],      'label' => 'Collection hubs']) ?></div>
      <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'building', 'value' => (string) $tg['recyclers'], 'label' => 'Recycling companies', 'hint' => 'at least']) ?></div>
      <div class="col-6 col-lg-3"><?= component('stat-card', ['icon' => 'box-seam', 'value' => number_format((int) $tg['kg']) . ' kg', 'label' => 'Plastic collected and sold']) ?></div>
    </div>
  <?php else: ?>
    <?= component('alert', ['type' => 'warning', 'message' => 'Programme targets are temporarily unavailable.']) ?>
  <?php endif; ?>
</section>

<!-- FAQ -->
<section class="container pb-5" id="faq">
  <div class="row g-4 g-lg-5">
    <div class="col-lg-4">
      <span class="land-kicker">FAQ</span>
      <h2 class="h2 fw-bold">Questions people ask</h2>
      <p class="text-muted">Can't find your answer? Ask staff at your nearest collection hub.</p>
    </div>
    <div class="col-lg-8">
      <div class="accordion land-faq" id="faqList">
        <?php foreach ([
            ['Who can join?', 'Waste pickers in Gulu City can register online or in person at a hub. Recycling companies register their business. New accounts are checked and approved before they can sign in.'],
            ['How is my plastic priced?', 'Programme staff set a price per kilogram for each type of plastic. The price in force when your plastic is weighed is the one used, and it is written on your receipt.'],
            ['How do I get paid?', 'Once collections are approved, the money appears in your earnings. You ask for a payout, a second person approves it, and it is paid to your registered phone by mobile money, or in cash at the hub.'],
            ['Is my information safe?', 'We keep your name, phone number and sales records to run the programme, and you agree to this when you register. Phone numbers and ID numbers are not shown in reports, and you can ask to see or delete your data.'],
            ['I am a recycler. How do I buy?', 'Register your company. When it is approved you can browse the plastic available at the hubs, make an offer, agree a price, and track the delivery and your invoice.'],
        ] as $n => [$q, $a]): ?>
          <div class="accordion-item">
            <h3 class="accordion-header"><button class="accordion-button<?= $n ? ' collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#faq<?= $n ?>" aria-expanded="<?= $n ? 'false' : 'true' ?>" aria-controls="faq<?= $n ?>"><?= e($q) ?></button></h3>
            <div id="faq<?= $n ?>" class="accordion-collapse collapse<?= $n ? '' : ' show' ?>" data-bs-parent="#faqList"><div class="accordion-body text-muted"><?= e($a) ?></div></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- Call to action -->
<section class="container pb-5">
  <div class="cta-band d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
    <div>
      <h2 class="h3 fw-bold mb-1">Ready to turn plastic into income?</h2>
      <p class="mb-0 opacity-75">Register in minutes, or visit your nearest collection hub.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-lime btn-lg" href="<?= e(url('/register')) ?>">Register as a picker</a>
      <a class="btn btn-outline-light btn-lg" href="<?= e(url('/register/recycler')) ?>">Register as a recycler</a>
    </div>
  </div>
</section>

<!-- Photo credits (required by the Creative Commons licences) -->
<section class="container pb-4" id="credits">
  <p class="small text-muted mb-0">
    <strong>Photo credits.</strong> Photos from Wikimedia Commons, resized and compressed.
    Gulu monument: <a href="https://commons.wikimedia.org/wiki/File:Gulu_city.jpg" rel="noopener noreferrer" target="_blank">"Gulu city"</a> by Micheal Kaluba,
    <a href="https://creativecommons.org/licenses/by-sa/4.0/" rel="noopener noreferrer" target="_blank">CC BY-SA 4.0</a>.
    Street: <a href="https://commons.wikimedia.org/wiki/File:Gulu_city_clock,_Uganda.jpg" rel="noopener noreferrer" target="_blank">"Gulu city clock, Uganda"</a> by Owobusingye,
    <a href="https://creativecommons.org/licenses/by/4.0/" rel="noopener noreferrer" target="_blank">CC BY 4.0</a>.
    Sorting bottles (taken in Thailand): <a href="https://commons.wikimedia.org/wiki/File:DFC_4507_A_worker_sorting_recyclable_plastic_bottles_into_large_collection_sacks_along_a_quiet_urban_street.jpg" rel="noopener noreferrer" target="_blank">"DFC 4507"</a> by PattayaPatrol,
    <a href="https://creativecommons.org/licenses/by-sa/4.0/" rel="noopener noreferrer" target="_blank">CC BY-SA 4.0</a>.
    Bales: <a href="https://commons.wikimedia.org/wiki/File:Bales_of_PET_bottles_stacked.jpg" rel="noopener noreferrer" target="_blank">"Bales of PET bottles stacked"</a> by Grendelkhan,
    <a href="https://creativecommons.org/licenses/by-sa/4.0/" rel="noopener noreferrer" target="_blank">CC BY-SA 4.0</a>.
    The last two show the kind of work involved and are not PlastiLink's own pickers or hubs.
  </p>
</section>