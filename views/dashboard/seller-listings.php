<?php
declare(strict_types=1);

$sellerId = require_dashboard_role('seller');
$sellerAccountStatement = database_connection()->prepare(
    'SELECT email, (SELECT full_name FROM user_profiles WHERE user_id = users.id) AS full_name
     FROM users WHERE id = :id LIMIT 1'
);
$sellerAccountStatement->execute(['id' => $sellerId]);
$sellerAccount = $sellerAccountStatement->fetch();
if (!is_array($sellerAccount)) {
    throw new RuntimeException('Authenticated seller could not be loaded.');
}
$sellerActiveTab = 'listings';
$sellerFlash = $_SESSION['seller_listing_flash'] ?? '';
unset($_SESSION['seller_listing_flash']);
$categories = database_connection()->query(
    'SELECT name, slug FROM categories WHERE is_active = 1 ORDER BY sort_order, name'
)->fetchAll();
$filters = ['all', 'draft', 'pending', 'active', 'rejected', 'paused', 'unavailable', 'sold', 'archived'];
$status = $_GET['status'] ?? 'all';
$status = is_string($status) && in_array($status, $filters, true) ? $status : 'all';
$query = $_GET['q'] ?? '';
$query = is_string($query) ? trim($query) : '';
$category = $_GET['category'] ?? '';
$category = is_string($category) ? $category : '';
$type = $_GET['type'] ?? '';
$type = is_string($type) && in_array($type, ['sell_only', 'rent_only', 'rent_and_sell'], true) ? $type : '';
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$page = $page === false ? 1 : $page;
$where = ['p.seller_id = :seller_id'];
$parameters = ['seller_id' => $sellerId];
if ($query !== '') {
    $where[] = 'p.title LIKE :title';
    $parameters['title'] = '%' . addcslashes(mb_substr($query, 0, 120), '%_\\') . '%';
}
if ($category !== '' && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $category)) {
    $where[] = 'c.slug = :category';
    $parameters['category'] = $category;
} else {
    $category = '';
}
if ($type !== '') {
    $where[] = 'p.listing_type = :listing_type';
    $parameters['listing_type'] = $type;
}
$statusConditions = [
    'draft' => 'p.moderation_status = "draft" AND p.archived_at IS NULL',
    'pending' => 'p.moderation_status = "pending_approval" AND p.archived_at IS NULL',
    'active' => 'p.moderation_status = "published" AND p.availability_status = "available" AND p.archived_at IS NULL',
    'rejected' => 'p.moderation_status = "rejected" AND p.archived_at IS NULL',
    'paused' => 'p.moderation_status = "published" AND p.availability_status = "paused" AND p.archived_at IS NULL',
    'unavailable' => 'p.availability_status = "unavailable" AND p.archived_at IS NULL',
    'sold' => 'p.availability_status = "sold" AND p.archived_at IS NULL',
    'archived' => 'p.archived_at IS NOT NULL',
];
if (isset($statusConditions[$status])) {
    $where[] = $statusConditions[$status];
}
$whereSql = implode(' AND ', $where);
$pdo = database_connection();
$countStatement = $pdo->prepare(
    'SELECT COUNT(*) FROM products p INNER JOIN categories c ON c.id = p.category_id WHERE ' . $whereSql
);
$countStatement->execute($parameters);
$total = (int) $countStatement->fetchColumn();
$perPage = 30;
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$listingStatement = $pdo->prepare(
    'SELECT p.id, p.title, p.listing_type, p.sale_price, p.daily_rental_price,
            p.moderation_status, p.availability_status, p.rejection_reason, p.archived_at, p.created_at,
            c.name AS category, c.slug AS category_slug,
            (SELECT pi.image_path FROM product_images pi
             WHERE pi.product_id = p.id ORDER BY pi.sort_order, pi.id LIMIT 1) AS image
     FROM products p INNER JOIN categories c ON c.id = p.category_id
     WHERE ' . $whereSql . '
     ORDER BY p.created_at DESC, p.id DESC LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $listingStatement->bindValue(':' . $key, $value);
}
$listingStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listingStatement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
$listingStatement->execute();
$listings = $listingStatement->fetchAll();
$statusLabels = [
    'draft' => 'Draft',
    'pending_approval' => 'Pending approval',
    'published' => 'Published',
    'rejected' => 'Rejected',
    'removed' => 'Removed',
];
$typeLabels = ['sell_only' => 'For sale', 'rent_only' => 'For rent', 'rent_and_sell' => 'For sale or rent'];
$statusTitles = ['all' => 'All listings', 'draft' => 'Drafts', 'pending' => 'Pending approval', 'active' => 'Active', 'rejected' => 'Rejected', 'paused' => 'Paused', 'unavailable' => 'Unavailable', 'sold' => 'Sold out', 'archived' => 'Archived'];
?>
<!doctype html>
<html lang="en-IN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My listings — CampusLoop</title>
  <link rel="stylesheet" href="/styles.css">
  <script src="/auth.js" defer></script>
  <script src="/seller.js" defer></script>
