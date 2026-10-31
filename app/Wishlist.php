<?php
declare(strict_types=1);

final class Wishlist
{
    public static function list(): never
    {
        $userId = require_user();
        json_response(['data' => self::products_for_user($userId)]);
    }

    public static function products_for_user(int $userId): array
    {
        $statement = database_connection()->prepare(
            'SELECT p.id, p.title, p.sale_price, p.daily_rental_price, p.listing_type,
                    p.condition_label, p.quantity, c.name AS category, p.city, p.country_code,
                    COALESCE(NULLIF(profile.public_name, ""), profile.full_name) AS seller_name,
                    (SELECT pi.image_path FROM product_images pi
                     WHERE pi.product_id = p.id ORDER BY pi.sort_order, pi.id LIMIT 1) AS image
             FROM wishlist w
             INNER JOIN products p ON p.id = w.product_id
             INNER JOIN categories c ON c.id = p.category_id AND c.is_active = 1
             INNER JOIN users seller ON seller.id = p.seller_id AND seller.account_status = "active"
             LEFT JOIN user_profiles profile ON profile.user_id = seller.id
             WHERE w.user_id = :user_id
               AND p.moderation_status = "published"
               AND p.availability_status = "available"
               AND p.quantity > 0
               AND p.archived_at IS NULL
             ORDER BY w.created_at DESC'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    public static function add(int $productId): never
    {
        require_csrf();
        $userId = require_user();
        $statement = database_connection()->prepare(
            'INSERT INTO wishlist (user_id, product_id)
             SELECT :user_id, p.id FROM products p
             INNER JOIN categories c ON c.id = p.category_id AND c.is_active = 1
             INNER JOIN users seller ON seller.id = p.seller_id AND seller.account_status = "active"
             WHERE p.id = :product_id
               AND p.moderation_status = "published"
               AND p.availability_status = "available"
               AND p.quantity > 0
               AND p.archived_at IS NULL
             ON DUPLICATE KEY UPDATE created_at = created_at'
        );
        $statement->execute(['user_id' => $userId, 'product_id' => $productId]);
        if ($statement->rowCount() === 0) {
            $exists = database_connection()->prepare(
                'SELECT 1 FROM products p
                 INNER JOIN categories c ON c.id = p.category_id AND c.is_active = 1
                 INNER JOIN users seller ON seller.id = p.seller_id AND seller.account_status = "active"
                 WHERE p.id = :id AND p.moderation_status = "published"
                   AND p.availability_status = "available" AND p.quantity > 0
                   AND p.archived_at IS NULL'
            );
            $exists->execute(['id' => $productId]);
            if (!$exists->fetchColumn()) {
                json_response(['error' => 'That listing is not available.'], 404);
            }
        }
        json_response(['message' => 'Saved to your wishlist.'], 201);
    }

    public static function remove(int $productId): never
    {
        require_csrf();
        $userId = require_user();
        $statement = database_connection()->prepare(
            'DELETE FROM wishlist WHERE user_id = :user_id AND product_id = :product_id'
        );
        $statement->execute(['user_id' => $userId, 'product_id' => $productId]);
        json_response(['message' => 'Wishlist updated.']);
    }
}
