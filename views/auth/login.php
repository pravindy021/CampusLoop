<?php
declare(strict_types=1);
$requestedMode = $_GET['mode'] ?? 'buyer';
$mode = is_string($requestedMode) && $requestedMode === 'seller' ? 'seller' : 'buyer';
$userId = current_user_id();
if ($userId !== null) {
    $roles = user_roles($userId);
    $destination = $mode === 'seller' && in_array('seller', $roles, true)
        ? '/seller/dashboard'
        : '/buyer/dashboard';
    header('Location: ' . $destination, true, 303);
    exit;
}
?>
<!doctype html>
<html lang="en-IN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in — CampusLoop</title>
  <link rel="stylesheet" href="/styles.css">
  <script src="/auth.js" defer></script>
</head>
<body>
  <main class="auth-page">
    <a class="brand" href="/"><span class="brand__mark" aria-hidden="true"><span></span><span></span><span></span></span><span class="brand__name">Campus<span>Loop</span></span></a>
    <section class="auth-card" aria-labelledby="auth-title">
      <p class="eyebrow" data-mode-label data-buyer-label="Buyer mode · welcome back" data-seller-label="Seller mode · welcome back"><?= $mode === 'seller' ? 'Seller mode · welcome back' : 'Buyer mode · welcome back' ?></p>
      <h1 id="auth-title">Sign in to your account.</h1>
      <p>Use your CampusLoop account to continue. One account can use both Buyer and Seller modes.</p>
      <form data-auth-form action="/api/v1/auth/login" method="post">
        <fieldset class="mode-choice">
          <legend>Continue as</legend>
          <label><input type="radio" name="mode" value="buyer" <?= $mode === 'buyer' ? 'checked' : '' ?>> Buyer</label>
          <label><input type="radio" name="mode" value="seller" <?= $mode === 'seller' ? 'checked' : '' ?>> Seller</label>
        </fieldset>
        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="email" required maxlength="254">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="255">
        <p class="auth-message" data-form-message aria-live="polite"></p>
        <button class="button button--dark" type="submit">Sign in <span aria-hidden="true">↗</span></button>
      </form>
      <p class="auth-switch">New to CampusLoop? <a href="/register?mode=<?= $mode ?>">Create an account</a></p>
    </section>
  </main>
</body>
</html>