</head>
<body class="dashboard-page">
  <?php require __DIR__ . '/_seller-header.php'; ?>
  <main class="dashboard-main seller-main">
    <section class="seller-page-heading">
      <div><p class="eyebrow">Your inventory</p><h1>My Listings</h1><p>Manage your products and follow their review status.</p></div>
      <a class="button button--lime" href="/seller/listings/new">Add a listing <span aria-hidden="true">↗</span></a>
    </section>
    <nav class="seller-status-tabs" aria-label="Filter listings by status">
      <?php foreach ($statusTitles as $key => $label): ?>
        <a href="/seller/listings?status=<?= rawurlencode($key) ?>"<?= $status === $key ? ' aria-current="page"' : '' ?>><?= escape_html($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php if (is_string($sellerFlash) && $sellerFlash !== ''): ?><p class="seller-success-message" role="status"><?= escape_html($sellerFlash) ?></p><?php endif; ?>
    <form class="seller-filters" method="get" action="/seller/listings">
      <input type="hidden" name="status" value="<?= escape_html($status) ?>">
      <label>Search title<input type="search" name="q" maxlength="120" value="<?= escape_html($query) ?>" placeholder="Search your listings"></label>
      <label>Category<select name="category"><option value="">All categories</option><?php foreach ($categories as $row): ?><option value="<?= escape_html((string) $row['slug']) ?>"<?= $category === $row['slug'] ? ' selected' : '' ?>><?= escape_html((string) $row['name']) ?></option><?php endforeach; ?></select></label>
      <label>Listing type<select name="type"><option value="">All types</option><option value="sell_only"<?= $type === 'sell_only' ? ' selected' : '' ?>>For sale</option><option value="rent_only"<?= $type === 'rent_only' ? ' selected' : '' ?>>For rent</option><option value="rent_and_sell"<?= $type === 'rent_and_sell' ? ' selected' : '' ?>>For sale or rent</option></select></label>
      <button class="button button--dark" type="submit">Filter</button>
    </form>
    <p class="seller-listing-count"><?= $total ?> listing<?= $total === 1 ? '' : 's' ?> · <?= escape_html($statusTitles[$status]) ?></p>
    <p class="seller-action-message" data-seller-message role="status" aria-live="polite"></p>
    <?php if ($listings === []): ?>
      <p class="buyer-empty">No listings match this view. Try another filter or add a new listing.</p>
    <?php else: ?>
      <div class="seller-listing-list">
        <?php foreach ($listings as $item): ?>
          <?php
          $availability = (string) $item['availability_status'];
          $archived = $item['archived_at'] !== null;
          $canEdit = !$archived && $item['moderation_status'] !== 'removed' && $availability !== 'sold';
          $label = $archived ? 'Archived' : ($availability === 'paused' ? 'Paused' : ($availability === 'sold' ? 'Sold out' : ($availability === 'unavailable' ? 'Unavailable' : ($statusLabels[(string) $item['moderation_status']] ?? 'Unavailable'))));
          $imagePath = (string) ($item['image'] ?? '');
          $hasImage = preg_match('#^/media/products/[a-f0-9]{32}\.(?:jpg|png|webp)$#D', $imagePath) === 1;
          ?>
          <article class="seller-listing">
            <div class="seller-listing__image"><?php if ($hasImage): ?><img src="<?= escape_html($imagePath) ?>" alt="" loading="lazy"><?php else: ?><span aria-hidden="true">CampusLoop</span><?php endif; ?></div>
            <div class="seller-listing__content">
              <div class="seller-listing__title"><div><p class="eyebrow"><?= escape_html((string) $item['category']) ?> · <?= escape_html($typeLabels[(string) $item['listing_type']] ?? 'Listing') ?></p><h2><?= escape_html((string) $item['title']) ?></h2></div><span class="seller-status seller-status--<?= escape_html($availability === 'paused' ? 'paused' : (string) $item['moderation_status']) ?>"><?= escape_html($label) ?></span></div>
              <p class="seller-listing__prices"><?php if ($item['sale_price'] !== null): ?>Sale <?= escape_html(number_format((float) $item['sale_price'], 2)) ?><?php endif; ?><?php if ($item['daily_rental_price'] !== null): ?><?= $item['sale_price'] !== null ? ' · ' : '' ?>Daily rent <?= escape_html(number_format((float) $item['daily_rental_price'], 2)) ?><?php endif; ?></p>
              <p class="seller-listing__date">Created <?= escape_html((string) $item['created_at']) ?> · Amounts shown as stored; no listing currency is recorded.</p>
              <?php if ($item['moderation_status'] === 'rejected' && !empty($item['rejection_reason'])): ?><p class="seller-rejection"><strong>Review note:</strong> <?= escape_html((string) $item['rejection_reason']) ?></p><?php endif; ?>
              <div class="seller-listing__actions">
                <?php if ($canEdit): ?><a class="button button--outline" href="/seller/listings/<?= (int) $item['id'] ?>/edit"><?= $item['moderation_status'] === 'rejected' ? 'Edit and resubmit' : 'Edit listing' ?></a><?php endif; ?>
                <?php if ($item['moderation_status'] === 'published' && $availability === 'available' && !$archived): ?>
                  <a class="dashboard-link" href="/products/<?= (int) $item['id'] ?>">View public listing</a>
                  <form data-listing-action action="/api/v1/seller/listings/<?= (int) $item['id'] ?>/actions"><input type="hidden" name="action" value="pause"><button type="submit">Pause</button></form>
                <?php elseif ($item['moderation_status'] === 'published' && $availability === 'paused' && !$archived): ?>
                  <form data-listing-action action="/api/v1/seller/listings/<?= (int) $item['id'] ?>/actions"><input type="hidden" name="action" value="resume"><button type="submit">Resume</button></form>
                <?php endif; ?>
                <?php if (!$archived): ?>
                  <form data-listing-action data-confirm="Archive this listing? It will no longer be publicly available." action="/api/v1/seller/listings/<?= (int) $item['id'] ?>/actions"><input type="hidden" name="action" value="archive"><button type="submit">Archive</button></form>
                <?php endif; ?>
                <?php if ($item['moderation_status'] === 'draft'): ?>
                  <form data-listing-action data-confirm="Delete this draft and its images?" action="/api/v1/seller/listings/<?= (int) $item['id'] ?>/actions"><input type="hidden" name="action" value="delete-draft"><button type="submit" class="seller-danger-action">Delete draft</button></form>
                <?php endif; ?>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <?php if ($pages > 1): ?><nav class="seller-pagination" aria-label="Listing pages"><?php if ($page > 1): ?><a href="<?= escape_html('/seller/listings?' . http_build_query(['status' => $status, 'q' => $query, 'category' => $category, 'type' => $type, 'page' => $page - 1])) ?>">← Previous</a><?php endif; ?><span>Page <?= $page ?> of <?= $pages ?></span><?php if ($page < $pages): ?><a href="<?= escape_html('/seller/listings?' . http_build_query(['status' => $status, 'q' => $query, 'category' => $category, 'type' => $type, 'page' => $page + 1])) ?>">Next →</a><?php endif; ?></nav><?php endif; ?>
    <?php endif; ?>
    <p class="dashboard-notice">Listings are only visible in the marketplace after administrator approval. Seller controls cannot approve a listing.</p>
  </main>
</body>
</html>
