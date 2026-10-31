<?php
declare(strict_types=1);

final class Auth
{
    public static function register(array $input): never
    {
        require_csrf();
        $mode = self::requested_mode($input);
        $name = trim((string) ($input['full_name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $phone = trim((string) ($input['mobile_number'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $college = trim((string) ($input['college'] ?? ''));
        $city = trim((string) ($input['city'] ?? ''));
        foreach (['college', 'city', 'education_type', 'education_detail', 'stream', 'exam_category', 'preferred_language', 'locality'] as $field) {
            if (isset($input[$field]) && !is_scalar($input[$field])) {
                json_response(['error' => 'Education profile fields must contain single values.'], 422);
            }
        }
        $educationType = trim((string) ($input['education_type'] ?? ''));
        $educationDetail = trim((string) ($input['education_detail'] ?? ''));
        $stream = trim((string) ($input['stream'] ?? ''));
        $examCategory = trim((string) ($input['exam_category'] ?? ''));
        $preferredLanguage = trim((string) ($input['preferred_language'] ?? ''));
        $locality = trim((string) ($input['locality'] ?? ''));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            json_response(['error' => 'Enter a name between 2 and 120 characters.'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            json_response(['error' => 'Enter a valid email address.'], 422);
        }
        if ($phone !== '' && !preg_match('/^[0-9+().\-\s]{7,24}$/', $phone)) {
            json_response(['error' => 'Enter a valid mobile number.'], 422);
        }
        if (strlen($password) < 12 || strlen($password) > 72) {
            json_response(['error' => 'Use a password between 12 and 72 bytes.'], 422);
        }
        if (($input['confirm_password'] ?? null) !== $password) {
            json_response(['error' => 'The passwords do not match.'], 422);
        }
        if (!in_array($input['terms_accepted'] ?? null, [true, 1, '1', 'on'], true)) {
            json_response(['error' => 'Accept the Terms and Privacy Policy to create an account.'], 422);
        }
        if (mb_strlen($college) > 160) {
            json_response(['error' => 'Institution name is too long.'], 422);
        }
        if (mb_strlen($city) > 120) {
            json_response(['error' => 'City is too long.'], 422);
        }
        if ($educationType !== '' && !in_array($educationType, ['school', 'college', 'competitive_exam', 'other_learner'], true)) {
            json_response(['error' => 'Choose a valid learner type.'], 422);
        }
        foreach ([
            'education_detail' => [$educationDetail, 120],
            'stream' => [$stream, 120],
            'exam_category' => [$examCategory, 120],
            'preferred_language' => [$preferredLanguage, 60],
            'locality' => [$locality, 120],
        ] as $field => [$value, $limit]) {
            if (mb_strlen($value) > $limit) {
                json_response(['error' => ucfirst(str_replace('_', ' ', $field)) . ' is too long.'], 422);
            }
        }

        $pdo = database_connection();
        $pdo->beginTransaction();
        try {
            $insertUser = $pdo->prepare(
                'INSERT INTO users (email, password_hash, account_status) VALUES (:email, :password_hash, "active")'
            );
            $insertUser->execute([
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $userId = (int) $pdo->lastInsertId();

            $insertProfile = $pdo->prepare(
                'INSERT INTO user_profiles
                    (user_id, full_name, mobile_number, institution, education_type, education_detail,
                     stream, exam_category, preferred_language, locality, city)
                 VALUES
                    (:user_id, :full_name, :mobile_number, :institution, :education_type, :education_detail,
                     :stream, :exam_category, :preferred_language, :locality, :city)'
            );
            $insertProfile->execute([
                'user_id' => $userId,
                'full_name' => $name,
                'mobile_number' => $phone !== '' ? $phone : null,
                'institution' => $college !== '' ? $college : null,
                'education_type' => $educationType !== '' ? $educationType : null,
                'education_detail' => $educationDetail !== '' ? $educationDetail : null,
                'stream' => $stream !== '' ? $stream : null,
                'exam_category' => $examCategory !== '' ? $examCategory : null,
                'preferred_language' => $preferredLanguage !== '' ? $preferredLanguage : null,
                'locality' => $locality !== '' ? $locality : null,
                'city' => $city !== '' ? $city : null,
            ]);

            $insertRole = $pdo->prepare(
                'INSERT INTO user_roles (user_id, role_id)
                 SELECT :user_id, id FROM roles WHERE name = :role_name'
            );
            $roles = $mode === 'seller' ? ['buyer', 'seller'] : ['buyer'];
            foreach ($roles as $role) {
                $insertRole->execute(['user_id' => $userId, 'role_name' => $role]);
                if ($insertRole->rowCount() !== 1) {
                    throw new RuntimeException('A required account role has not been seeded.');
                }
            }
            if ($mode === 'seller') {
                audit_event($userId, 'seller_mode_activated', 'user', $userId);
            }
            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception->getCode() === '23000') {
                json_response(['error' => 'An account with that email address already exists.'], 409);
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        self::sign_in($userId);
        json_response([
            'message' => 'Your account has been created.',
            'user' => self::public_user($userId),
            'redirect_url' => self::dashboard_url($mode),
        ], 201);
    }

    public static function login(array $input): never
    {
        require_csrf();
        $mode = self::requested_mode($input);
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) > 255) {
            json_response(['error' => 'Email or password is incorrect.'], 422);
        }

        $pdo = database_connection();
        $fingerprint = ip_fingerprint();
        $throttle = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email = :email AND ip_fingerprint = :ip AND succeeded = 0
               AND attempted_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE'
        );
        $throttle->execute(['email' => $email, 'ip' => $fingerprint]);
        if ((int) $throttle->fetchColumn() >= 5) {
            json_response(['error' => 'Too many sign-in attempts. Try again in 15 minutes.'], 429);
        }

        $statement = $pdo->prepare(
            'SELECT u.id, u.password_hash, u.account_status
             FROM users u WHERE u.email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();
        $valid = is_array($user)
            && $user['account_status'] === 'active'
            && password_verify($password, $user['password_hash']);

        $record = $pdo->prepare(
            'INSERT INTO login_attempts (email, ip_fingerprint, succeeded) VALUES (:email, :ip, :succeeded)'
        );
        $record->execute(['email' => $email, 'ip' => $fingerprint, 'succeeded' => $valid ? 1 : 0]);

        if (!$valid) {
            json_response(['error' => 'Email or password is incorrect.'], 401);
        }

        if ($mode === 'seller') {
            self::grant_seller_role((int) $user['id']);
        }
        self::sign_in((int) $user['id']);
        json_response([
            'message' => 'You are signed in.',
            'user' => self::public_user((int) $user['id']),
            'redirect_url' => self::dashboard_url($mode),
        ]);
    }

    public static function logout(): never
    {
        require_csrf();
        start_app_session();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $cookie['path'],
                'domain' => $cookie['domain'],
                'secure' => $cookie['secure'],
                'httponly' => $cookie['httponly'],
                'samesite' => $cookie['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        json_response(['message' => 'You are signed out.']);
    }

    public static function current(): never
    {
        $userId = require_user();
        json_response(['user' => self::public_user($userId)]);
    }

    public static function activate_seller(): never
    {
        require_csrf();
        $userId = require_user();
        self::grant_seller_role($userId);
        json_response(['message' => 'Seller mode is enabled.', 'user' => self::public_user($userId)]);
    }

    private static function dashboard_url(string $mode): string
    {
        return $mode === 'seller' ? '/seller/dashboard' : '/buyer/dashboard';
    }

    private static function requested_mode(array $input): string
    {
        $mode = $input['mode'] ?? 'buyer';
        if (!is_string($mode) || !in_array($mode, ['buyer', 'seller'], true)) {
            json_response(['error' => 'Choose Buyer or Seller mode.'], 422);
        }

        return $mode;
    }

    private static function grant_seller_role(int $userId): void
    {
        $pdo = database_connection();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'INSERT IGNORE INTO user_roles (user_id, role_id)
                 SELECT :user_id, id FROM roles WHERE name = "seller"'
            );
            $statement->execute(['user_id' => $userId]);
            if ($statement->rowCount() === 1) {
                audit_event($userId, 'seller_mode_activated', 'user', $userId);
                $pdo->commit();
                return;
            }
            if (!in_array('seller', user_roles($userId), true)) {
                throw new RuntimeException('The seller role has not been seeded.');
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private static function sign_in(int $userId): void
    {
        start_app_session();
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to rotate the session after authentication.');
        }
        $_SESSION['user_id'] = $userId;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    private static function public_user(int $userId): array
    {
        $statement = database_connection()->prepare(
            'SELECT u.id, u.email, p.full_name, p.institution, p.verification_status
             FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id WHERE u.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();
        if (!is_array($user)) {
            throw new RuntimeException('Authenticated account could not be loaded.');
        }

        $user['roles'] = user_roles($userId);
        return $user;
    }
}
