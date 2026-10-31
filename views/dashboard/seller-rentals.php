<?php
declare(strict_types=1);

$sellerId = require_dashboard_role('seller');
$accountStatement = database_connection()->prepare(
    'SELECT email, (SELECT full_name FROM user_profiles WHERE user_id = users.id) AS full_name FROM users WHERE id = :id'
);
$accountStatement->execute(['id' => $sellerId]);
$sellerAccount = $accountStatement->fetch();
if (!is_array($sellerAccount)) {
    throw new RuntimeException('Authenticated seller could not be loaded.');
}
$sellerActiveTab = 'rentals';
$statement = database_connection()->prepare(
    'SELECT booking_number, product_title_snapshot, starts_at, ends_at,
            rental_charge, security_deposit, currency, status, payment_status, created_at
     FROM rental_bookings WHERE seller_id = :seller_id
     ORDER BY created_at DESC, id DESC LIMIT 100'
);
$statement->execute(['seller_id' => $sellerId]);
$rentals = $statement->fetchAll();
?>
<!doctype html>
<html lang="en-IN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Rental requests — CampusLoop</title>
  <link rel="stylesheet" href="/styles.css">
  <script src="/auth.js" defer></script>
</head>
<body class="dashboard-page">
  <?php require __DIR__ . '/_seller-header.php'; ?>
  <main class="dashboard-main seller-main">
    <section class="seller-page-heading"><div><p class="eyebrow">Rental activity</p><h1>Rental Requests</h1><p>Review the booking records stored against your listings.</p></div></section>
    <?php if ($rentals === []): ?>
      <p class="buyer-empty">No rental request records are currently associated with your listings.</p>
    <?php else: ?>
      <div class="seller-transaction-list">
        <?php foreach ($rentals as $rental): ?>
          <article class="seller-transaction">
            <div class="seller-transaction__heading">
              <div><p class="eyebrow">Booking <?= escape_html((string) $rental['booking_number']) ?></p><h2><?= escape_html((string) $rental['product_title_snapshot']) ?></h2></div>
              <span class="seller-status seller-status--order"><?= escape_html(ucwords(str_replace('_', ' ', (string) $rental['status']))) ?></span>
            </div>
            <dl><div><dt>Requested dates</dt><dd><?= escape_html((string) $rental['starts_at']) ?> – <?= escape_html((string) $rental['ends_at']) ?></dd></div><div><dt>Rental charge</dt><dd><?= escape_html((string) $rental['currency']) ?> <?= escape_html(number_format((float) $rental['rental_charge'], 2)) ?></dd></div><div><dt>Listed deposit</dt><dd><?= escape_html((string) $rental['currency']) ?> <?= escape_html(number_format((float) $rental['security_deposit'], 2)) ?></dd></div><div><dt>Payment status</dt><dd><?= escape_html(ucwords(str_replace('_', ' ', (string) $rental['payment_status']))) ?></dd></div><div><dt>Created</dt><dd><?= escape_html((string) $rental['created_at']) ?></dd></div></dl>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <p class="dashboard-notice">Accepting or rejecting requests, checking rental-date conflicts, recording handovers/returns, and collecting payments are not implemented. A request or seller acceptance is not proof of a successful payment. This page is read-only.</p>
  </main>
</body>
</html>
