CREATE DATABASE IF NOT EXISTS second_story CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE second_story;

CREATE TABLE roles (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(32) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    account_status ENUM('active', 'suspended', 'closed') NOT NULL DEFAULT 'active',
    email_verified_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_status (account_status)
) ENGINE=InnoDB;

CREATE TABLE user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id SMALLINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE user_profiles (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    public_name VARCHAR(120) NULL,
    mobile_number VARCHAR(24) NULL,
    institution VARCHAR(160) NULL,
    education_type ENUM('school', 'college', 'competitive_exam', 'other_learner') NULL,
    education_detail VARCHAR(120) NULL,
    stream VARCHAR(120) NULL,
    exam_category VARCHAR(120) NULL,
    preferred_language VARCHAR(60) NULL,
    locality VARCHAR(120) NULL,
    bio VARCHAR(1000) NULL,
    city VARCHAR(120) NULL,
    country_code CHAR(2) NOT NULL DEFAULT 'IN',
    verification_status ENUM('not_started', 'pending', 'verified', 'rejected', 'needs_review') NOT NULL DEFAULT 'not_started',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_profiles_location (country_code, city),
    INDEX idx_profiles_education (education_type, education_detail, exam_category)
) ENGINE=InnoDB;

CREATE TABLE categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(140) NOT NULL UNIQUE,
    description VARCHAR(500) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_categories_active (is_active, sort_order)
) ENGINE=InnoDB;

CREATE TABLE subcategories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(140) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_subcategory_slug (category_id, slug),
    CONSTRAINT fk_subcategories_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
    INDEX idx_subcategories_active (category_id, is_active)
) ENGINE=InnoDB;

CREATE TABLE products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seller_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    subcategory_id BIGINT UNSIGNED NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NOT NULL,
    condition_label ENUM('new', 'like_new', 'good', 'fair') NOT NULL,
    listing_type ENUM('rent_only', 'sell_only', 'rent_and_sell') NOT NULL,
    sale_price DECIMAL(12,2) NULL,
    daily_rental_price DECIMAL(12,2) NULL,
    weekly_rental_price DECIMAL(12,2) NULL,
    monthly_rental_price DECIMAL(12,2) NULL,
    security_deposit DECIMAL(12,2) NOT NULL DEFAULT 0,
    minimum_rental_days SMALLINT UNSIGNED NULL,
    maximum_rental_days SMALLINT UNSIGNED NULL,
    quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    country_code CHAR(2) NOT NULL DEFAULT 'IN',
    city VARCHAR(120) NOT NULL,
    delivery_available BOOLEAN NOT NULL DEFAULT FALSE,
    pickup_available BOOLEAN NOT NULL DEFAULT TRUE,
    moderation_status ENUM('draft', 'pending_approval', 'published', 'rejected', 'removed') NOT NULL DEFAULT 'draft',
    rejection_reason VARCHAR(1000) NULL,
    availability_status ENUM('available', 'paused', 'unavailable', 'sold') NOT NULL DEFAULT 'available',
    moderated_by BIGINT UNSIGNED NULL,
    moderated_at DATETIME NULL,
    archived_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_products_seller FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
    CONSTRAINT fk_products_subcategory FOREIGN KEY (subcategory_id) REFERENCES subcategories(id) ON DELETE SET NULL,
    CONSTRAINT fk_products_moderator FOREIGN KEY (moderated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_product_quantity CHECK (quantity >= 1),
    CONSTRAINT chk_sale_price_type CHECK (
        (listing_type = 'rent_only' AND sale_price IS NULL)
        OR (listing_type IN ('sell_only', 'rent_and_sell') AND sale_price > 0)
    ),
    CONSTRAINT chk_rental_price_type CHECK (
        (listing_type = 'sell_only' AND daily_rental_price IS NULL)
        OR (listing_type IN ('rent_only', 'rent_and_sell') AND daily_rental_price > 0)
    ),
    INDEX idx_products_search (moderation_status, availability_status, category_id, created_at),
    INDEX idx_products_seller (seller_id, created_at),
    INDEX idx_products_location (country_code, city),
    INDEX idx_products_sale_price (sale_price),
    INDEX idx_products_rental_price (daily_rental_price)
) ENGINE=InnoDB;

