USE second_story;

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

ALTER TABLE notifications
    ADD COLUMN related_conversation_id BIGINT UNSIGNED NULL AFTER user_id,
    ADD COLUMN related_message_id BIGINT UNSIGNED NULL AFTER related_conversation_id,
    ADD COLUMN action_url VARCHAR(500) NULL AFTER related_message_id,
    ADD CONSTRAINT fk_notifications_conversation
        FOREIGN KEY (related_conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_notifications_message
        FOREIGN KEY (related_message_id) REFERENCES messages(id) ON DELETE SET NULL;
