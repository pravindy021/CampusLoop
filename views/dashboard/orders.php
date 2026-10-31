<?php
declare(strict_types=1);

$userId = require_dashboard_role('buyer');
$pdo = database_connection();
$ordersStatement = $pdo->prepare(
    'SELECT id, order_number, currency, total_amount, status, created_at
     FROM orders WHERE buyer_id = :buyer_id
     ORDER BY created_at DESC, id DESC LIMIT 50'
);
$ordersStatement->execute(['buyer_id' => $userId]);
$orders = $ordersStatement->fetchAll();
$orderItems = [];
if ($orders !== []) {
    $itemStatement = $pdo->prepare(
        'SELECT order_id, product_title_snapshot, quantity, unit_price, line_total
         FROM order_items WHERE order_id = :order_id ORDER BY id'
    );
    foreach ($orders as $order) {
        $itemStatement->execute(['order_id' => $order['id']]);
        $orderItems[(int) $order['id']] = $itemStatement->fetchAll();
    }
}
$rentalStatement = $pdo->prepare(
    'SELECT booking_number, product_title_snapshot, starts_at, ends_at,
            rental_charge, security_deposit, currency, status, payment_status, created_at
     FROM rental_bookings WHERE buyer_id = :buyer_id
     ORDER BY created_at DESC, id DESC LIMIT 50'
);
$rentalStatement->execute(['buyer_id' => $userId]);
$rentals = $rentalStatement->fetchAll();
?>
<!doctype html>
<html lang="en-IN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My orders — CampusLoop</title>
  <link rel="stylesheet" href="/styles.css">
  <script src="/auth.js" defer></script>
  <script src="/notifications.js" defer></script>
</head>
<body class="dashboard-page">
  <header class="dashboard-header">
    <a class="brand" href="/" aria-label="CampusLoop home"><span class="brand__mark" aria-hidden="true"><span></span><span></span><span></span></span><span class="brand__name">Campus<span>Loop</span></span></a>
    <div class="dashboard-header__actions"><a class="dashboard-link" href="/buyer/dashboard">Buyer dashboard</a><a class="dashboard-link" href="/buyer/wishlist">My wishlist</a><a class="dashboard-link" href="/messages">Messages <span class="nav-count" data-message-unread-count hidden></span></a><a class="dashboard-link" href="/notifications">Notifications <span class="nav-count" data-notification-unread-count hidden></span></a><span class="notification-count-error" data-count-status role="status" aria-live="polite"></span>
      <form data-logout-form action="/api/v1/auth/logout" method="post"><button class="button button--dark" type="submit">Sign out</button><p class="dashboard-message" data-dashboard-message aria-live="polite"></p></form>
    </div>
  </header>
  <main class="dashboard-main">
    <section class="dashboard-section">
      <div class="dashboard-section__heading"><div><p class="eyebrow">Purchase history</p><h1>My orders</h1></div><a class="dashboard-link" href="/buyer/dashboard#listings">Discover listings ↗</a></div>
      <?php if ($orders === []): ?>
        <p class="buyer-empty">No orders are recorded for this account.</p>
      <?php else: ?>
        <div class="buyer-order-list">
          <?php foreach ($orders as $order): ?>
            <article class="buyer-order">
              <div class="buyer-order__heading"><div><span class="eyebrow">Order <?= escape_html((string) $order['order_number']) ?></span><h2><?= escape_html(ucwords(str_replace('_', ' ', (string) $order['status']))) ?></h2></div><time datetime="<?= escape_html((string) $order['created_at']) ?>"><?= escape_html((string) $order['created_at']) ?></time></div>
              <ul>
                <?php foreach ($orderItems[(int) $order['id']] ?? [] as $item): ?>
                  <li><span><?= escape_html((string) $item['product_title_snapshot']) ?> × <?= (int) $item['quantity'] ?></span><span><?= escape_html((string) $order['currency']) ?> <?= escape_html(number_format((float) $item['line_total'], 2)) ?></span></li>
                <?php endforeach; ?>
              </ul>
              <p class="buyer-order__total">Total <strong><?= escape_html((string) $order['currency']) ?> <?= escape_html(number_format((float) $order['total_amount'], 2)) ?></strong></p>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
    <section class="dashboard-section">
      <div class="dashboard-section__heading"><div><p class="eyebrow">Rental activity</p><h2>My rental requests</h2></div></div>
      <?php if ($rentals === []): ?>
        <p class="buyer-empty">No rental requests are recorded for this account.</p>
      <?php else: ?>
        <div class="buyer-order-list">
          <?php foreach ($rentals as $rental): ?>
            <article class="buyer-order">
              <div class="buyer-order__heading"><div><span class="eyebrow">Booking <?= escape_html((string) $rental['booking_number']) ?></span><h2><?= escape_html((string) $rental['product_title_snapshot']) ?></h2></div><span class="buyer-order__status"><?= escape_html(ucwords(str_replace('_', ' ', (string) $rental['status']))) ?></span></div>
              <p><?= escape_html((string) $rental['starts_at']) ?> — <?= escape_html((string) $rental['ends_at']) ?></p>
              <p>Rental charge: <?= escape_html((string) $rental['currency']) ?> <?= escape_html(number_format((float) $rental['rental_charge'], 2)) ?> · Deposit: <?= escape_html((string) $rental['currency']) ?> <?= escape_html(number_format((float) $rental['security_deposit'], 2)) ?></p>
              <p>Payment status: <?= escape_html(ucwords((string) $rental['payment_status'])) ?></p>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <p class="dashboard-notice">These are database records only. CampusLoop does not currently provide purchase checkout or create rental bookings from listings.</p>
    </section>
  </main>
</body>
</html>
