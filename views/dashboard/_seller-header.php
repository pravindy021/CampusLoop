<?php
declare(strict_types=1);

$sellerActiveTab = $sellerActiveTab ?? '';
?>
<script src="/notifications.js" defer></script>
<header class="dashboard-header seller-header">
  <a class="brand" href="/" aria-label="CampusLoop home"><span class="brand__mark" aria-hidden="true"><span></span><span></span><span></span></span><span class="brand__name">Campus<span>Loop</span></span></a>
  <nav class="seller-nav" aria-label="Seller dashboard">
    <a<?= $sellerActiveTab === 'overview' ? ' aria-current="page"' : '' ?> href="/seller/dashboard">Overview</a>
    <a<?= $sellerActiveTab === 'listings' ? ' aria-current="page"' : '' ?> href="/seller/listings">My Listings</a>
    <a href="/seller/listings/new">Add Listing</a>
    <a<?= $sellerActiveTab === 'orders' ? ' aria-current="page"' : '' ?> href="/seller/orders">Sale Orders</a>
    <a<?= $sellerActiveTab === 'rentals' ? ' aria-current="page"' : '' ?> href="/seller/rentals">Rental Requests</a>
    <a href="/messages">Messages <span class="nav-count" data-message-unread-count hidden></span></a>
    <a href="/notifications">Notifications <span class="nav-count" data-notification-unread-count hidden></span></a>
  </nav>
  <div class="seller-header__actions">
    <a class="button button--outline seller-mode-switch" href="/buyer/dashboard">Switch to Buyer Mode</a>
    <details class="seller-profile">
      <summary aria-label="Open account menu">☻</summary>
      <div class="seller-profile__menu">
        <strong><?= escape_html((string) ($sellerAccount['full_name'] ?: 'CampusLoop seller')) ?></strong>
        <span><?= escape_html((string) $sellerAccount['email']) ?></span>
        <form data-logout-form action="/api/v1/auth/logout" method="post">
          <input type="hidden" name="_csrf" value="<?= escape_html(csrf_token()) ?>">
          <button type="submit">Sign out</button>
          <p class="dashboard-message" data-dashboard-message aria-live="polite"></p>
        </form>
      </div>
    </details>
    <span class="notification-count-error" data-count-status role="status" aria-live="polite"></span>
  </div>
</header>
