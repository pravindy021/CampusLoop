<?php
declare(strict_types=1);

$userId = current_user_id();
if ($userId === null) {
    header('Location: /login?mode=buyer', true, 303);
    exit;
}
require_user();
?>
<!doctype html>
<html lang="en-IN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f5f4ed">
  <title>Notifications — CampusLoop</title>
  <link rel="stylesheet" href="/styles.css">
  <script src="/auth.js" defer></script>
  <script src="/notifications.js" defer></script>
</head>
<body class="dashboard-page">
  <header class="dashboard-header">
    <a class="brand" href="/" aria-label="CampusLoop home"><span class="brand__mark" aria-hidden="true"><span></span><span></span><span></span></span><span class="brand__name">Campus<span>Loop</span></span></a>
    <nav class="dashboard-header__actions" aria-label="Account navigation">
      <a class="dashboard-link" href="/buyer/dashboard">Marketplace</a>
      <a class="dashboard-link" href="/buyer/orders">My orders</a>
      <a class="dashboard-link" href="/messages">Messages <span class="nav-count" data-message-unread-count hidden></span></a>
      <a class="dashboard-link" href="/notifications" aria-current="page">Notifications <span class="nav-count" data-notification-unread-count hidden></span></a>
      <span class="notification-count-error" data-count-status role="status" aria-live="polite"></span>
    </nav>
  </header>
  <main class="dashboard-main">
    <section class="dashboard-section">
      <div class="dashboard-section__heading">
        <div><p class="eyebrow">Your CampusLoop activity</p><h1>Notifications</h1></div>
        <button class="button button--outline" id="notifications-read-all" type="button">Mark all as read</button>
      </div>
      <p class="buyer-error" id="notifications-error" role="alert" hidden></p>
      <div class="notification-list" id="notification-list" aria-live="polite">
        <p class="buyer-empty">Loading notifications…</p>
      </div>
    </section>
  </main>
</body>
</html>
