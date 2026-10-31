<?php
declare(strict_types=1);

$userId = require_dashboard_role('buyer');
$products = Wishlist::products_for_user($userId);
$conditionLabels = ['new' => 'New', 'like_new' => 'Like new', 'good' => 'Good', 'fair' => 'Fair'];
$listingLabels = ['sell_only' => 'For sale', 'rent_only' => 'For rent', 'rent_and_sell' => 'For sale or rent'];
?>
<!doctype html>
<html lang="en-IN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My wishlist — CampusLoop</title>
  <link rel="stylesheet" href="/styles.css">
  <script src="/auth.js" defer></script>
  <script src="/notifications.js" defer></script>
</head>
<body class="dashboard-page">
  <header class="dashboard-header">
    <a class="brand" href="/" aria-label="CampusLoop home"><span class="brand__mark" aria-hidden="true"><span></span><span></span><span></span></span><span class="brand__name">Campus<span>Loop</span></span></a>
    <div class="dashboard-header__actions"><a class="dashboard-link" href="/buyer/dashboard">Buyer dashboard</a><a class="dashboard-link" href="/buyer/orders">My orders</a><a class="dashboard-link" href="/messages">Messages <span class="nav-count" data-message-unread-count hidden></span></a><a class="dashboard-link" href="/notifications">Notifications <span class="nav-count" data-notification-unread-count hidden></span></a><span class="notification-count-error" data-count-status role="status" aria-live="polite"></span>
      <form data-logout-form action="/api/v1/auth/logout" method="post"><button class="button button--dark" type="submit">Sign out</button><p class="dashboard-message" data-dashboard-message aria-live="polite"></p></form>
    </div>
  </header>
  <main class="dashboard-main">
    <section class="dashboard-section">
      <div class="dashboard-section__heading"><div><p class="eyebrow">Saved for later</p><h1>My wishlist</h1></div><a class="dashboard-link" href="/buyer/dashboard#listings">Discover listings ↗</a></div>
      <?php if ($products === []): ?>
        <p class="buyer-empty">No available saved listings yet. Listings that are unpublished, unavailable, or archived are not shown here.</p>
      <?php else: ?>
        <div class="buyer-product-grid">
          <?php foreach ($products as $product): ?>
            <?php
            $imagePath = (string) ($product['image'] ?? '');
            $imageSegments = explode('/', substr($imagePath, 1));
            $imageIsPublic = preg_match('#^/(?!/)[A-Za-z0-9_./-]+$#D', $imagePath) === 1
                && !in_array('', $imageSegments, true)
                && !in_array('.', $imageSegments, true)
                && !in_array('..', $imageSegments, true);
            ?>
            <article class="buyer-product">
              <a class="buyer-product__link" href="/products/<?= (int) $product['id'] ?>">
                <div class="buyer-product__visual">
                  <?php if ($imageIsPublic): ?>
                    <img src="<?= escape_html($imagePath) ?>" alt="<?= escape_html((string) $product['title']) ?>" loading="lazy">
                  <?php else: ?>
                    <span class="buyer-product__placeholder" aria-hidden="true">CampusLoop</span>
                  <?php endif; ?>
                  <span class="buyer-product__badge"><?= escape_html($listingLabels[(string) $product['listing_type']] ?? 'Listing') ?></span>
                </div>
                <div class="buyer-product__details">
                  <p class="buyer-product__category"><?= escape_html((string) $product['category']) ?></p>
                  <h2><?= escape_html((string) $product['title']) ?></h2>
                  <p class="buyer-product__meta"><?= escape_html($conditionLabels[(string) $product['condition_label']] ?? (string) $product['condition_label']) ?></p>
                  <p class="buyer-product__meta"><?= escape_html((string) ($product['seller_name'] ?: 'CampusLoop seller')) ?> · <?= escape_html(implode(', ', array_filter([(string) $product['city'], (string) $product['country_code']]))) ?></p>
                  <div class="buyer-product__prices">
                    <?php if ($product['sale_price'] !== null): ?><span class="buyer-product__price"><small>Sale amount</small><strong><?= escape_html(number_format((float) $product['sale_price'], 2)) ?></strong></span><?php endif; ?>
                    <?php if ($product['daily_rental_price'] !== null): ?><span class="buyer-product__price"><small>Per day</small><strong><?= escape_html(number_format((float) $product['daily_rental_price'], 2)) ?></strong></span><?php endif; ?>
                  </div>
                  <p class="buyer-product__availability">Available</p>
                </div>
              </a>
              <form class="wishlist-remove" data-wishlist-remove action="/api/v1/wishlist/<?= (int) $product['id'] ?>" method="post">
                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                <button class="button button--outline" type="submit">Remove from wishlist</button>
                <p class="dashboard-message" data-dashboard-message aria-live="polite"></p>
              </form>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <p class="buyer-currency-note">Listing amounts are shown as stored; per-listing currency is not currently recorded.</p>
    </section>
  </main>
</body>
</html>
