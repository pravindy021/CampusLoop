<?php
declare(strict_types=1);

$userId = require_dashboard_role('seller');
$statement = database_connection()->prepare(
    'SELECT u.email, p.full_name
     FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id
     WHERE u.id = :id LIMIT 1'
);
$statement->execute(['id' => $userId]);
$account = $statement->fetch();
if (!is_array($account)) {
    throw new RuntimeException('Authenticated account could not be loaded.');
}
$listingStatement = database_connection()->prepare(
    'SELECT COUNT(*) AS total,
            COALESCE(SUM(moderation_status = "pending_approval"), 0) AS pending,
            COALESCE(SUM(moderation_status = "published" AND availability_status = "available"
                AND archived_at IS NULL), 0) AS available,
            COALESCE(SUM(moderation_status = "published"
                AND listing_type IN ("rent_only", "rent_and_sell")
                AND availability_status = "available" AND archived_at IS NULL), 0) AS rental_active
     FROM products WHERE seller_id = :seller_id'
);
$listingStatement->execute(['seller_id' => $userId]);
$listingCounts = $listingStatement->fetch();
if (!is_array($listingCounts)) {
    throw new RuntimeException('Seller listing summary could not be loaded.');
}
$soldStatement = database_connection()->prepare(
    'SELECT COALESCE(SUM(oi.quantity), 0) AS sold_items
     FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id
     WHERE oi.seller_id = :seller_id AND o.status = "completed"'
);
$soldStatement->execute(['seller_id' => $userId]);
$soldItems = (int) $soldStatement->fetchColumn();
$completedStatement = database_connection()->prepare(
    'SELECT COUNT(DISTINCT o.id)
     FROM orders o INNER JOIN order_items oi ON oi.order_id = o.id
     WHERE oi.seller_id = :seller_id AND o.status = "completed"'
);
$completedStatement->execute(['seller_id' => $userId]);
$completedOrders = (int) $completedStatement->fetchColumn();
$rentalStatement = database_connection()->prepare(
    'SELECT COUNT(*) FROM rental_bookings WHERE seller_id = :seller_id AND status = "requested"'
);
$rentalStatement->execute(['seller_id' => $userId]);
$pendingRentals = (int) $rentalStatement->fetchColumn();
$sellerAccount = $account;
$sellerActiveTab = 'overview';
?>
<!doctype html>
<html lang="en-IN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f5f4ed">
  <title>Seller dashboard — CampusLoop</title>
  <link rel="stylesheet" href="/styles.css">
  <script src="/auth.js" defer></script>
</head>
<body class="dashboard-page">
  <?php require __DIR__ . '/_seller-header.php'; ?>
  <main class="dashboard-main seller-main">
    <section class="dashboard-welcome dashboard-welcome--seller">
      <p class="eyebrow">Seller dashboard</p>
      <h1>Welcome back<?= !empty($account['full_name']) ? ', ' . escape_html((string) $account['full_name']) : '' ?>!</h1>
      <p>Manage your listings, track order records, and grow your CampusLoop store.</p>
      <span class="dashboard-account"><?= escape_html((string) $account['email']) ?></span>
    </section>
    <section class="dashboard-section" aria-labelledby="seller-listings-title">
      <div class="dashboard-section__heading"><div><p class="eyebrow">Your marketplace activity</p><h2 id="seller-listings-title">Store overview</h2></div><a class="button button--lime" href="/seller/listings/new">Add a listing <span aria-hidden="true">↗</span></a></div>
      <div class="dashboard-stats">
        <article><span>Total listings</span><strong><?= (int) $listingCounts['total'] ?></strong></article>
        <article><span>Active listings</span><strong><?= (int) $listingCounts['available'] ?></strong></article>
        <article><span>Pending approval</span><strong><?= (int) $listingCounts['pending'] ?></strong></article>
        <article><span>Completed items sold</span><strong><?= $soldItems ?></strong></article>
        <article><span>Active rental listings</span><strong><?= (int) $listingCounts['rental_active'] ?></strong></article>
        <article><span>Pending rental requests</span><strong><?= $pendingRentals ?></strong></article>
        <article><span>Completed orders</span><strong><?= $completedOrders ?></strong></article>
      </div>
    </section>
    <section class="dashboard-section seller-quick-links" aria-label="Seller tools">
      <a href="/seller/listings"><strong>Manage listings</strong><span>Review status, edit details, pause or archive items.</span></a>
      <a href="/seller/orders"><strong>Sale orders</strong><span>View order records associated with your listings.</span></a>
      <a href="/seller/rentals"><strong>Rental requests</strong><span>Review rental request records associated with your listings.</span></a>
    </section>
    <section class="dashboard-notice">
      <h2>Moderation and transaction limitations</h2>
      <p>Listings remain private until approved by an administrator. The project does not yet include an admin moderation screen, order status actions, rental request decisions, checkout, or payment processing. The counts above reflect stored records only.</p>
    </section>
  </main>
</body>
</html>
