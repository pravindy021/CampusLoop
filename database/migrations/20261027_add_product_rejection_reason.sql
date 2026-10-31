ALTER TABLE products
    ADD COLUMN rejection_reason VARCHAR(1000) NULL AFTER moderation_status;
