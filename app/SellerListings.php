<?php
declare(strict_types=1);

final class SellerListings
{
    private const MAX_IMAGE_COUNT = 5;
    private const MAX_IMAGE_BYTES = 5242880;
    private const ALLOWED_IMAGE_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public static function checkUploadRequestSize(): void
    {
        $contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
        $postLimit = self::iniSizeBytes((string) ini_get('post_max_size'));
        if ($contentLength !== false && $postLimit > 0 && $contentLength > $postLimit) {
            json_response(['error' => 'The combined form and images exceed the server upload limit. Choose fewer or smaller images.'], 413);
        }
    }

    public static function save(array $input, array $files, ?int $productId = null): never
    {
        require_csrf();
        $sellerId = require_role('seller');
        $data = self::validateListing($input);
        $submissionToken = (string) ($input['submission_token'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $submissionToken)) {
            json_response(['error' => 'Refresh the listing form before submitting it.'], 422);
        }
        $pdo = database_connection();
        $existing = $productId === null ? null : self::findOwnedListing($pdo, $sellerId, $productId);
        if ($productId !== null && $existing === null) {
            json_response(['error' => 'That listing could not be found.'], 404);
        }
        $priorSubmission = $_SESSION['seller_listing_submissions'][$submissionToken] ?? null;
        if (is_array($priorSubmission)) {
            if ($priorSubmission['seller_id'] !== $sellerId || $priorSubmission['submitted_product_id'] !== $productId) {
                json_response(['error' => 'This form submission has already been used. Refresh before trying again.'], 409);
            }
            $_SESSION['seller_listing_flash'] = $priorSubmission['message'];
            json_response([
                'message' => $priorSubmission['message'],
                'id' => $priorSubmission['product_id'],
                'redirect_url' => '/seller/listings',
            ]);
        }
        if ($existing !== null && $existing['archived_at'] !== null) {
            json_response(['error' => 'Archived listings cannot be edited.'], 409);
        }
        if ($existing !== null && ($existing['moderation_status'] === 'removed'
            || $existing['availability_status'] === 'sold')) {
            json_response(['error' => 'Removed or sold listings cannot be edited.'], 409);
        }
        if ($existing !== null && self::hasInProgressTransactions($pdo, $sellerId, (int) $productId)) {
            json_response(['error' => 'This listing has an in-progress order or rental and cannot be edited yet.'], 409);
        }
        $intent = (string) ($input['intent'] ?? 'submit');
        if (!in_array($intent, ['draft', 'submit'], true)) {
            json_response(['error' => 'Choose Save Draft or Submit for Approval.'], 422);
        }
        if ($intent === 'draft' && $existing !== null
            && !in_array($existing['moderation_status'], ['draft', 'rejected'], true)) {
            json_response(['error' => 'Only draft or rejected listings can be saved as drafts.'], 422);
        }

        $uploads = self::validateUploads($files);
        $currentImages = $existing === null ? [] : self::imagesForProduct($pdo, $productId);
        $removeIds = self::validatedImageRemovals($input, $currentImages);
        $keptImages = array_values(array_filter(
            $currentImages,
            static fn (array $image): bool => !in_array((int) $image['id'], $removeIds, true)
        ));
        if (count($keptImages) + count($uploads) > self::imageLimit()) {
            json_response(['error' => 'A listing can have at most ' . self::imageLimit() . ' images.'], 422);
        }
        $primary = (string) ($input['primary_image'] ?? '');
        $availablePrimaryIds = array_map(static fn (array $image): string => 'image-' . (int) $image['id'], $keptImages);
        $availablePrimaryIds = array_merge(
            $availablePrimaryIds,
            array_map(static fn (int $index): string => 'new-' . $index, array_keys($uploads))
        );
        if ($primary !== '' && !in_array($primary, $availablePrimaryIds, true)) {
            json_response(['error' => 'Choose a primary image that will remain on this listing.'], 422);
        }

        $storedUploads = self::moveUploads($uploads);
        $createdPaths = array_column($storedUploads, 'path');
        try {
            $pdo->beginTransaction();
            $moderationStatus = $intent === 'draft' ? 'draft' : 'pending_approval';
            $availabilityStatus = 'available';
            if ($existing === null) {
                $statement = $pdo->prepare(
                    'INSERT INTO products
                        (seller_id, category_id, subcategory_id, title, description, condition_label,
                         listing_type, sale_price, daily_rental_price, weekly_rental_price, monthly_rental_price,
                         security_deposit, minimum_rental_days, maximum_rental_days, quantity,
                         country_code, city, pickup_available, delivery_available,
                         moderation_status, availability_status, rejection_reason)
                     VALUES
                        (:seller_id, :category_id, :subcategory_id, :title, :description, :condition,
                         :listing_type, :sale_price, :daily_price, :weekly_price, :monthly_price,
                         :deposit, :minimum_days, :maximum_days, :quantity, :country, :city,
                         :pickup, :delivery, :moderation_status, :availability_status, NULL)'
                );
                $statement->execute(self::productParameters($data, $sellerId, $moderationStatus, $availabilityStatus));
                $productId = (int) $pdo->lastInsertId();
            } else {
                $statement = $pdo->prepare(
                    'UPDATE products SET
                        category_id = :category_id, subcategory_id = :subcategory_id,
                        title = :title, description = :description, condition_label = :condition,
                        listing_type = :listing_type, sale_price = :sale_price,
                        daily_rental_price = :daily_price, weekly_rental_price = :weekly_price,
                        monthly_rental_price = :monthly_price, security_deposit = :deposit,
                        minimum_rental_days = :minimum_days, maximum_rental_days = :maximum_days,
                        quantity = :quantity, country_code = :country, city = :city,
                        pickup_available = :pickup, delivery_available = :delivery,
                        moderation_status = :moderation_status, availability_status = :availability_status,
                        rejection_reason = NULL, moderated_by = NULL, moderated_at = NULL
                     WHERE id = :id AND seller_id = :seller_id AND archived_at IS NULL'
                );
                $statement->execute(self::productParameters($data, $sellerId, $moderationStatus, $availabilityStatus)
                    + ['id' => $productId]);
                self::saveEducationDetails($pdo, $productId, $data);
            }

            if ($existing === null) {
                self::saveEducationDetails($pdo, $productId, $data);
            }

            if ($removeIds !== []) {
                $delete = $pdo->prepare(
                    'DELETE FROM product_images WHERE product_id = :product_id AND id = :image_id'
                );
                foreach ($removeIds as $imageId) {
                    $delete->execute(['product_id' => $productId, 'image_id' => $imageId]);
                }
            }
            $sortOrder = 0;
            $savedImages = [];
            foreach ($keptImages as $image) {
                $savedImages[] = [
                    'id' => (int) $image['id'],
                    'path' => (string) $image['image_path'],
                    'token' => 'image-' . (int) $image['id'],
                ];
            }
            foreach ($storedUploads as $uploadIndex => $upload) {
                $statement = $pdo->prepare(
                    'INSERT INTO product_images (product_id, image_path, alt_text, sort_order)
                     VALUES (:product_id, :image_path, :alt_text, :sort_order)'
                );
                $statement->execute([
                    'product_id' => $productId,
                    'image_path' => $upload['path'],
                    'alt_text' => null,
                    'sort_order' => $sortOrder++,
                ]);
                $imageId = (int) $pdo->lastInsertId();
                $savedImages[] = [
                    'id' => $imageId,
                    'path' => $upload['path'],
                    'token' => 'image-' . $imageId,
                    'upload_token' => 'new-' . $uploadIndex,
                ];
            }

            if ($primary !== '') {
                usort($savedImages, static function (array $left, array $right) use ($primary): int {
                    $leftPrimary = $primary === $left['token'] || $primary === ($left['upload_token'] ?? '');
                    $rightPrimary = $primary === $right['token'] || $primary === ($right['upload_token'] ?? '');
                    return $rightPrimary <=> $leftPrimary;
                });
            }
            $orderStatement = $pdo->prepare(
                'UPDATE product_images SET sort_order = :sort_order WHERE id = :image_id AND product_id = :product_id'
            );
            foreach ($savedImages as $index => $image) {
                $orderStatement->execute([
                    'sort_order' => $index,
                    'image_id' => $image['id'],
                    'product_id' => $productId,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::removeStoredFiles($createdPaths);
            throw $exception;
        }

        $removedPaths = array_column(array_filter(
            $currentImages,
            static fn (array $image): bool => in_array((int) $image['id'], $removeIds, true)
        ), 'image_path');
        self::removeStoredFiles($removedPaths);
        $message = $intent === 'draft' ? 'Draft saved.' : 'Listing submitted for approval.';
        $_SESSION['seller_listing_flash'] = $message;
        $_SESSION['seller_listing_submissions'] ??= [];
        $_SESSION['seller_listing_submissions'][$submissionToken] = [
            'seller_id' => $sellerId,
            'submitted_product_id' => $existing === null ? null : (int) $existing['id'],
            'product_id' => $productId,
            'message' => $message,
        ];
        $_SESSION['seller_listing_submissions'] = array_slice(
            $_SESSION['seller_listing_submissions'],
            -32,
            null,
            true
        );
        json_response([
            'message' => $message,
            'id' => $productId,
            'redirect_url' => '/seller/listings',
        ], $existing === null ? 201 : 200);
    }

    public static function action(int $productId, array $input): never
    {
        require_csrf();
        $sellerId = require_role('seller');
        $action = (string) ($input['action'] ?? '');
        $pdo = database_connection();
        $listing = self::findOwnedListing($pdo, $sellerId, $productId);
        if ($listing === null) {
            json_response(['error' => 'That listing could not be found.'], 404);
        }
        if ($action === 'archive' && self::hasInProgressTransactions($pdo, $sellerId, $productId)) {
            json_response(['error' => 'This listing has an in-progress order or rental and cannot be archived yet.'], 409);
        }

        $statement = match ($action) {
            'pause' => $pdo->prepare(
                'UPDATE products SET availability_status = "paused"
                 WHERE id = :id AND seller_id = :seller_id AND moderation_status = "published"
                   AND availability_status = "available" AND archived_at IS NULL'
            ),
            'resume' => $pdo->prepare(
                'UPDATE products SET availability_status = "available"
                 WHERE id = :id AND seller_id = :seller_id AND moderation_status = "published"
                   AND availability_status = "paused" AND archived_at IS NULL'
            ),
            'archive' => $pdo->prepare(
                'UPDATE products SET archived_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND seller_id = :seller_id AND archived_at IS NULL'
            ),
            default => null,
        };
        if ($action === 'delete-draft') {
            if ($listing['moderation_status'] !== 'draft') {
                json_response(['error' => 'Only a draft listing can be deleted.'], 409);
            }
            $paths = array_column(self::imagesForProduct($pdo, $productId), 'image_path');
            $delete = $pdo->prepare(
                'DELETE FROM products WHERE id = :id AND seller_id = :seller_id AND moderation_status = "draft"'
            );
            $delete->execute(['id' => $productId, 'seller_id' => $sellerId]);
            if ($delete->rowCount() !== 1) {
                json_response(['error' => 'The draft could not be deleted. Refresh and try again.'], 409);
            }
            self::removeStoredFiles($paths);
            $_SESSION['seller_listing_flash'] = 'Draft deleted.';
            json_response(['message' => 'Draft deleted.']);
        }
        if (!$statement instanceof PDOStatement) {
            json_response(['error' => 'Choose a supported listing action.'], 422);
        }
        $statement->execute(['id' => $productId, 'seller_id' => $sellerId]);
        if ($statement->rowCount() !== 1) {
            json_response(['error' => 'That listing cannot make this status change.'], 409);
        }
        $message = match ($action) {
            'pause' => 'Listing paused.',
            'resume' => 'Listing resumed.',
            'archive' => 'Listing archived.',
        };
        $_SESSION['seller_listing_flash'] = $message;
        json_response(['message' => $message]);
    }

    public static function image(string $filename): never
    {
        if (!preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/D', $filename)) {
            http_response_code(404);
            exit;
        }
        $statement = database_connection()->prepare(
            'SELECT p.seller_id, p.moderation_status, p.availability_status, p.archived_at, p.quantity
             FROM product_images pi INNER JOIN products p ON p.id = pi.product_id
             INNER JOIN users seller ON seller.id = p.seller_id AND seller.account_status = "active"
             INNER JOIN categories c ON c.id = p.category_id AND c.is_active = 1
             WHERE pi.image_path = :path LIMIT 1'
        );
        $statement->execute(['path' => '/media/products/' . $filename]);
        $listing = $statement->fetch();
        if (!is_array($listing)) {
            http_response_code(404);
            exit;
        }
        $isPublic = $listing['moderation_status'] === 'published'
            && $listing['availability_status'] === 'available'
            && (int) $listing['quantity'] > 0
            && $listing['archived_at'] === null;
        if (!$isPublic) {
            $viewerId = current_user_id();
            if ($viewerId !== (int) $listing['seller_id'] || !in_array('seller', user_roles($viewerId), true)) {
                http_response_code(404);
                exit;
            }
        }
        $path = self::uploadDirectory() . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            http_response_code(404);
            exit;
        }
        $mime = match (pathinfo($filename, PATHINFO_EXTENSION)) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        };
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=300');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }

    public static function listing_for_seller(int $sellerId, int $productId): ?array
    {
        $pdo = database_connection();
        $listing = self::findOwnedListing($pdo, $sellerId, $productId);
        if ($listing === null) {
            return null;
        }
        $education = $pdo->prepare('SELECT * FROM product_education WHERE product_id = :id');
        $education->execute(['id' => $productId]);
        $listing['education'] = $education->fetch() ?: [];
        $listing['images'] = self::imagesForProduct($pdo, $productId);
        return $listing;
    }

    private static function validateListing(array $input): array
    {
        foreach (['title', 'description', 'listing_type', 'condition', 'category_id', 'subcategory_id',
            'sale_price', 'daily_rental_price', 'weekly_rental_price', 'monthly_rental_price',
            'security_deposit', 'minimum_rental_days', 'maximum_rental_days', 'quantity',
            'country_code', 'city', 'pickup_available', 'delivery_available', 'education_type',
            'class_level', 'institution', 'course', 'branch', 'semester', 'stream', 'exam_category',
            'subject', 'brand', 'locality', 'sharing_rights_confirmed', 'intent', 'primary_image',
            'submission_token'] as $field) {
            if (isset($input[$field]) && !is_scalar($input[$field])) {
                json_response(['error' => 'Listing fields must contain single values.'], 422);
            }
        }
        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $listingType = (string) ($input['listing_type'] ?? '');
        $condition = (string) ($input['condition'] ?? '');
        if (mb_strlen($title) < 3 || mb_strlen($title) > 180) {
            json_response(['error' => 'Title must be between 3 and 180 characters.'], 422);
        }
        if (mb_strlen($description) < 10 || mb_strlen($description) > 10000) {
            json_response(['error' => 'Description must be between 10 and 10,000 characters.'], 422);
        }
        if (!in_array($listingType, ['rent_only', 'sell_only', 'rent_and_sell'], true)
            || !in_array($condition, ['new', 'like_new', 'good', 'fair'], true)) {
            json_response(['error' => 'Choose a valid listing type and condition.'], 422);
        }
        $salePrice = self::optionalAmount($input['sale_price'] ?? null);
        $dailyPrice = self::optionalAmount($input['daily_rental_price'] ?? null);
        $weeklyPrice = self::optionalAmount($input['weekly_rental_price'] ?? null);
        $monthlyPrice = self::optionalAmount($input['monthly_rental_price'] ?? null);
        $deposit = self::optionalAmount($input['security_deposit'] ?? null, true) ?? '0.00';
        $minimumDays = self::optionalInteger($input['minimum_rental_days'] ?? null, 1, 3650);
        $maximumDays = self::optionalInteger($input['maximum_rental_days'] ?? null, 1, 3650);
        $quantity = self::optionalInteger($input['quantity'] ?? 1, 1, 99) ?? 1;
        $hasSale = in_array($listingType, ['sell_only', 'rent_and_sell'], true);
        $hasRental = in_array($listingType, ['rent_only', 'rent_and_sell'], true);
        if ($hasSale !== ($salePrice !== null) || $hasRental !== ($dailyPrice !== null)) {
            json_response(['error' => 'Enter prices that match the selected sale or rental options.'], 422);
        }
        if (!$hasRental && ($weeklyPrice !== null || $monthlyPrice !== null || (float) $deposit > 0
            || $minimumDays !== null || $maximumDays !== null)) {
            json_response(['error' => 'Rental terms can only be set on rental-enabled listings.'], 422);
        }
        if ($minimumDays !== null && $maximumDays !== null && $maximumDays < $minimumDays) {
            json_response(['error' => 'Maximum rental duration cannot be shorter than the minimum.'], 422);
        }
        $categoryId = filter_var($input['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $subcategoryId = ($input['subcategory_id'] ?? '') === ''
            ? null
            : filter_var($input['subcategory_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($categoryId === false || $subcategoryId === false) {
            json_response(['error' => 'Choose a valid category and subcategory.'], 422);
        }
        $country = strtoupper(trim((string) ($input['country_code'] ?? 'IN')));
        $city = trim((string) ($input['city'] ?? ''));
        if (!preg_match('/^[A-Z]{2}$/D', $country) || $city === '' || mb_strlen($city) > 120) {
            json_response(['error' => 'Enter a two-letter country code and a city of up to 120 characters.'], 422);
        }
        $pickup = self::booleanField($input['pickup_available'] ?? '0');
        $delivery = self::booleanField($input['delivery_available'] ?? '0');
        if (!$pickup && !$delivery) {
            json_response(['error' => 'Enable pickup, delivery, or both.'], 422);
        }
        $pdo = database_connection();
        $category = $pdo->prepare('SELECT slug FROM categories WHERE id = :id AND is_active = 1');
        $category->execute(['id' => $categoryId]);
        $categorySlug = $category->fetchColumn();
        if (!is_string($categorySlug)) {
            json_response(['error' => 'That category is not available.'], 422);
        }
        if ($subcategoryId !== null) {
            $subcategory = $pdo->prepare(
                'SELECT 1 FROM subcategories WHERE id = :id AND category_id = :category_id AND is_active = 1'
            );
            $subcategory->execute(['id' => $subcategoryId, 'category_id' => $categoryId]);
            if (!$subcategory->fetchColumn()) {
                json_response(['error' => 'That subcategory does not belong to the selected category.'], 422);
            }
        }
        $sharingRightsConfirmed = self::booleanField($input['sharing_rights_confirmed'] ?? '0');
        if ($categorySlug === 'college-school' && !$sharingRightsConfirmed) {
            json_response(['error' => 'Confirm that you have the right to share or resell this learning material.'], 422);
        }
        $educationType = trim((string) ($input['education_type'] ?? ''));
        if ($educationType !== '' && !in_array($educationType, ['school', 'college', 'competitive_exam', 'other_learner'], true)) {
            json_response(['error' => 'Choose a valid learner audience.'], 422);
        }
        $education = [
            'education_type' => $educationType !== '' ? $educationType : null,
            'sharing_rights_confirmed' => $categorySlug === 'college-school' ? 1 : null,
        ];
        foreach ([
            'class_level' => 80,
            'institution' => 160,
            'course' => 120,
            'branch' => 120,
            'semester' => 40,
            'stream' => 120,
            'exam_category' => 120,
            'subject' => 120,
            'brand' => 120,
            'locality' => 120,
        ] as $key => $limit) {
            $value = trim((string) ($input[$key] ?? ''));
            if (mb_strlen($value) > $limit) {
                json_response(['error' => ucfirst($key) . ' is too long.'], 422);
            }
            $education[$key] = $value !== '' ? $value : null;
        }

        return [
            'category_id' => $categoryId,
            'subcategory_id' => $subcategoryId,
            'title' => $title,
            'description' => $description,
            'condition' => $condition,
            'listing_type' => $listingType,
            'sale_price' => $salePrice,
            'daily_price' => $dailyPrice,
            'weekly_price' => $weeklyPrice,
            'monthly_price' => $monthlyPrice,
            'deposit' => $deposit,
            'minimum_days' => $minimumDays,
            'maximum_days' => $maximumDays,
            'quantity' => $quantity,
            'country' => $country,
            'city' => $city,
            'pickup' => $pickup ? 1 : 0,
            'delivery' => $delivery ? 1 : 0,
            'education' => $education,
        ];
    }

    private static function productParameters(array $data, int $sellerId, string $moderation, string $availability): array
    {
        return [
            'seller_id' => $sellerId,
            'category_id' => $data['category_id'],
            'subcategory_id' => $data['subcategory_id'],
            'title' => $data['title'],
            'description' => $data['description'],
            'condition' => $data['condition'],
            'listing_type' => $data['listing_type'],
            'sale_price' => $data['sale_price'],
            'daily_price' => $data['daily_price'],
            'weekly_price' => $data['weekly_price'],
            'monthly_price' => $data['monthly_price'],
            'deposit' => $data['deposit'],
            'minimum_days' => $data['minimum_days'],
            'maximum_days' => $data['maximum_days'],
            'quantity' => $data['quantity'],
            'country' => $data['country'],
            'city' => $data['city'],
            'pickup' => $data['pickup'],
            'delivery' => $data['delivery'],
            'moderation_status' => $moderation,
            'availability_status' => $availability,
        ];
    }

    private static function saveEducationDetails(PDO $pdo, int $productId, array $data): void
    {
        if (array_filter($data['education'], static fn (mixed $value): bool => $value !== null) === []) {
            $delete = $pdo->prepare('DELETE FROM product_education WHERE product_id = :id');
            $delete->execute(['id' => $productId]);
            return;
        }
        $statement = $pdo->prepare(
            'INSERT INTO product_education
                (product_id, education_type, class_level, institution, course, branch, semester,
                 stream, exam_category, subject, brand, locality, sharing_rights_confirmed)
             VALUES
                (:product_id, :education_type, :class_level, :institution, :course, :branch, :semester,
                 :stream, :exam_category, :subject, :brand, :locality, :sharing_rights_confirmed)
             ON DUPLICATE KEY UPDATE institution = VALUES(institution), course = VALUES(course),
                education_type = VALUES(education_type), class_level = VALUES(class_level),
                branch = VALUES(branch), semester = VALUES(semester), stream = VALUES(stream),
                exam_category = VALUES(exam_category), subject = VALUES(subject),
                brand = VALUES(brand), locality = VALUES(locality),
                sharing_rights_confirmed = VALUES(sharing_rights_confirmed)'
        );
        $statement->execute(['product_id' => $productId] + $data['education']);
    }

    private static function validateUploads(array $files): array
    {
        $file = $files['images'] ?? null;
        if (!is_array($file)) {
            return [];
        }
        foreach (['name', 'tmp_name', 'error', 'size'] as $key) {
            if (!isset($file[$key]) || !is_array($file[$key])) {
                json_response(['error' => 'The image upload request is malformed.'], 422);
            }
        }
        $uploads = [];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new RuntimeException('The server image validation component is unavailable.');
        }
        try {
            foreach ($file['name'] as $index => $_originalName) {
                $error = (int) ($file['error'][$index] ?? UPLOAD_ERR_NO_FILE);
                if ($error === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($error !== UPLOAD_ERR_OK) {
                    json_response(['error' => 'An image upload failed. Try a smaller image and submit again.'], 422);
                }
                $temporaryPath = (string) ($file['tmp_name'][$index] ?? '');
                $size = (int) ($file['size'][$index] ?? 0);
                if ($size < 1 || $size > self::imageMaxBytes() || !is_uploaded_file($temporaryPath)) {
                    json_response(['error' => 'Images must be valid uploads no larger than ' . self::imageMaxBytes() . ' bytes.'], 422);
                }
                $mime = finfo_file($finfo, $temporaryPath);
                $imageInfo = self::readImageInfo($temporaryPath);
                if (!is_string($mime) || !isset(self::ALLOWED_IMAGE_MIMES[$mime]) || $imageInfo === false
                    || $imageInfo['mime'] !== $mime || $imageInfo[0] > 12000
                    || $imageInfo[1] > 12000 || $imageInfo[0] * $imageInfo[1] > 40000000) {
                    json_response(['error' => 'Only valid JPEG, PNG, and WebP images are accepted.'], 422);
                }
                $uploads[] = [
                    'temporary_path' => $temporaryPath,
                    'extension' => self::ALLOWED_IMAGE_MIMES[$mime],
                ];
                if (count($uploads) > self::imageLimit()) {
                    json_response(['error' => 'A listing can have at most ' . self::imageLimit() . ' images.'], 422);
                }
            }
        } finally {
            finfo_close($finfo);
        }
        return $uploads;
    }

    private static function moveUploads(array $uploads): array
    {
        if ($uploads === []) {
            return [];
        }
        $directory = self::uploadDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Product image storage could not be prepared.');
        }
        if (is_link($directory)) {
            throw new RuntimeException('Product image storage must not be a symbolic link.');
        }
        $stored = [];
        try {
            foreach ($uploads as $upload) {
                $filename = bin2hex(random_bytes(16)) . '.' . $upload['extension'];
                $destination = $directory . DIRECTORY_SEPARATOR . $filename;
                if (!move_uploaded_file($upload['temporary_path'], $destination)) {
                    self::removeStoredFiles(array_column($stored, 'path'));
                    json_response(['error' => 'An image could not be stored. Please try again.'], 500);
                }
                $stored[] = ['path' => '/media/products/' . $filename, 'filename' => $filename];
                if (!chmod($destination, 0600)) {
                    throw new RuntimeException('Product image file permissions could not be restricted.');
                }
            }
        } catch (Throwable $exception) {
            self::removeStoredFiles(array_column($stored, 'path'));
            throw $exception;
        }
        return $stored;
    }

    private static function findOwnedListing(PDO $pdo, int $sellerId, int $productId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT p.*, c.name AS category_name, c.slug AS category_slug, s.name AS subcategory_name
             FROM products p
             INNER JOIN categories c ON c.id = p.category_id
             LEFT JOIN subcategories s ON s.id = p.subcategory_id
             WHERE p.id = :id AND p.seller_id = :seller_id LIMIT 1'
        );
        $statement->execute(['id' => $productId, 'seller_id' => $sellerId]);
        $listing = $statement->fetch();
        return is_array($listing) ? $listing : null;
    }

    private static function imagesForProduct(PDO $pdo, int $productId): array
    {
        $statement = $pdo->prepare(
            'SELECT id, image_path, alt_text, sort_order FROM product_images
             WHERE product_id = :id ORDER BY sort_order, id'
        );
        $statement->execute(['id' => $productId]);
        return $statement->fetchAll();
    }

    private static function hasInProgressTransactions(PDO $pdo, int $sellerId, int $productId): bool
    {
        $statement = $pdo->prepare(
            'SELECT (
                EXISTS (
                    SELECT 1 FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id
                    WHERE oi.product_id = :order_product_id AND oi.seller_id = :order_seller_id
                      AND o.status IN ("pending_payment", "paid", "accepted", "handed_over", "disputed")
                )
                OR EXISTS (
                    SELECT 1 FROM rental_bookings rb
                    WHERE rb.product_id = :rental_product_id AND rb.seller_id = :rental_seller_id
                      AND rb.status IN ("requested", "accepted", "pending_payment", "paid", "active", "return_pending", "disputed")
                )
             )'
        );
        $statement->execute([
            'order_product_id' => $productId,
            'order_seller_id' => $sellerId,
            'rental_product_id' => $productId,
            'rental_seller_id' => $sellerId,
        ]);
        return (bool) $statement->fetchColumn();
    }

    private static function validatedImageRemovals(array $input, array $currentImages): array
    {
        $requested = $input['remove_images'] ?? [];
        if (!is_array($requested)) {
            json_response(['error' => 'The image removal request is invalid.'], 422);
        }
        $owned = array_map(static fn (array $image): int => (int) $image['id'], $currentImages);
        $remove = [];
        foreach ($requested as $id) {
            $validated = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($validated === false || !in_array($validated, $owned, true)) {
                json_response(['error' => 'One of the selected images does not belong to this listing.'], 422);
            }
            $remove[] = $validated;
        }
        return array_values(array_unique($remove));
    }

    private static function removeStoredFiles(array $paths): void
    {
        $directory = self::uploadDirectory();
        foreach ($paths as $path) {
            if (!is_string($path) || !preg_match('#^/media/products/([a-f0-9]{32}\.(?:jpg|png|webp))$#D', $path, $matches)) {
                continue;
            }
            $file = $directory . DIRECTORY_SEPARATOR . $matches[1];
            if (is_file($file) && !is_link($file)) {
                if (!unlink($file)) {
                    error_log('Could not remove a generated product image after its listing was changed.');
                }
            }
        }
    }

    private static function readImageInfo(string $path): array|false
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            return getimagesize($path);
        } catch (ErrorException) {
            return false;
        } finally {
            restore_error_handler();
        }
    }

