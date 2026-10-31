<?php
declare(strict_types=1);

final class Messaging
{
    private const MAX_MESSAGE_LENGTH = 2000;

    public static function startConversation(array $input): never
    {
        require_csrf();
        $buyerId = require_role('buyer');
        $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($productId === false) {
            json_response(['error' => 'Choose a valid listing.'], 422);
        }
        $body = self::validatedMessage($input['message'] ?? null);
        $pdo = database_connection();
        $productStatement = $pdo->prepare(
            'SELECT p.seller_id
             FROM products p
             INNER JOIN users seller ON seller.id = p.seller_id AND seller.account_status = "active"
             INNER JOIN categories c ON c.id = p.category_id AND c.is_active = 1
             WHERE p.id = :id AND p.moderation_status = "published"
               AND p.availability_status = "available" AND p.quantity > 0 AND p.archived_at IS NULL
             LIMIT 1'
        );
        $productStatement->execute(['id' => $productId]);
        $sellerId = $productStatement->fetchColumn();
        if (!is_string($sellerId) && !is_int($sellerId)) {
            json_response(['error' => 'That listing is not available for a conversation.'], 404);
        }
        $sellerId = (int) $sellerId;
        if ($sellerId === $buyerId) {
            json_response(['error' => 'You cannot message yourself about your own listing.'], 422);
        }

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO conversations (product_id, buyer_id, seller_id)
                 VALUES (:product_id, :buyer_id, :seller_id)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
            );
            $insert->execute([
                'product_id' => $productId,
                'buyer_id' => $buyerId,
                'seller_id' => $sellerId,
            ]);
            $conversationId = (int) $pdo->lastInsertId();
            $lock = $pdo->prepare('SELECT id FROM conversations WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $conversationId]);
            if (!$lock->fetchColumn()) {
                throw new RuntimeException('The conversation could not be locked.');
            }
            if (self::isBlocked($pdo, $conversationId)) {
                $pdo->rollBack();
                json_response(['error' => 'Messaging is unavailable for this conversation.'], 403);
            }
            $messageId = self::insertMessage($pdo, $conversationId, $buyerId, $sellerId, $body);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        json_response([
            'message' => 'Your message was sent.',
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'redirect_url' => '/messages?conversation_id=' . $conversationId,
        ], 201);
    }

