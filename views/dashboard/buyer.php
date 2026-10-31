<?php
declare(strict_types=1);

$userId = require_dashboard_role('buyer');
$statement = database_connection()->prepare(
    'SELECT u.email, p.full_name, p.city, p.country_code
     FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id
     WHERE u.id = :id LIMIT 1'
);
$statement->execute(['id' => $userId]);
$account = $statement->fetch();
if (!is_array($account)) {
    throw new RuntimeException('Authenticated account could not be loaded.');
}
$categories = database_connection()->query(
    'SELECT name, slug, description FROM categories WHERE is_active = 1 ORDER BY sort_order, id'
)->fetchAll();
$roles = user_roles($userId);
$requestedCategory = $_GET['category'] ?? '';
$requestedCategory = is_string($requestedCategory) ? $requestedCategory : '';
?>
<!doctype html>
<html lang="en-IN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f5f4ed">
  <title>Buyer dashboard — CampusLoop</title>
  <link rel="stylesheet" href="/styles.css">
  <script src="/buyer.js" defer></script>
  <script src="/auth.js" defer></script>
  <script src="/notifications.js" defer></script>
</head>
<body class="dashboard-page buyer-dashboard">
  <header class="dashboard-header buyer-header">
    <a class="brand" href="/" aria-label="CampusLoop home"><span class="brand__mark" aria-hidden="true"><span></span><span></span><span></span></span><span class="brand__name">Campus<span>Loop</span></span></a>
    <form class="buyer-header__search" id="buyer-search-form" role="search">
      <label class="visually-hidden" for="buyer-keyword">Search marketplace</label>
      <span aria-hidden="true">⌕</span>
      <input id="buyer-keyword" name="q" type="search" placeholder="Search books, gadgets, essentials…">
      <button class="button button--dark" type="submit">Search</button>
    </form>
    <div class="buyer-header__links">
      <label class="buyer-location"><span aria-hidden="true">⌖</span>
        <span class="visually-hidden">Filter by city</span>
        <input id="buyer-city" name="city" type="search" placeholder="Any location">
      </label>
      <a class="buyer-header__icon" href="/buyer/wishlist" aria-label="Wishlist">♡</a>
      <a class="buyer-header__icon" href="/buyer/orders" aria-label="Orders">▤</a>
      <a class="buyer-header__icon buyer-header__messages" href="/messages" aria-label="Messages">✉<span class="nav-count" data-message-unread-count hidden></span></a>
      <a class="buyer-header__icon" href="/notifications" aria-label="Notifications">♢<span class="nav-count" data-notification-unread-count hidden></span></a>
      <div class="buyer-profile">
        <button class="buyer-profile__button" id="buyer-profile-button" type="button" aria-expanded="false" aria-controls="buyer-profile-menu" aria-label="Open profile menu">☻</button>
        <div class="buyer-profile__menu" id="buyer-profile-menu" hidden>
          <strong><?= escape_html((string) ($account['full_name'] ?: 'CampusLoop buyer')) ?></strong>
          <span><?= escape_html((string) $account['email']) ?></span>
          <a href="/buyer/wishlist">My wishlist</a>
          <a href="/buyer/orders">My orders</a>
          <a href="/buyer/dashboard">Buyer dashboard</a>
          <?php if (in_array('seller', $roles, true)): ?>
            <a href="/seller/dashboard">Switch to Seller Mode</a>
          <?php endif; ?>
          <form data-logout-form action="/api/v1/auth/logout" method="post">
            <button type="submit">Sign out</button>
            <p class="dashboard-message" data-dashboard-message aria-live="polite"></p>
          </form>
        </div>
      </div>
      <?php if (in_array('seller', $roles, true)): ?>
        <a class="button button--outline buyer-seller-switch" href="/seller/dashboard">Seller mode</a>
      <?php else: ?>
        <a class="button button--outline buyer-seller-switch" href="#activate-seller">Activate Seller Mode</a>
      <?php endif; ?>
      <span class="notification-count-error" data-count-status role="status" aria-live="polite"></span>
    </div>
  </header>
  <main class="dashboard-main buyer-main">
    <section class="dashboard-welcome">
      <p class="eyebrow">Buyer dashboard</p>
      <h1>Welcome<?= !empty($account['full_name']) ? ', ' . escape_html((string) $account['full_name']) : '' ?>.</h1>
      <p>Find campus essentials, compare available listings, and save the things you like.</p>
      <span class="dashboard-account"><?= escape_html((string) $account['email']) ?></span>
    </section>

    <section class="dashboard-section" aria-labelledby="buyer-categories-title">
      <div class="dashboard-section__heading">
        <div><p class="eyebrow">Explore the marketplace</p><h2 id="buyer-categories-title">Browse by category</h2></div>
        <a class="dashboard-link" href="/">View homepage <span aria-hidden="true">↗</span></a>
      </div>
      <div class="dashboard-category-grid">
        <?php foreach ($categories as $category): ?>
          <?php $categorySlug = (string) $category['slug']; ?>
          <a class="dashboard-category<?= $requestedCategory === $categorySlug ? ' dashboard-category--active' : '' ?>" href="/buyer/dashboard?category=<?= rawurlencode($categorySlug) ?>#listings" data-category-link="<?= escape_html($categorySlug) ?>">
            <span class="dashboard-category__arrow" aria-hidden="true">↗</span>
            <h3><?= escape_html((string) $category['name']) ?></h3>
            <p><?= escape_html((string) ($category['description'] ?? 'Explore this category.')) ?></p>
          </a>
        <?php endforeach; ?>
      </div>
      <?php if ($categories === []): ?>
        <p class="dashboard-notice">Categories are not available yet. Check the database seed setup.</p>
      <?php endif; ?>
    </section>

    <section class="buyer-listings dashboard-section" id="listings" aria-labelledby="buyer-listings-title">
      <div class="dashboard-section__heading">
        <div><p class="eyebrow">Published and currently available</p><h2 id="buyer-listings-title">Marketplace listings</h2></div>
        <a class="dashboard-link" href="/buyer/wishlist">Saved finds ♡</a>
      </div>
      <p class="buyer-wishlist-status" id="buyer-wishlist-status" aria-live="polite"></p>
      <p class="buyer-currency-note">Listing amounts are shown as stored; per-listing currency is not currently recorded.</p>
      <form class="buyer-filters" id="buyer-filters">
        <label>Category
          <select name="category" id="buyer-category">
            <option value="">All categories</option>
            <?php foreach ($categories as $category): ?>
              <option value="<?= escape_html((string) $category['slug']) ?>" <?= $requestedCategory === $category['slug'] ? 'selected' : '' ?>><?= escape_html((string) $category['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Listing type
          <select name="mode" id="buyer-mode"><option value="">Sale or rental</option><option value="buy">For sale</option><option value="rent">For rent</option></select>
        </label>
        <label>Condition
          <select name="condition" id="buyer-condition"><option value="">Any condition</option><option value="new">New</option><option value="like_new">Like new</option><option value="good">Good</option><option value="fair">Fair</option></select>
        </label>
        <label>Learner type
          <select name="education_type" id="buyer-education-type"><option value="">Any learner</option><option value="school">School</option><option value="college">College or university</option><option value="competitive_exam">Competitive exams</option><option value="other_learner">Other learners</option></select>
        </label>
        <label>Class or level
          <input name="class_level" type="search" maxlength="80" placeholder="e.g. Class 10">
        </label>
        <label>Exam
          <input name="exam_category" type="search" maxlength="120" placeholder="e.g. NEET, UPSC">
        </label>
        <label>Brand
          <input name="brand" type="search" maxlength="120" placeholder="e.g. Lenovo">
        </label>
        <label>Locality
          <input name="locality" type="search" maxlength="120" placeholder="Nearby area">
        </label>
        <label>Min price
          <input name="min_price" id="buyer-min-price" type="number" min="0" max="100000000" step="0.01" inputmode="decimal" placeholder="Min amount">
        </label>
        <label>Max price
          <input name="max_price" id="buyer-max-price" type="number" min="0" max="100000000" step="0.01" inputmode="decimal" placeholder="Max amount">
        </label>
        <label>Country
          <select name="country" id="buyer-country"><option value="">Any country</option><option value="IN" selected>India</option><option value="US">United States</option><option value="GB">United Kingdom</option><option value="AE">United Arab Emirates</option><option value="SG">Singapore</option></select>
        </label>
        <button class="button button--dark" type="submit">Apply filters</button>
        <button class="buyer-reset" id="buyer-reset" type="reset">Clear</button>
      </form>
      <p class="buyer-results" id="buyer-results" aria-live="polite"></p>
      <div class="buyer-product-grid" id="buyer-products" aria-live="polite"></div>
      <p class="buyer-empty" id="buyer-empty" hidden>No published listings match those filters yet. Try changing your search.</p>
      <div class="buyer-pagination" id="buyer-pagination" aria-label="Product listing pages"></div>
      <p class="buyer-error" id="buyer-error" role="alert" hidden></p>
    </section>

    <section class="dashboard-mode-card" id="activate-seller">
      <div><p class="eyebrow">One account, both modes</p><h2>Have something to share?</h2><p>Activate Seller Mode on this account. No second registration is needed.</p></div>
      <?php if (in_array('seller', $roles, true)): ?>
        <a class="button button--lime" href="/seller/dashboard">Open Seller Dashboard <span aria-hidden="true">↗</span></a>
      <?php else: ?>
        <form data-activate-seller action="/api/v1/seller/activate" method="post">
          <input type="hidden" name="_csrf" value="<?= escape_html(csrf_token()) ?>">
          <button class="button button--lime" type="submit">Activate Seller Mode <span aria-hidden="true">↗</span></button>
          <p class="dashboard-message" data-dashboard-message aria-live="polite"></p>
        </form>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