    private static function uploadDirectory(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
            . 'uploads' . DIRECTORY_SEPARATOR . 'products';
    }

    private static function imageLimit(): int
    {
        $configured = (int) (app_settings()['product_image_max_count'] ?? self::MAX_IMAGE_COUNT);
        return max(1, min(10, $configured));
    }

    private static function imageMaxBytes(): int
    {
        $configured = (int) (app_settings()['product_image_max_bytes'] ?? self::MAX_IMAGE_BYTES);
        return max(1024, min(20971520, $configured));
    }

    private static function iniSizeBytes(string $value): int
    {
        if (!preg_match('/^\s*(\d+(?:\.\d+)?)\s*([KMG]?)\s*$/iD', $value, $matches)) {
            return 0;
        }
        $multiplier = match (strtoupper($matches[2])) {
            'K' => 1024,
            'M' => 1048576,
            'G' => 1073741824,
            default => 1,
        };
        return (int) ((float) $matches[1] * $multiplier);
    }

    private static function optionalAmount(mixed $value, bool $allowZero = false): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $amount = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($amount === false || !is_finite((float) $amount) || $amount < ($allowZero ? 0 : 0.01) || $amount > 100000000) {
            json_response(['error' => 'Prices must be valid amounts between 0.01 and 100,000,000.'], 422);
        }
        return number_format($amount, 2, '.', '');
    }

    private static function optionalInteger(mixed $value, int $minimum, int $maximum): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $minimum, 'max_range' => $maximum],
        ]);
        if ($integer === false) {
            json_response(['error' => 'A rental duration or quantity is outside the allowed range.'], 422);
        }
        return $integer;
    }

    private static function booleanField(mixed $value): bool
    {
        if (in_array($value, [true, 1, '1', 'on'], true)) {
            return true;
        }
        if (in_array($value, [false, 0, '0', ''], true)) {
            return false;
        }
        json_response(['error' => 'Choose valid pickup and delivery options.'], 422);
    }
}
