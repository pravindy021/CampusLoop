<?php
declare(strict_types=1);

$sellerAccountStatement = database_connection()->prepare(
    'SELECT email, (SELECT full_name FROM user_profiles WHERE user_id = users.id) AS full_name
     FROM users WHERE id = :id LIMIT 1'
);
$sellerAccountStatement->execute(['id' => $sellerUserId]);
$sellerAccount = $sellerAccountStatement->fetch();
if (!is_array($sellerAccount)) {
    throw new RuntimeException('Authenticated seller could not be loaded.');
}
$sellerActiveTab = 'listings';
$categories = database_connection()->query(
    'SELECT id, name, slug FROM categories WHERE is_active = 1 ORDER BY sort_order, name'
)->fetchAll();
$subcategories = database_connection()->query(
    'SELECT id, category_id, name FROM subcategories WHERE is_active = 1 ORDER BY name'
)->fetchAll();
$education = is_array($listing['education'] ?? null) ? $listing['education'] : [];
$isEditing = is_array($listing);
$imageLimit = max(1, min(10, (int) (app_settings()['product_image_max_count'] ?? 5)));
$imageSizeLimit = max(1024, min(20971520, (int) (app_settings()['product_image_max_bytes'] ?? 5242880)));
$formAction = '/api/v1/seller/listings' . ($isEditing ? '/' . (int) $listing['id'] : '');
$selectedCategory = (int) ($listing['category_id'] ?? 0);
$selectedSubcategory = (int) ($listing['subcategory_id'] ?? 0);
$currentImages = is_array($listing['images'] ?? null) ? $listing['images'] : [];
$primaryImageId = $currentImages === [] ? null : (int) $currentImages[0]['id'];
$submissionToken = bin2hex(random_bytes(32));
?>
<!doctype html>
<html lang="en-IN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $isEditing ? 'Edit listing' : 'Add a listing' ?> — CampusLoop</title>
  <link rel="stylesheet" href="/styles.css">
  <script src="/auth.js" defer></script>
  <script src="/seller.js" defer></script>
