<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Auth.php';
require_once __DIR__ . '/../app/Catalog.php';
require_once __DIR__ . '/../app/Wishlist.php';
require_once __DIR__ . '/../app/SellerListings.php';
require_once __DIR__ . '/../app/Messaging.php';

send_security_headers();
start_app_session();

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $staticFiles = [
        '/' => [dirname(__DIR__) . '/index.html', 'text/html; charset=utf-8'],
        '/styles.css' => [dirname(__DIR__) . '/styles.css', 'text/css; charset=utf-8'],
        '/app.js' => [dirname(__DIR__) . '/app.js', 'text/javascript; charset=utf-8'],
        '/auth.js' => [__DIR__ . '/auth.js', 'text/javascript; charset=utf-8'],
        '/buyer.js' => [__DIR__ . '/buyer.js', 'text/javascript; charset=utf-8'],
        '/seller.js' => [__DIR__ . '/seller.js', 'text/javascript; charset=utf-8'],
        '/messages.js' => [__DIR__ . '/messages.js', 'text/javascript; charset=utf-8'],
        '/notifications.js' => [__DIR__ . '/notifications.js', 'text/javascript; charset=utf-8'],
    ];
    if ($method === 'GET' && isset($staticFiles[$path])) {
        [$file, $contentType] = $staticFiles[$path];
        if (is_file($file)) {
            header('Content-Type: ' . $contentType);
            readfile($file);
            exit;
        }
    }
    if ($method === 'GET' && preg_match('#^/media/products/([a-f0-9]{32}\.(?:jpg|png|webp))$#D', $path, $matches)) {
        SellerListings::image($matches[1]);
    }

    if ($method === 'GET' && $path === '/api/v1/csrf') {
        json_response(['csrf_token' => csrf_token()]);
    }
    if ($method === 'GET' && $path === '/api/v1/categories') {
        Catalog::categories();
    }
    if ($method === 'GET' && $path === '/api/v1/products') {
        Catalog::search($_GET);
    }
    if ($method === 'GET' && $path === '/api/v1/conversations') {
        Messaging::conversations();
    }
    if ($method === 'POST' && $path === '/api/v1/conversations') {
        Messaging::startConversation(request_data());
    }
    if ($method === 'GET' && $path === '/api/v1/conversations/unread-count') {
        Messaging::unreadMessageCount();
    }
    if (preg_match('#^/api/v1/conversations/([1-9][0-9]*)/messages$#D', $path, $matches)) {
        $conversationId = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($conversationId === false) {
            json_response(['error' => 'Invalid conversation ID.'], 400);
        }
        if ($method === 'GET') {
            Messaging::messages($conversationId, $_GET);
        }
        if ($method === 'POST') {
            Messaging::sendMessage($conversationId, request_data());
        }
    }
    if (preg_match('#^/api/v1/conversations/([1-9][0-9]*)/block$#D', $path, $matches) && $method === 'POST') {
        $conversationId = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($conversationId === false) {
            json_response(['error' => 'Invalid conversation ID.'], 400);
        }
        Messaging::block($conversationId, request_data());
    }
    if (preg_match('#^/api/v1/conversations/([1-9][0-9]*)/report$#D', $path, $matches) && $method === 'POST') {
        $conversationId = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($conversationId === false) {
            json_response(['error' => 'Invalid conversation ID.'], 400);
        }
        Messaging::report($conversationId, request_data());
    }
    if ($method === 'GET' && $path === '/api/v1/notifications') {
        Messaging::notifications();
    }
    if ($method === 'GET' && $path === '/api/v1/notifications/unread-count') {
        Messaging::unreadNotificationCount();
    }
    if ($method === 'POST' && $path === '/api/v1/notifications/read-all') {
        Messaging::markAllNotificationsRead();
    }
    if ($method === 'POST' && preg_match('#^/api/v1/notifications/([1-9][0-9]*)/read$#D', $path, $matches)) {
        $notificationId = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($notificationId === false) {
            json_response(['error' => 'Invalid notification ID.'], 400);
        }
        Messaging::markNotificationRead($notificationId);
    }
    if ($method === 'POST' && $path === '/api/v1/auth/register') {
        Auth::register(request_data());
    }
    if ($method === 'POST' && $path === '/api/v1/auth/login') {
        Auth::login(request_data());
    }
    if ($method === 'POST' && $path === '/api/v1/auth/logout') {
        Auth::logout();
    }
    if ($method === 'GET' && $path === '/api/v1/auth/me') {
        Auth::current();
    }
    if ($method === 'POST' && $path === '/api/v1/seller/activate') {
        Auth::activate_seller();
    }
    if ($method === 'GET' && $path === '/api/v1/wishlist') {
        Wishlist::list();
    }
    if (preg_match('#^/api/v1/wishlist/([1-9][0-9]*)$#', $path, $matches)) {
        $productId = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($productId === false) {
            json_response(['error' => 'Invalid listing ID.'], 400);
        }
        if ($method === 'POST') {
            Wishlist::add($productId);
        }
        if ($method === 'DELETE') {
            Wishlist::remove($productId);
        }
    }
    if ($method === 'POST' && $path === '/api/v1/seller/listings') {
        $isMultipart = str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data');
        if ($isMultipart) {
            SellerListings::checkUploadRequestSize();
        }
        $input = $isMultipart ? $_POST : request_data();
        Catalog::create_listing($input);
    }
    if ($method === 'POST' && preg_match('#^/api/v1/seller/listings/([1-9][0-9]*)$#D', $path, $matches)) {
        $productId = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($productId === false) {
            json_response(['error' => 'Invalid listing ID.'], 400);
        }
        $isMultipart = str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data');
        if ($isMultipart) {
            SellerListings::checkUploadRequestSize();
        }
        $input = $isMultipart ? $_POST : request_data();
        SellerListings::save($input, $_FILES, $productId);
    }
    if ($method === 'POST' && preg_match('#^/api/v1/seller/listings/([1-9][0-9]*)/actions$#D', $path, $matches)) {
        $productId = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($productId === false) {
            json_response(['error' => 'Invalid listing ID.'], 400);
        }
        SellerListings::action($productId, request_data());
    }
    if ($method === 'GET' && $path === '/login') {
        require __DIR__ . '/../views/auth/login.php';
        exit;
    }
    if ($method === 'GET' && $path === '/register') {
        require __DIR__ . '/../views/auth/register.php';
        exit;
    }
    if ($method === 'GET' && $path === '/buyer/dashboard') {
        require __DIR__ . '/../views/dashboard/buyer.php';
        exit;
    }
    if ($method === 'GET' && $path === '/seller/dashboard') {
        require __DIR__ . '/../views/dashboard/seller.php';
        exit;
    }
    if ($method === 'GET' && $path === '/seller/listings') {
        require __DIR__ . '/../views/dashboard/seller-listings.php';
        exit;
    }
    if ($method === 'GET' && $path === '/seller/listings/new') {
        $sellerUserId = require_dashboard_role('seller');
        $listing = null;
        require __DIR__ . '/../views/dashboard/seller-listing-form.php';
        exit;
    }
    if ($method === 'GET' && preg_match('#^/seller/listings/([1-9][0-9]*)/edit$#D', $path, $matches)) {
        $sellerUserId = require_dashboard_role('seller');
        $productId = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($productId === false) {
            http_response_code(404);
            echo 'Listing not found.';
            exit;
        }
        $listing = SellerListings::listing_for_seller($sellerUserId, $productId);
        if ($listing === null || $listing['archived_at'] !== null
            || $listing['moderation_status'] === 'removed' || $listing['availability_status'] === 'sold') {
            http_response_code(404);
            echo 'Listing not found.';
            exit;
        }
        require __DIR__ . '/../views/dashboard/seller-listing-form.php';
        exit;
    }
    if ($method === 'GET' && $path === '/seller/orders') {
        require __DIR__ . '/../views/dashboard/seller-orders.php';
        exit;
    }
    if ($method === 'GET' && $path === '/seller/rentals') {
        require __DIR__ . '/../views/dashboard/seller-rentals.php';
        exit;
    }
    if ($method === 'GET' && $path === '/buyer/wishlist') {
        require __DIR__ . '/../views/dashboard/wishlist.php';
        exit;
    }
    if ($method === 'GET' && $path === '/buyer/orders') {
        require __DIR__ . '/../views/dashboard/orders.php';
        exit;
    }
    if ($method === 'GET' && $path === '/messages') {
        require __DIR__ . '/../views/dashboard/messages.php';
        exit;
    }
    if ($method === 'GET' && $path === '/notifications') {
        require __DIR__ . '/../views/dashboard/notifications.php';
        exit;
    }
    if ($method === 'GET' && preg_match('#^/products/([1-9][0-9]*)$#', $path, $matches)) {
        $productId = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($productId === false) {
            http_response_code(404);
            echo 'Listing not found.';
            exit;
        }
        require __DIR__ . '/../views/dashboard/product.php';
        exit;
    }
    if (str_starts_with($path, '/api/')) {
        json_response(['error' => 'API route not found.'], 404);
    }

    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Page not found.';
} catch (Throwable $exception) {
    error_log(sprintf(
        'Application error: %s (%s) at %s:%d',
        $exception::class,
        $exception->getCode(),
        $exception->getFile(),
        $exception->getLine()
    ));
    if (str_starts_with(parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/', '/api/')) {
        json_response(['error' => 'The request could not be completed. Check the application configuration.'], 500);
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'The request could not be completed.';
}
