<?php
declare(strict_types=1);

require_dashboard_role('buyer');
$product = Catalog::product_details((int) $productId);
if ($product === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Listing unavailable — CampusLoop</title><body><main><h1>This listing is not available.</h1><p>It may have been removed or its availability changed.</p><a href="/buyer/dashboard">Return to Buyer Dashboard</a></main></body></html>';
    exit;
}
$conditionLabels = ['new' => 'New', 'like_new' => 'Like new', 'good' => 'Good', 'fair' => 'Fair'];
$listingLabels = [
    'sell_only' => 'For sale',
    'rent_only' => 'For rent',
    'rent_and_sell' => 'For sale or rent',
];
$educationTypeLabels = [
    'school' => 'School',
    'college' => 'College or university',
    'competitive_exam' => 'Competitive exams',
    'other_learner' => 'Other learners',
];
$displayableImages = array_filter(
    $product['images'],
    static function (array $image): bool {
        $path = (string) $image['image_path'];
        $segments = explode('/', substr($path, 1));

        return preg_match('#^/(?!/)[A-Za-z0-9_./-]+$#D', $path) === 1
            && !in_array('', $segments, true)
            && !in_array('.', $segments, true)
            && !in_array('..', $segments, true);
    }
);
?>
<!doctype html>
<html lang="en-IN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= escape_html((string) $product['title']) ?> — CampusLoop</title>
  <link rel="stylesheet" href="/styles.css">
  <script src="/auth.js" defer></script>
  <script src="/notifications.js" defer></script>
  <script src="/messages.js" defer></script>