</head>
<body class="dashboard-page">
  <?php require __DIR__ . '/_seller-header.php'; ?>
  <main class="dashboard-main seller-main">
    <section class="seller-page-heading">
      <div><p class="eyebrow"><?= $isEditing ? 'Listing management' : 'Grow your store' ?></p><h1><?= $isEditing ? 'Edit listing' : 'Add a new listing' ?></h1><p>Clear details help students understand exactly what you are offering.</p></div>
      <a class="dashboard-link" href="/seller/listings">← Back to My Listings</a>
    </section>
    <?php if ($isEditing && $listing['moderation_status'] === 'rejected' && !empty($listing['rejection_reason'])): ?>
      <p class="seller-rejection"><strong>Review note:</strong> <?= escape_html((string) $listing['rejection_reason']) ?> Update the listing and submit it for review again.</p>
    <?php endif; ?>
    <form class="seller-listing-form" id="seller-listing-form" action="<?= escape_html($formAction) ?>" method="post" enctype="multipart/form-data" data-max-images="<?= $imageLimit ?>" data-max-image-bytes="<?= $imageSizeLimit ?>">
      <input type="hidden" name="_csrf" value="<?= escape_html(csrf_token()) ?>">
      <input type="hidden" name="submission_token" value="<?= escape_html($submissionToken) ?>">
      <section class="seller-form-section">
        <div class="seller-form-section__heading"><span>01</span><div><h2>Basic information</h2><p>Tell buyers what the product is and how you want to list it.</p></div></div>
        <div class="seller-form-grid">
          <label class="seller-field seller-field--wide">Product title
            <input name="title" type="text" minlength="3" maxlength="180" required value="<?= escape_html((string) ($listing['title'] ?? '')) ?>" placeholder="e.g. Calculus textbook, 8th edition">
          </label>
          <label class="seller-field">Category
            <select name="category_id" id="seller-category" required>
              <option value="">Choose category</option>
              <?php foreach ($categories as $categoryRow): ?>
                <option value="<?= (int) $categoryRow['id'] ?>" data-category-slug="<?= escape_html((string) $categoryRow['slug']) ?>"<?= $selectedCategory === (int) $categoryRow['id'] ? ' selected' : '' ?>><?= escape_html((string) $categoryRow['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="seller-field">Subcategory <span class="seller-optional">Optional</span>
            <select name="subcategory_id" id="seller-subcategory">
              <option value="">No subcategory</option>
              <?php foreach ($subcategories as $subcategory): ?>
                <option value="<?= (int) $subcategory['id'] ?>" data-parent-category="<?= (int) $subcategory['category_id'] ?>"<?= $selectedSubcategory === (int) $subcategory['id'] ? ' selected' : '' ?>><?= escape_html((string) $subcategory['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="seller-field seller-field--wide">Detailed description
            <textarea name="description" rows="6" minlength="10" maxlength="10000" required placeholder="Describe the product, its use, and anything a buyer should know."><?= escape_html((string) ($listing['description'] ?? '')) ?></textarea>
          </label>
          <label class="seller-field">Condition
            <select name="condition" required>
              <?php $condition = (string) ($listing['condition_label'] ?? ''); ?>
              <option value="">Choose condition</option>
              <?php foreach (['new' => 'New', 'like_new' => 'Like new', 'good' => 'Good', 'fair' => 'Fair'] as $value => $label): ?>
                <option value="<?= $value ?>"<?= $condition === $value ? ' selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="seller-field">Listing type
            <?php $listingType = (string) ($listing['listing_type'] ?? ''); ?>
            <select name="listing_type" id="seller-listing-type" required>
              <option value="">Choose sale or rental</option>
              <option value="sell_only"<?= $listingType === 'sell_only' ? ' selected' : '' ?>>Sell only</option>
              <option value="rent_only"<?= $listingType === 'rent_only' ? ' selected' : '' ?>>Rent only</option>
              <option value="rent_and_sell"<?= $listingType === 'rent_and_sell' ? ' selected' : '' ?>>Sell and rent</option>
            </select>
          </label>
          <label class="seller-field">Quantity
            <input name="quantity" type="number" min="1" max="99" step="1" required value="<?= escape_html((string) ($listing['quantity'] ?? '1')) ?>">
          </label>
        </div>
      </section>

      <section class="seller-form-section">
        <div class="seller-form-section__heading"><span>02</span><div><h2>Product images</h2><p>JPEG, PNG, or WebP only. Maximum <?= $imageLimit ?> images, <?= number_format($imageSizeLimit / 1048576, 1) ?> MB each.</p></div></div>
        <?php if ($currentImages !== []): ?>
          <div class="seller-image-grid seller-image-grid--existing">
            <?php foreach ($currentImages as $image): ?>
              <?php $safeImagePath = preg_match('#^/media/products/[a-f0-9]{32}\.(?:jpg|png|webp)$#D', (string) $image['image_path']) === 1; ?>
              <figure class="seller-image-preview">
                <?php if ($safeImagePath): ?><img src="<?= escape_html((string) $image['image_path']) ?>" alt="" loading="lazy"><?php else: ?><span>Legacy image path unavailable</span><?php endif; ?>
                <label><input type="radio" name="primary_image" value="image-<?= (int) $image['id'] ?>"<?= (int) $image['id'] === $primaryImageId ? ' checked' : '' ?>> Primary</label>
                <label class="seller-image-remove"><input type="checkbox" name="remove_images[]" value="<?= (int) $image['id'] ?>"> Remove</label>
              </figure>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <label class="seller-upload">Choose product images
          <input id="seller-images" name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple>
        </label>
        <div class="seller-image-grid" id="seller-image-preview" aria-live="polite"></div>
        <p class="seller-form-hint">Images are validated on the server and stored outside the public web root. The first selected image is primary unless you select another.</p>
      </section>

      <section class="seller-form-section">
        <div class="seller-form-section__heading"><span>03</span><div><h2>Pricing and rental terms</h2><p>Enter only prices relevant to your listing type.</p></div></div>
        <div class="seller-form-grid">
          <label class="seller-field" data-seller-sale-field>Sale price
            <input name="sale_price" type="number" min="0.01" max="100000000" step="0.01" inputmode="decimal" value="<?= escape_html((string) ($listing['sale_price'] ?? '')) ?>" placeholder="Amount">
          </label>
          <label class="seller-field" data-seller-rental-field>Daily rental price
            <input name="daily_rental_price" type="number" min="0.01" max="100000000" step="0.01" inputmode="decimal" value="<?= escape_html((string) ($listing['daily_rental_price'] ?? '')) ?>" placeholder="Amount per day">
          </label>
          <label class="seller-field" data-seller-rental-field>Weekly rental price <span class="seller-optional">Optional</span>
            <input name="weekly_rental_price" type="number" min="0.01" max="100000000" step="0.01" inputmode="decimal" value="<?= escape_html((string) ($listing['weekly_rental_price'] ?? '')) ?>" placeholder="Amount per week">
          </label>
          <label class="seller-field" data-seller-rental-field>Monthly rental price <span class="seller-optional">Optional</span>
            <input name="monthly_rental_price" type="number" min="0.01" max="100000000" step="0.01" inputmode="decimal" value="<?= escape_html((string) ($listing['monthly_rental_price'] ?? '')) ?>" placeholder="Amount per month">
          </label>
          <label class="seller-field" data-seller-rental-field>Refundable deposit <span class="seller-optional">Optional</span>
            <input name="security_deposit" type="number" min="0" max="100000000" step="0.01" inputmode="decimal" value="<?= escape_html((string) ($listing['security_deposit'] ?? '0')) ?>" placeholder="Amount">
          </label>
          <label class="seller-field" data-seller-rental-field>Minimum rental days <span class="seller-optional">Optional</span>
            <input name="minimum_rental_days" type="number" min="1" max="3650" step="1" value="<?= escape_html((string) ($listing['minimum_rental_days'] ?? '')) ?>">
          </label>
          <label class="seller-field" data-seller-rental-field>Maximum rental days <span class="seller-optional">Optional</span>
            <input name="maximum_rental_days" type="number" min="1" max="3650" step="1" value="<?= escape_html((string) ($listing['maximum_rental_days'] ?? '')) ?>">
          </label>
        </div>
        <p class="seller-form-hint">The database does not record per-listing currency. No deposit is collected or held by CampusLoop.</p>
      </section>

      <section class="seller-form-section" id="seller-education-fields">
        <div class="seller-form-section__heading"><span>04</span><div><h2>Learning and product details</h2><p>Optional details help learners find books, technology, and other student essentials.</p></div></div>
        <div class="seller-form-grid">
          <label class="seller-field">Learner audience <span class="seller-optional">Optional</span>
            <select name="education_type">
              <option value="">All learners</option>
              <?php foreach (['school' => 'School', 'college' => 'College or university', 'competitive_exam' => 'Competitive exams', 'other_learner' => 'Other learners'] as $value => $label): ?>
                <option value="<?= $value ?>"<?= ($education['education_type'] ?? '') === $value ? ' selected' : '' ?>><?= escape_html($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php foreach ([
              'class_level' => ['Class or level', 80],
              'institution' => ['Institution', 160],
              'course' => ['Course', 120],
              'branch' => ['Branch or department', 120],
              'semester' => ['Semester or year', 40],
              'stream' => ['Stream', 120],
              'exam_category' => ['Exam', 120],
              'subject' => ['Subject', 120],
              'brand' => ['Brand', 120],
              'locality' => ['Locality', 120],
          ] as $name => [$label, $maxLength]): ?>
            <label class="seller-field"><?= escape_html($label) ?><span class="seller-optional">Optional</span><input name="<?= $name ?>" type="text" maxlength="<?= $maxLength ?>" value="<?= escape_html((string) ($education[$name] ?? '')) ?>"></label>
          <?php endforeach; ?>
        </div>
        <label class="seller-sharing-rights" data-study-material-terms>
          <input type="checkbox" name="sharing_rights_confirmed" value="1"<?= !empty($education['sharing_rights_confirmed']) ? ' checked' : '' ?>>
          I have the right to resell or share this book or learning material, and it does not contain unauthorized copies.
        </label>
      </section>

      <section class="seller-form-section">
        <div class="seller-form-section__heading"><span>05</span><div><h2>Location and handover</h2><p>Use a city only—do not enter a residential street address.</p></div></div>
        <div class="seller-form-grid">
          <label class="seller-field">City<input name="city" type="text" maxlength="120" required value="<?= escape_html((string) ($listing['city'] ?? '')) ?>" placeholder="e.g. Pune"></label>
          <label class="seller-field">Country code<input name="country_code" type="text" pattern="[A-Za-z]{2}" maxlength="2" required value="<?= escape_html((string) ($listing['country_code'] ?? 'IN')) ?>" placeholder="IN"></label>
          <fieldset class="seller-field seller-field--wide seller-handover"><legend>Handover options</legend>
            <label><input type="checkbox" name="pickup_available" value="1"<?= !isset($listing['pickup_available']) || (bool) $listing['pickup_available'] ? ' checked' : '' ?>> Pickup available</label>
            <label><input type="checkbox" name="delivery_available" value="1"<?= !empty($listing['delivery_available']) ? ' checked' : '' ?>> Delivery available</label>
          </fieldset>
        </div>
      </section>

      <p class="seller-form-message" id="seller-form-message" role="alert" aria-live="polite"></p>
      <div class="seller-form-actions">
        <a class="button button--outline" href="/seller/listings">Cancel</a>
        <button class="button button--outline" type="submit" name="intent" value="draft">Save Draft</button>
        <button class="button button--lime" type="submit" name="intent" value="submit"><?= $isEditing && $listing['moderation_status'] === 'rejected' ? 'Resubmit for Approval' : 'Submit for Approval' ?></button>
      </div>
    </form>
  </main>
</body>
</html>
