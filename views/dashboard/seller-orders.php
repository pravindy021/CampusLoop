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
$sellerActiveTab = 'orders';
$statement = database_connection()->prepare(
    'SELECT o.order_number, o.status, o.currency, o.created_at, oi.product_title_snapshot,
            oi.quantity, oi.unit_price, oi.line_total
     FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id
     WHERE oi.seller_id = :seller_id
     ORDER BY o.created_at DESC, o.id DESC, oi.id DESC LIMIT 100'
);
$statement->execute(['seller_id' => $sellerId]);
$orders = $statement->fetchAll();
?>
<!doctype html>
<html lang="en-IN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sale orders — CampusLoop</title>
  <link rel="stylesheet" href="/styles.css">
  <script src="/auth.js" defer></script>
</head>
<body class="dashboard-page">
  <?php require __DIR__ . '/_seller-header.php'; ?>
  <main class="dashboard-main seller-main">
    <section class="seller-page-heading"><div><p class="eyebrow">Transaction records</p><h1>Sale Orders</h1><p>Only order items associated with your seller account are shown.</p></div></section>
    <?php if ($orders === []): ?>
      <p class="buyer-empty">No sale-order records are currently associated with your listings.</p>
    <?php else: ?>
      <div class="seller-transaction-list">
        <?php foreach ($orders as $order): ?>
          <article class="seller-transaction">
            <div class="seller-transaction__heading">
              <div><p class="eyebrow">Order <?= escape_html((string) $order['order_number']) ?></p><h2><?= escape_html((string) $order['product_title_snapshot']) ?></h2></div>
              <span class="seller-status seller-status--order"><?= escape_html(ucwords(str_replace('_', ' ', (string) $order['status']))) ?></span>
            </div>
            <dl><div><dt>Quantity</dt><dd><?= (int) $order['quantity'] ?></dd></div><div><dt>Unit price</dt><dd><?= escape_html((string) $order['currency']) ?> <?= escape_html(number_format((float) $order['unit_price'], 2)) ?></dd></div><div><dt>Line total</dt><dd><?= escape_html((string) $order['currency']) ?> <?= escape_html(number_format((float) $order['line_total'], 2)) ?></dd></div><div><dt>Recorded</dt><dd><?= escape_html((string) $order['created_at']) ?></dd></div></dl>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <p class="dashboard-notice">Order status changes, fulfillment actions, refunds, and payment processing are not implemented. This page is read-only and shows database records only; order records are not proof of a payment unless their stored status explicitly says so.</p>
  </main>
</body>
</html>