</head>
<body class="dashboard-page">
  <header class="dashboard-header">
    <a class="brand" href="/" aria-label="CampusLoop home"><span class="brand__mark" aria-hidden="true"><span></span><span></span><span></span></span><span class="brand__name">Campus<span>Loop</span></span></a>
    <div class="dashboard-header__actions"><a class="dashboard-link" href="/buyer/dashboard">Buyer dashboard</a><a class="dashboard-link" href="/buyer/wishlist">Wishlist ♡</a><a class="dashboard-link" href="/buyer/orders">Orders</a><a class="dashboard-link" href="/messages">Messages <span class="nav-count" data-message-unread-count hidden></span></a><a class="dashboard-link" href="/notifications">Notifications <span class="nav-count" data-notification-unread-count hidden></span></a><span class="notification-count-error" data-count-status role="status" aria-live="polite"></span></div>
  </header>
  <main class="dashboard-main">
    <p><a class="dashboard-link" href="/buyer/dashboard?category=<?= rawurlencode((string) $product['category_slug']) ?>#listings">← <?= escape_html((string) $product['category']) ?></a></p>
    <article class="product-detail">
      <div class="product-detail__gallery">
        <?php foreach ($displayableImages as $image): ?>
          <img src="<?= escape_html((string) $image['image_path']) ?>" alt="<?= escape_html((string) ($image['alt_text'] ?: $product['title'])) ?>" loading="lazy">
        <?php endforeach; ?>
        <?php if ($displayableImages === []): ?>
          <div class="product-detail__placeholder">No product images are available.</div>
        <?php endif; ?>
      </div>
      <div class="product-detail__copy">
        <p class="eyebrow"><?= escape_html((string) $product['category']) ?> · <?= escape_html($listingLabels[(string) $product['listing_type']] ?? 'Listing') ?></p>
        <h1><?= escape_html((string) $product['title']) ?></h1>
        <p class="product-detail__availability">Available · <?= (int) $product['quantity'] ?> unit<?= (int) $product['quantity'] === 1 ? '' : 's' ?> listed</p>
        <p class="product-detail__description"><?= nl2br(escape_html((string) $product['description'])) ?></p>
        <dl class="product-detail__facts">
          <div><dt>Condition</dt><dd><?= escape_html($conditionLabels[(string) $product['condition_label']] ?? (string) $product['condition_label']) ?></dd></div>
          <div><dt>Seller</dt><dd><?= escape_html((string) ($product['seller_name'] ?: 'Seller #' . $product['seller_id'])) ?></dd></div>
          <div><dt>Location</dt><dd><?= escape_html(implode(', ', array_filter([(string) $product['city'], (string) $product['country_code']]))) ?></dd></div>
          <?php foreach ([
              'education_type' => 'Learner audience',
              'class_level' => 'Class or level',
              'institution' => 'Institution',
              'course' => 'Course',
              'branch' => 'Branch or department',
              'semester' => 'Semester or year',
              'stream' => 'Stream',
              'exam_category' => 'Exam',
              'subject' => 'Subject',
              'brand' => 'Brand',
              'locality' => 'Locality',
          ] as $field => $label): ?>
            <?php if (!empty($product[$field])): ?><div><dt><?= escape_html($label) ?></dt><dd><?= escape_html($field === 'education_type' ? ($educationTypeLabels[(string) $product[$field]] ?? (string) $product[$field]) : (string) $product[$field]) ?></dd></div><?php endif; ?>
          <?php endforeach; ?>
          <div><dt>Listing status</dt><dd>Published and currently available</dd></div>
        </dl>
        <div class="product-detail__prices">
          <?php if ($product['sale_price'] !== null): ?><div><span>Sale amount</span><strong><?= escape_html(number_format((float) $product['sale_price'], 2)) ?></strong></div><?php endif; ?>
          <?php if ($product['daily_rental_price'] !== null): ?><div><span>Daily rental</span><strong><?= escape_html(number_format((float) $product['daily_rental_price'], 2)) ?></strong></div><?php endif; ?>
          <?php if ($product['weekly_rental_price'] !== null): ?><div><span>Weekly rental</span><strong><?= escape_html(number_format((float) $product['weekly_rental_price'], 2)) ?></strong></div><?php endif; ?>
          <?php if ($product['monthly_rental_price'] !== null): ?><div><span>Monthly rental</span><strong><?= escape_html(number_format((float) $product['monthly_rental_price'], 2)) ?></strong></div><?php endif; ?>
          <?php if ((float) $product['security_deposit'] > 0): ?><div><span>Refundable deposit</span><strong><?= escape_html(number_format((float) $product['security_deposit'], 2)) ?></strong></div><?php endif; ?>
          <?php if ($product['minimum_rental_days'] !== null || $product['maximum_rental_days'] !== null): ?><div><span>Rental duration</span><strong><?= $product['minimum_rental_days'] !== null ? (int) $product['minimum_rental_days'] : '—' ?>–<?= $product['maximum_rental_days'] !== null ? (int) $product['maximum_rental_days'] : '—' ?> days</strong></div><?php endif; ?>
        </div>
        <p class="product-detail__currency-note">Amounts are shown as stored. The listing schema does not record a per-product currency.</p>
        <p class="dashboard-notice">Purchase checkout and rental booking are not implemented, so Buy Now and Rent Now are unavailable.</p>
        <form class="product-message-form" data-message-seller action="/api/v1/conversations" method="post">
          <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
          <label for="seller-message">Message the seller about this listing</label>
          <textarea id="seller-message" name="message" rows="3" maxlength="2000" required placeholder="Ask a question or discuss this listing…"></textarea>
          <small>Keep personal contact details private. Do not send passwords or payment credentials.</small>
          <p class="dashboard-message" data-product-message-status role="status" aria-live="polite"></p>
          <button class="button button--lime" type="submit">Message seller</button>
        </form>
        <form class="product-detail__wishlist" data-wishlist-add action="/api/v1/wishlist/<?= (int) $product['id'] ?>" method="post">
          <button class="button button--lime" type="submit">♡ Save to wishlist</button>
          <p class="dashboard-message" data-dashboard-message aria-live="polite"></p>
        </form>
      </div>
    </article>
  </main>
</body>
</html>
