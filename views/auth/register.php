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
  <title>Create an account — CampusLoop</title>
  <link rel="stylesheet" href="/styles.css">
  <script src="/auth.js" defer></script>
</head>
<body>
  <main class="auth-page">
    <a class="brand" href="/"><span class="brand__mark" aria-hidden="true"><span></span><span></span><span></span></span><span class="brand__name">Campus<span>Loop</span></span></a>
    <section class="auth-card" aria-labelledby="auth-title">
      <p class="eyebrow" data-mode-label data-buyer-label="Buyer mode · start here" data-seller-label="Seller mode · one account"><?= $mode === 'seller' ? 'Seller mode · one account' : 'Buyer mode · start here' ?></p>
      <h1 id="auth-title">Create your account.</h1>
      <p>Create one account for CampusLoop. You can use both Buyer and Seller modes.</p>
      <form data-auth-form action="/api/v1/auth/register" method="post">
        <fieldset class="mode-choice">
          <legend>Start in</legend>
          <label><input type="radio" name="mode" value="buyer" <?= $mode === 'buyer' ? 'checked' : '' ?>> Buyer</label>
          <label><input type="radio" name="mode" value="seller" <?= $mode === 'seller' ? 'checked' : '' ?>> Seller</label>
        </fieldset>
        <label for="full-name">Full name</label>
        <input id="full-name" name="full_name" autocomplete="name" required minlength="2" maxlength="120">
        <label for="register-email">Email</label>
        <input id="register-email" name="email" type="email" autocomplete="email" required maxlength="254">
        <label for="mobile">Mobile number <span>(optional)</span></label>
        <input id="mobile" name="mobile_number" type="tel" autocomplete="tel" maxlength="24">
        <label for="college">School, college, or learning institute <span>(optional)</span></label>
        <input id="college" name="college" autocomplete="organization" maxlength="160">
        <label for="city">City <span>(optional)</span></label>
        <input id="city" name="city" autocomplete="address-level2" maxlength="120">
        <fieldset class="learner-preferences">
          <legend>Learning preferences <span>(optional)</span></legend>
          <p class="auth-hint">Share what you study to help tailor discovery later. No school or college ID is required.</p>
          <label for="education-type">Learner type</label>
          <select id="education-type" name="education_type">
            <option value="">Choose if you'd like</option>
            <option value="school">School student</option>
            <option value="college">College or university</option>
            <option value="competitive_exam">Competitive exam aspirant</option>
            <option value="other_learner">Other learner</option>
          </select>
          <label for="education-detail">Class, course, or learning area</label>
          <input id="education-detail" name="education_detail" maxlength="120" placeholder="e.g. Class 10, BTech, language learning">
          <label for="stream">Stream or subject area <span>(optional)</span></label>
          <input id="stream" name="stream" maxlength="120" placeholder="e.g. Science, Biology, Computer Science">
          <label for="exam-category">Exam preparation <span>(optional)</span></label>
          <input id="exam-category" name="exam_category" maxlength="120" placeholder="e.g. NEET, UPSC, State PCS">
          <label for="preferred-language">Preferred language <span>(optional)</span></label>
          <input id="preferred-language" name="preferred_language" maxlength="60" autocomplete="language">
          <label for="locality">Locality <span>(optional)</span></label>
          <input id="locality" name="locality" maxlength="120" autocomplete="address-level3">
        </fieldset>
        <label for="new-password">Password</label>
        <input id="new-password" name="password" type="password" autocomplete="new-password" required minlength="12" maxlength="72">
        <small class="auth-hint">Use at least 12 characters.</small>
        <label for="confirm-password">Confirm password</label>
        <input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" required minlength="12" maxlength="72">
        <label class="auth-terms"><input type="checkbox" name="terms_accepted" value="1" required> I accept the Terms and Privacy Policy. <span>Policy pages must be published before launch.</span></label>
        <p class="auth-message" data-form-message aria-live="polite"></p>
        <button class="button button--dark" type="submit">Create account <span aria-hidden="true">↗</span></button>
      </form>
      <p class="auth-switch">Already have an account? <a href="/login?mode=<?= $mode ?>">Sign in</a></p>
    </section>
  </main>
</body>
</html>