    public static function conversations(): never
    {
        $userId = require_user();
        $statement = database_connection()->prepare(
            'SELECT c.id, c.product_id, p.title AS product_title,
                    COALESCE(NULLIF(other_profile.public_name, ""), other_profile.full_name, "CampusLoop user") AS other_name,
                    (SELECT m.body FROM messages m
                     WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message,
                    (SELECT m.created_at FROM messages m
                     WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message_at,
                    (SELECT COUNT(*) FROM messages unread
                     WHERE unread.conversation_id = c.id AND unread.recipient_id = :unread_user
                       AND unread.read_at IS NULL) AS unread_count,
                    EXISTS (SELECT 1 FROM conversation_blocks own_block
                            WHERE own_block.conversation_id = c.id AND own_block.blocker_id = :own_blocker) AS blocked_by_me,
                    EXISTS (SELECT 1 FROM conversation_blocks other_block
                            WHERE other_block.conversation_id = c.id AND other_block.blocker_id <> :other_blocker) AS blocked_by_other
             FROM conversations c
             INNER JOIN products p ON p.id = c.product_id
             INNER JOIN users other_user
                ON other_user.id = CASE WHEN c.buyer_id = :viewer_buyer THEN c.seller_id ELSE c.buyer_id END
             LEFT JOIN user_profiles other_profile ON other_profile.user_id = other_user.id
             WHERE c.buyer_id = :buyer_id OR c.seller_id = :seller_id
             ORDER BY COALESCE(c.last_message_at, c.created_at) DESC, c.id DESC
             LIMIT 100'
        );
        $statement->execute([
            'unread_user' => $userId,
            'own_blocker' => $userId,
            'other_blocker' => $userId,
            'viewer_buyer' => $userId,
            'buyer_id' => $userId,
            'seller_id' => $userId,
        ]);
        json_response(['data' => $statement->fetchAll()]);
    }

    public static function unreadMessageCount(): never
    {
        $userId = require_user();
        $statement = database_connection()->prepare(
            'SELECT COUNT(*) FROM messages
             WHERE recipient_id = :user_id AND read_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);
        json_response(['unread_count' => (int) $statement->fetchColumn()]);
    }

    public static function messages(int $conversationId, array $input): never
    {
        $userId = require_user();
        foreach (['after', 'before'] as $cursorName) {
            if (isset($input[$cursorName]) && !is_scalar($input[$cursorName])) {
                json_response(['error' => 'Message cursor must be a single value.'], 422);
            }
        }
        if (isset($input['after'], $input['before'])) {
            json_response(['error' => 'Choose either an older or newer message cursor.'], 422);
        }
        $after = isset($input['after'])
            ? filter_var($input['after'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])
            : 0;
        $before = isset($input['before'])
            ? filter_var($input['before'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : null;
        if ($after === false || (isset($input['before']) && $before === false)) {
            json_response(['error' => 'Message cursor must be a valid message ID.'], 422);
        }
        if ($before !== null && $after > 0) {
            json_response(['error' => 'Choose either an older or newer message cursor.'], 422);
        }
        $pdo = database_connection();
        $conversation = self::conversationForUser($pdo, $conversationId, $userId);
        if ($conversation === null) {
            json_response(['error' => 'That conversation could not be found.'], 404);
        }

        $pdo->beginTransaction();
        try {
            if ($before !== null) {
                $messages = $pdo->prepare(
                    'SELECT id, sender_id, recipient_id, body, read_at, created_at
                     FROM (
                         SELECT id, sender_id, recipient_id, body, read_at, created_at
                         FROM messages
                         WHERE conversation_id = :conversation_id AND id < :before_id
                         ORDER BY id DESC LIMIT 100
                     ) older_messages ORDER BY id ASC'
                );
                $messages->bindValue(':conversation_id', $conversationId, PDO::PARAM_INT);
                $messages->bindValue(':before_id', $before, PDO::PARAM_INT);
            } elseif ($after > 0) {
                $messages = $pdo->prepare(
                    'SELECT id, sender_id, recipient_id, body, read_at, created_at
                     FROM messages
                     WHERE conversation_id = :conversation_id AND id > :after_id
                     ORDER BY id ASC LIMIT 100'
                );
                $messages->bindValue(':conversation_id', $conversationId, PDO::PARAM_INT);
                $messages->bindValue(':after_id', $after, PDO::PARAM_INT);
            } else {
                $messages = $pdo->prepare(
                    'SELECT id, sender_id, recipient_id, body, read_at, created_at
                     FROM (
                         SELECT id, sender_id, recipient_id, body, read_at, created_at
                         FROM messages WHERE conversation_id = :conversation_id
                         ORDER BY id DESC LIMIT 100
                     ) recent_messages ORDER BY id ASC'
                );
                $messages->bindValue(':conversation_id', $conversationId, PDO::PARAM_INT);
            }
            $messages->execute();
            $rows = $messages->fetchAll();
            $unreadIds = array_values(array_map(
                static fn (array $message): int => (int) $message['id'],
                array_filter(
                    $rows,
                    static fn (array $message): bool =>
                        (int) $message['recipient_id'] === $userId && $message['read_at'] === null
                )
            ));
            if ($unreadIds !== []) {
                $idParameters = [];
                $placeholders = [];
                foreach ($unreadIds as $index => $messageId) {
                    $name = 'message_id_' . $index;
                    $placeholders[] = ':' . $name;
                    $idParameters[$name] = $messageId;
                }
                $updateRead = $pdo->prepare(
                    'UPDATE messages SET read_at = UTC_TIMESTAMP()
                     WHERE conversation_id = :conversation_id AND recipient_id = :recipient_id
                       AND id IN (' . implode(', ', $placeholders) . ') AND read_at IS NULL'
                );
                $updateRead->execute(
                    ['conversation_id' => $conversationId, 'recipient_id' => $userId] + $idParameters
                );
                $updateNotifications = $pdo->prepare(
                    'UPDATE notifications SET read_at = UTC_TIMESTAMP()
                     WHERE user_id = :user_id AND related_message_id IN (' . implode(', ', $placeholders) . ')
                       AND read_at IS NULL'
                );
                $updateNotifications->execute(['user_id' => $userId] + $idParameters);
                $readAt = gmdate('Y-m-d H:i:s');
                foreach ($rows as &$message) {
                    if (in_array((int) $message['id'], $unreadIds, true)) {
                        $message['read_at'] = $readAt;
                    }
                }
                unset($message);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $readReceipts = $pdo->prepare(
            'SELECT id FROM messages
             WHERE conversation_id = :conversation_id AND sender_id = :sender_id AND read_at IS NOT NULL
             ORDER BY id DESC LIMIT 100'
        );
        $readReceipts->execute(['conversation_id' => $conversationId, 'sender_id' => $userId]);
        $readMessageIds = array_map('intval', $readReceipts->fetchAll(PDO::FETCH_COLUMN));
        $conversation['blocked_by_me'] = self::isBlockedBy($pdo, $conversationId, $userId);
        $conversation['blocked_by_other'] = self::isBlockedByOther($pdo, $conversationId, $userId);
        json_response([
            'conversation' => $conversation,
            'messages' => $rows,
            'read_message_ids' => $readMessageIds,
            'viewer_id' => $userId,
            'next_after' => $rows === [] ? $after : (int) $rows[array_key_last($rows)]['id'],
            'has_more' => count($rows) === 100,
        ]);
    }

    public static function sendMessage(int $conversationId, array $input): never
    {
        require_csrf();
        $userId = require_user();
        $body = self::validatedMessage($input['message'] ?? null);
        $pdo = database_connection();
        $pdo->beginTransaction();
        try {
            $conversation = self::conversationForUser($pdo, $conversationId, $userId, true);
            if ($conversation === null) {
                $pdo->rollBack();
                json_response(['error' => 'That conversation could not be found.'], 404);
            }
            if (self::isBlocked($pdo, $conversationId)) {
                $pdo->rollBack();
                json_response(['error' => 'Messaging is unavailable for this conversation.'], 403);
            }
            $recipientId = $userId === (int) $conversation['buyer_id']
                ? (int) $conversation['seller_id']
                : (int) $conversation['buyer_id'];
            $messageId = self::insertMessage($pdo, $conversationId, $userId, $recipientId, $body);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        json_response(['message' => 'Message sent.', 'message_id' => $messageId], 201);
    }

    public static function block(int $conversationId, array $input): never
    {
        require_csrf();
        $userId = require_user();
        $action = $input['action'] ?? null;
        if (!is_string($action) || !in_array($action, ['block', 'unblock'], true)) {
            json_response(['error' => 'Choose whether to block or unblock this conversation.'], 422);
        }
        $pdo = database_connection();
        $pdo->beginTransaction();
        try {
            $conversation = self::conversationForUser($pdo, $conversationId, $userId, true);
            if ($conversation === null) {
                $pdo->rollBack();
                json_response(['error' => 'That conversation could not be found.'], 404);
            }
            if ($action === 'block') {
                $statement = $pdo->prepare(
                    'INSERT INTO conversation_blocks (conversation_id, blocker_id)
                     VALUES (:conversation_id, :blocker_id)
                     ON DUPLICATE KEY UPDATE created_at = created_at'
                );
            } else {
                $statement = $pdo->prepare(
                    'DELETE FROM conversation_blocks
                     WHERE conversation_id = :conversation_id AND blocker_id = :blocker_id'
                );
            }
            $statement->execute(['conversation_id' => $conversationId, 'blocker_id' => $userId]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        json_response(['message' => $action === 'block' ? 'Conversation blocked.' : 'Conversation unblocked.']);
    }

    public static function report(int $conversationId, array $input): never
    {
        require_csrf();
        $userId = require_user();
        foreach (['reason', 'details'] as $field) {
            if (isset($input[$field]) && !is_scalar($input[$field])) {
                json_response(['error' => 'Report fields must contain single values.'], 422);
            }
        }
        $reason = trim((string) ($input['reason'] ?? ''));
        $details = trim((string) ($input['details'] ?? ''));
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 120 || mb_strlen($details) > 2000) {
            json_response(['error' => 'Enter a report reason of 3 to 120 characters and details up to 2,000 characters.'], 422);
        }
        $pdo = database_connection();
        if (self::conversationForUser($pdo, $conversationId, $userId) === null) {
            json_response(['error' => 'That conversation could not be found.'], 404);
        }
        $statement = $pdo->prepare(
            'INSERT INTO conversation_reports (conversation_id, reporter_id, reason, details)
             VALUES (:conversation_id, :reporter_id, :reason, :details)'
        );
        $statement->execute([
            'conversation_id' => $conversationId,
            'reporter_id' => $userId,
            'reason' => $reason,
            'details' => $details !== '' ? $details : null,
        ]);
        json_response(['message' => 'Report submitted for review.'], 201);
    }

    public static function notifications(): never
    {
        $userId = require_user();
        $statement = database_connection()->prepare(
            'SELECT id, type, title, body, action_url, read_at, created_at
             FROM notifications WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC LIMIT 50'
        );
        $statement->execute(['user_id' => $userId]);
        $data = $statement->fetchAll();
        $count = database_connection()->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL'
        );
        $count->execute(['user_id' => $userId]);
        json_response(['data' => $data, 'unread_count' => (int) $count->fetchColumn()]);
    }

    public static function unreadNotificationCount(): never
    {
        $userId = require_user();
        $statement = database_connection()->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);
        json_response(['unread_count' => (int) $statement->fetchColumn()]);
    }

    public static function markNotificationRead(int $notificationId): never
    {
        require_csrf();
        $userId = require_user();
        $statement = database_connection()->prepare(
            'UPDATE notifications SET read_at = COALESCE(read_at, UTC_TIMESTAMP())
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute(['id' => $notificationId, 'user_id' => $userId]);
        if ($statement->rowCount() === 0) {
            $exists = database_connection()->prepare(
                'SELECT 1 FROM notifications WHERE id = :id AND user_id = :user_id LIMIT 1'
            );
            $exists->execute(['id' => $notificationId, 'user_id' => $userId]);
            if (!$exists->fetchColumn()) {
                json_response(['error' => 'That notification could not be found.'], 404);
            }
        }
        json_response(['message' => 'Notification marked as read.']);
    }

    public static function markAllNotificationsRead(): never
    {
        require_csrf();
        $userId = require_user();
        $statement = database_connection()->prepare(
            'UPDATE notifications SET read_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id AND read_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);
        json_response(['message' => 'Notifications marked as read.']);
    }

    private static function validatedMessage(mixed $value): string
    {
        if (!is_string($value)) {
            json_response(['error' => 'Enter a text message.'], 422);
        }
        $body = trim($value);
        if (!mb_check_encoding($body, 'UTF-8')
            || $body === '' || mb_strlen($body) > self::MAX_MESSAGE_LENGTH) {
            json_response(['error' => 'Messages must contain 1 to 2,000 characters.'], 422);
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $body)) {
            json_response(['error' => 'Messages cannot contain control characters.'], 422);
        }
        return $body;
    }

    private static function insertMessage(
        PDO $pdo,
        int $conversationId,
        int $senderId,
        int $recipientId,
        string $body
    ): int {
        $message = $pdo->prepare(
            'INSERT INTO messages (conversation_id, sender_id, recipient_id, body)
             VALUES (:conversation_id, :sender_id, :recipient_id, :body)'
        );
        $message->execute([
            'conversation_id' => $conversationId,
            'sender_id' => $senderId,
            'recipient_id' => $recipientId,
            'body' => $body,
        ]);
        $messageId = (int) $pdo->lastInsertId();
        $notification = $pdo->prepare(
            'INSERT INTO notifications
                (user_id, related_conversation_id, related_message_id, action_url, type, title, body)
             VALUES
                (:user_id, :conversation_id, :message_id, :action_url, "new_message", "New message", :body)'
        );
        $notification->execute([
            'user_id' => $recipientId,
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'action_url' => '/messages?conversation_id=' . $conversationId,
            'body' => mb_substr($body, 0, 180),
        ]);
        $updateConversation = $pdo->prepare(
            'UPDATE conversations SET last_message_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $updateConversation->execute(['id' => $conversationId]);
        return $messageId;
    }

    private static function conversationForUser(
        PDO $pdo,
        int $conversationId,
        int $userId,
        bool $lock = false
    ): ?array {
        $statement = $pdo->prepare(
            'SELECT c.id, c.product_id, c.buyer_id, c.seller_id, p.title AS product_title
             FROM conversations c INNER JOIN products p ON p.id = c.product_id
             WHERE c.id = :id AND (c.buyer_id = :buyer_id OR c.seller_id = :seller_id)
             LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute(['id' => $conversationId, 'buyer_id' => $userId, 'seller_id' => $userId]);
        $conversation = $statement->fetch();
        if (!is_array($conversation)) {
            return null;
        }
        $otherUserId = $userId === (int) $conversation['buyer_id']
            ? (int) $conversation['seller_id']
            : (int) $conversation['buyer_id'];
        $other = $pdo->prepare(
            'SELECT COALESCE(NULLIF(public_name, ""), full_name, "CampusLoop user")
             FROM user_profiles WHERE user_id = :user_id LIMIT 1'
        );
        $other->execute(['user_id' => $otherUserId]);
        $conversation['other_name'] = $other->fetchColumn() ?: 'CampusLoop user';
        $conversation['other_user_id'] = $otherUserId;
        return $conversation;
    }

    private static function isBlocked(PDO $pdo, int $conversationId): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1 FROM conversation_blocks WHERE conversation_id = :id LIMIT 1'
        );
        $statement->execute(['id' => $conversationId]);
        return (bool) $statement->fetchColumn();
    }

    private static function isBlockedBy(PDO $pdo, int $conversationId, int $userId): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1 FROM conversation_blocks WHERE conversation_id = :id AND blocker_id = :user_id LIMIT 1'
        );
        $statement->execute(['id' => $conversationId, 'user_id' => $userId]);
        return (bool) $statement->fetchColumn();
    }

    private static function isBlockedByOther(PDO $pdo, int $conversationId, int $userId): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1 FROM conversation_blocks WHERE conversation_id = :id AND blocker_id <> :user_id LIMIT 1'
        );
        $statement->execute(['id' => $conversationId, 'user_id' => $userId]);
        return (bool) $statement->fetchColumn();
    }
}
