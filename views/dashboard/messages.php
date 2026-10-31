<?php
declare(strict_types=1);

$userId = current_user_id();
if ($userId === null) {
    header('Location: /login?mode=buyer', true, 303);
    exit;
}
$userId = require_user();
$accountStatement = database_connection()->prepare(
    'SELECT email, (SELECT full_name FROM user_profiles WHERE user_id = users.id) AS full_name
     FROM users WHERE id = :id LIMIT 1'
);
$accountStatement->execute(['id' => $userId]);
$account = $accountStatement->fetch();
if (!is_array($account)) {
    throw new RuntimeException('Authenticated account could not be loaded.');
}
?>
<!doctype html>
<html lang="en-IN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f5f4ed">
  <title>Messages — CampusLoop</title>
  <link rel="stylesheet" href="/styles.css">
  <script src="/auth.js" defer></script>
  <script src="/notifications.js" defer></script>
  <script src="/messages.js" defer></script>
</head>
<body class="dashboard-page messages-page">
  <header class="dashboard-header">
    <a class="brand" href="/" aria-label="CampusLoop home"><span class="brand__mark" aria-hidden="true"><span></span><span></span><span></span></span><span class="brand__name">Campus<span>Loop</span></span></a>
    <nav class="dashboard-header__actions" aria-label="Account navigation">
      <a class="dashboard-link" href="/buyer/dashboard">Marketplace</a>
      <a class="dashboard-link" href="/buyer/orders">My orders</a>
      <a class="dashboard-link" href="/messages">Messages <span class="nav-count" data-message-unread-count hidden></span></a>
      <a class="dashboard-link" href="/notifications">Notifications <span class="nav-count" data-notification-unread-count hidden></span></a>
      <span class="notification-count-error" data-count-status role="status" aria-live="polite"></span>
      <span class="dashboard-account"><?= escape_html((string) ($account['full_name'] ?: $account['email'])) ?></span>
    </nav>
  </header>
  <main class="dashboard-main messages-main">
    <section class="dashboard-section">
      <div class="dashboard-section__heading">
        <div><p class="eyebrow">CampusLoop conversations</p><h1>Messages</h1></div>
        <p class="messages-poll-status" id="messages-poll-status" role="status" aria-live="polite">Conversations refresh automatically.</p>
      </div>
      <p class="buyer-error" id="messages-error" role="alert" hidden></p>
      <div class="messages-layout">
        <aside class="conversation-sidebar" aria-labelledby="conversation-list-title">
          <h2 id="conversation-list-title">Your conversations</h2>
          <div class="conversation-list" id="conversation-list" aria-live="polite">
            <p class="buyer-empty">Loading conversations…</p>
          </div>
        </aside>
        <section class="message-thread" aria-label="Conversation">
          <header class="message-thread__header">
            <div><p class="eyebrow" id="message-product-category">Product conversation</p><h2 id="message-thread-title">Choose a conversation</h2><p id="message-other-user"></p></div>
            <div class="message-thread__actions" id="message-thread-actions" hidden>
              <button class="button button--outline" id="message-block-button" type="button">Block</button>
              <details class="message-report">
                <summary>Report</summary>
                <form id="message-report-form">
                  <label>Reason
                    <select name="reason" required>
                      <option value="">Choose a reason</option>
                      <option value="harassment">Harassment or abusive messages</option>
                      <option value="scam">Scam or suspicious request</option>
                      <option value="unsafe_content">Unsafe or inappropriate content</option>
                      <option value="other">Other concern</option>
                    </select>
                  </label>
                  <label>Details <span class="seller-optional">Optional</span>
                    <textarea name="details" maxlength="2000" rows="3"></textarea>
                  </label>
                  <button class="button button--dark" type="submit">Submit report</button>
                </form>
              </details>
            </div>
          </header>
          <button class="button button--outline message-load-older" id="message-load-older" type="button" hidden>Load earlier messages</button>
          <div class="message-feed" id="message-feed" aria-live="polite" aria-relevant="additions text">
            <p class="buyer-empty">Select a conversation to read and reply.</p>
          </div>
          <form class="message-composer" id="message-composer" hidden>
            <label class="visually-hidden" for="message-input">Write a message</label>
            <textarea id="message-input" name="message" rows="3" maxlength="2000" required placeholder="Write a message about this listing…"></textarea>
            <div><small>Never share passwords or payment credentials in chat.</small><button class="button button--lime" type="submit">Send message</button></div>
          </form>
          <p class="dashboard-message" id="message-status" role="status" aria-live="polite"></p>
        </section>
      </div>
    </section>
  </main>
</body>
</html>