CREATE TABLE product_images (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    image_path VARCHAR(500) NOT NULL,
    alt_text VARCHAR(180) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product_images_order (product_id, sort_order)
) ENGINE=InnoDB;

CREATE TABLE product_education (
    product_id BIGINT UNSIGNED PRIMARY KEY,
    institution VARCHAR(160) NULL,
    education_type ENUM('school', 'college', 'competitive_exam', 'other_learner') NULL,
    class_level VARCHAR(80) NULL,
    course VARCHAR(120) NULL,
    branch VARCHAR(120) NULL,
    semester VARCHAR(40) NULL,
    stream VARCHAR(120) NULL,
    exam_category VARCHAR(120) NULL,
    subject VARCHAR(120) NULL,
    brand VARCHAR(120) NULL,
    locality VARCHAR(120) NULL,
    author VARCHAR(160) NULL,
    book_title VARCHAR(180) NULL,
    sharing_rights_confirmed BOOLEAN NOT NULL DEFAULT FALSE,
    CONSTRAINT fk_product_education_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product_education_filters (institution, course, branch, semester, subject),
    INDEX idx_product_education_audience (education_type, class_level, exam_category)
) ENGINE=InnoDB;

CREATE TABLE product_availability (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    availability_status ENUM('available', 'blocked', 'reserved') NOT NULL DEFAULT 'available',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_availability_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT chk_availability_dates CHECK (ends_at > starts_at),
    INDEX idx_availability_product_dates (product_id, starts_at, ends_at, availability_status)
) ENGINE=InnoDB;

CREATE TABLE conversations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    buyer_id BIGINT UNSIGNED NOT NULL,
    seller_id BIGINT UNSIGNED NOT NULL,
    last_message_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_conversations_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_conversations_buyer FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_conversations_seller FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_conversation_distinct_users CHECK (buyer_id <> seller_id),
    UNIQUE KEY uq_conversation_product_participants (product_id, buyer_id, seller_id),
    INDEX idx_conversations_buyer_recent (buyer_id, last_message_at, id),
    INDEX idx_conversations_seller_recent (seller_id, last_message_at, id)
) ENGINE=InnoDB;

CREATE TABLE messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    recipient_id BIGINT UNSIGNED NOT NULL,
    body VARCHAR(2000) NOT NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_messages_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_messages_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_messages_conversation_order (conversation_id, id),
    INDEX idx_messages_recipient_unread (recipient_id, read_at, created_at)
) ENGINE=InnoDB;

CREATE TABLE conversation_blocks (
    conversation_id BIGINT UNSIGNED NOT NULL,
    blocker_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (conversation_id, blocker_id),
    CONSTRAINT fk_conversation_blocks_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_blocks_user FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_conversation_blocks_user (blocker_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE conversation_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    reporter_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(120) NOT NULL,
    details VARCHAR(2000) NULL,
    status ENUM('open', 'reviewing', 'actioned', 'dismissed') NOT NULL DEFAULT 'open',
    reviewed_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    CONSTRAINT fk_conversation_reports_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_conversation_reports_reporter FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_conversation_reports_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_conversation_reports_status (status, created_at),
    INDEX idx_conversation_reports_reporter (reporter_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number CHAR(26) NOT NULL UNIQUE,
    buyer_id BIGINT UNSIGNED NULL,
    buyer_email_snapshot VARCHAR(254) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    subtotal DECIMAL(12,2) NOT NULL,
    delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
    platform_commission DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(12,2) NOT NULL,
    status ENUM('pending_payment', 'paid', 'accepted', 'handed_over', 'completed', 'cancelled', 'disputed') NOT NULL DEFAULT 'pending_payment',
    idempotency_key VARCHAR(100) NOT NULL,
    demo_mode BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_buyer FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_orders_idempotency (buyer_id, idempotency_key),
    INDEX idx_orders_status_created (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE order_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NULL,
    seller_id BIGINT UNSIGNED NULL,
    product_title_snapshot VARCHAR(180) NOT NULL,
    seller_name_snapshot VARCHAR(120) NULL,
    quantity SMALLINT UNSIGNED NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    line_total DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    CONSTRAINT fk_order_items_seller FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_order_items_seller (seller_id, order_id)
) ENGINE=InnoDB;

CREATE TABLE rental_bookings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_number CHAR(26) NOT NULL UNIQUE,
    product_id BIGINT UNSIGNED NULL,
    buyer_id BIGINT UNSIGNED NULL,
    seller_id BIGINT UNSIGNED NULL,
    product_title_snapshot VARCHAR(180) NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    rental_charge DECIMAL(12,2) NOT NULL,
    security_deposit DECIMAL(12,2) NOT NULL DEFAULT 0,
    delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
    platform_commission DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    status ENUM('requested', 'accepted', 'rejected', 'pending_payment', 'paid', 'active', 'return_pending', 'returned', 'completed', 'cancelled', 'disputed') NOT NULL DEFAULT 'requested',
    payment_status ENUM('pending', 'succeeded', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
    return_condition VARCHAR(1000) NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    demo_mode BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rental_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    CONSTRAINT fk_rental_buyer FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_rental_seller FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_rental_dates CHECK (ends_at > starts_at),
    UNIQUE KEY uq_rental_idempotency (buyer_id, idempotency_key),
    INDEX idx_rental_product_dates (product_id, status, starts_at, ends_at),
    INDEX idx_rental_buyer (buyer_id, created_at),
    INDEX idx_rental_seller (seller_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE rental_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    previous_status VARCHAR(32) NULL,
    new_status VARCHAR(32) NOT NULL,
    note VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rental_history_booking FOREIGN KEY (booking_id) REFERENCES rental_bookings(id) ON DELETE RESTRICT,
    CONSTRAINT fk_rental_history_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_rental_history_booking (booking_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NULL,
    rental_booking_id BIGINT UNSIGNED NULL,
    provider VARCHAR(60) NOT NULL DEFAULT 'demo',
    provider_reference VARCHAR(180) NULL,
    idempotency_key VARCHAR(100) NOT NULL UNIQUE,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    status ENUM('pending', 'succeeded', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
    is_demo BOOLEAN NOT NULL DEFAULT TRUE,
    verified_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payments_rental FOREIGN KEY (rental_booking_id) REFERENCES rental_bookings(id) ON DELETE RESTRICT,
    CONSTRAINT chk_payment_parent CHECK (
        (order_id IS NOT NULL AND rental_booking_id IS NULL)
        OR (order_id IS NULL AND rental_booking_id IS NOT NULL)
    ),
    INDEX idx_payments_order (order_id),
    INDEX idx_payments_rental (rental_booking_id),
    INDEX idx_payments_provider_ref (provider, provider_reference)
) ENGINE=InnoDB;

CREATE TABLE refunds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    rental_booking_id BIGINT UNSIGNED NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    reason VARCHAR(1000) NOT NULL,
    status ENUM('requested', 'approved', 'processing', 'succeeded', 'rejected', 'failed') NOT NULL DEFAULT 'requested',
    provider_reference VARCHAR(180) NULL,
    authorized_by BIGINT UNSIGNED NULL,
    initiated_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_refunds_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_refunds_rental FOREIGN KEY (rental_booking_id) REFERENCES rental_bookings(id) ON DELETE RESTRICT,
    CONSTRAINT fk_refunds_admin FOREIGN KEY (authorized_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_refunds_status (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE commissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_item_id BIGINT UNSIGNED NULL,
    rental_booking_id BIGINT UNSIGNED NULL,
    transaction_amount DECIMAL(12,2) NOT NULL,
    commission_percent DECIMAL(5,2) NOT NULL,
    commission_amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    payout_status ENUM('not_due', 'pending', 'processing', 'paid', 'failed') NOT NULL DEFAULT 'not_due',
    paid_at DATETIME NULL,
    is_demo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_commissions_order_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE RESTRICT,
    CONSTRAINT fk_commissions_rental FOREIGN KEY (rental_booking_id) REFERENCES rental_bookings(id) ON DELETE RESTRICT,
    CONSTRAINT chk_commission_parent CHECK (
        (order_item_id IS NOT NULL AND rental_booking_id IS NULL)
        OR (order_item_id IS NULL AND rental_booking_id IS NOT NULL)
    ),
    INDEX idx_commissions_payout (payout_status, created_at)
) ENGINE=InnoDB;

CREATE TABLE reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reviewer_id BIGINT UNSIGNED NULL,
    product_id BIGINT UNSIGNED NULL,
    order_item_id BIGINT UNSIGNED NULL,
    rental_booking_id BIGINT UNSIGNED NULL,
    rating TINYINT UNSIGNED NOT NULL,
    body VARCHAR(2000) NULL,
    moderation_status ENUM('pending', 'published', 'hidden', 'removed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reviews_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_reviews_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    CONSTRAINT fk_reviews_order_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE RESTRICT,
    CONSTRAINT fk_reviews_rental FOREIGN KEY (rental_booking_id) REFERENCES rental_bookings(id) ON DELETE RESTRICT,
    CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5),
    UNIQUE KEY uq_review_order_item (reviewer_id, order_item_id),
    UNIQUE KEY uq_review_rental_booking (reviewer_id, rental_booking_id),
    INDEX idx_reviews_product_status (product_id, moderation_status)
) ENGINE=InnoDB;

CREATE TABLE complaints (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reporter_id BIGINT UNSIGNED NULL,
    against_user_id BIGINT UNSIGNED NULL,
    product_id BIGINT UNSIGNED NULL,
    order_id BIGINT UNSIGNED NULL,
    rental_booking_id BIGINT UNSIGNED NULL,
    subject VARCHAR(180) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('open', 'investigating', 'resolved', 'dismissed') NOT NULL DEFAULT 'open',
    resolved_by BIGINT UNSIGNED NULL,
    resolution_note VARCHAR(2000) NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_complaints_reporter FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_complaints_against FOREIGN KEY (against_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_complaints_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    CONSTRAINT fk_complaints_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_complaints_rental FOREIGN KEY (rental_booking_id) REFERENCES rental_bookings(id) ON DELETE RESTRICT,
    CONSTRAINT fk_complaints_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_complaints_status (status, created_at),
    INDEX idx_complaints_reporter (reporter_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE listing_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reporter_id BIGINT UNSIGNED NULL,
    product_id BIGINT UNSIGNED NULL,
    reason VARCHAR(180) NOT NULL,
    details VARCHAR(2000) NULL,
    status ENUM('open', 'reviewing', 'actioned', 'dismissed') NOT NULL DEFAULT 'open',
    reviewed_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    CONSTRAINT fk_listing_reports_reporter FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_listing_reports_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    CONSTRAINT fk_listing_reports_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_listing_reports_status (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    related_conversation_id BIGINT UNSIGNED NULL,
    related_message_id BIGINT UNSIGNED NULL,
    action_url VARCHAR(500) NULL,
    type VARCHAR(80) NOT NULL,
    title VARCHAR(180) NOT NULL,
    body VARCHAR(1000) NOT NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_notifications_conversation FOREIGN KEY (related_conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
    CONSTRAINT fk_notifications_message FOREIGN KEY (related_message_id) REFERENCES messages(id) ON DELETE SET NULL,
    INDEX idx_notifications_user (user_id, read_at, created_at)
) ENGINE=InnoDB;

CREATE TABLE verification_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('not_started', 'pending', 'verified', 'rejected', 'needs_review') NOT NULL DEFAULT 'pending',
    provider VARCHAR(100) NULL,
    reference_hash CHAR(64) NULL,
    review_note VARCHAR(1000) NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    CONSTRAINT fk_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_verification_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_verification_status (status, submitted_at),
    INDEX idx_verification_user (user_id, submitted_at)
) ENGINE=InnoDB;

CREATE TABLE admin_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(120) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    metadata_json JSON NULL,
    ip_fingerprint CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_actor (actor_user_id, created_at),
    INDEX idx_audit_entity (entity_type, entity_id, created_at),
    INDEX idx_audit_action (action, created_at)
) ENGINE=InnoDB;

CREATE TABLE wishlist (
    user_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, product_id),
    CONSTRAINT fk_wishlist_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_wishlist_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_wishlist_product (product_id)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) NOT NULL,
    ip_fingerprint CHAR(64) NOT NULL,
    succeeded BOOLEAN NOT NULL DEFAULT FALSE,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_throttle (email, ip_fingerprint, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE password_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_password_reset_expiry (expires_at, consumed_at)
) ENGINE=InnoDB;

CREATE TABLE platform_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value VARCHAR(500) NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
