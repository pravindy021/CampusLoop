<?php
declare(strict_types=1);

final class Catalog
{
    public static function categories(): never
    {
        $statement = database_connection()->query(
            'SELECT id, name, slug, description FROM categories WHERE is_active = 1 ORDER BY sort_order, name'
        );
        json_response(['data' => $statement->fetchAll()]);
    }

    public static function search(array $input): never
    {
        $pdo = database_connection();
        foreach (['page', 'per_page', 'q', 'category', 'subcategory_id', 'condition', 'education_type',
            'class_level', 'institution', 'course', 'branch', 'semester', 'stream', 'exam_category',
            'subject', 'brand', 'locality', 'mode', 'sort', 'city', 'country', 'min_price', 'max_price'] as $key) {
            if (isset($input[$key]) && !is_scalar($input[$key])) {
                json_response(['error' => 'Search filters must contain single values.'], 422);
            }
        }
        $page = filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $perPage = filter_var($input['per_page'] ?? 12, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 48]]);
        if ($page === false || $perPage === false) {
            json_response(['error' => 'Page and page size must be valid positive integers.'], 422);
        }
        $sortColumn = 'p.sale_price';
        $sorts = [
            'newest' => 'p.created_at DESC',
        ];
        $sort = (string) ($input['sort'] ?? 'newest');

        $where = [
            'p.moderation_status = "published"',
            'p.availability_status = "available"',
            'p.quantity > 0',
            'p.archived_at IS NULL',
        ];
        $parameters = [];
        $text = trim((string) ($input['q'] ?? ''));
        if ($text !== '') {
            if (mb_strlen($text) > 120) {
                json_response(['error' => 'Search text must be 120 characters or fewer.'], 422);
            }
            $searchColumns = [
                'p.title',
                'p.description',
                'c.name',
                'p.city',
                'e.class_level',
                'e.institution',
                'e.course',
                'e.branch',
                'e.stream',
                'e.exam_category',
                'e.subject',
                'e.brand',
                'e.locality',
            ];
            $searchParts = [];
            foreach ($searchColumns as $index => $column) {
                $parameter = 'query_' . $index;
                $searchParts[] = $column . ' LIKE :' . $parameter;
                $parameters[$parameter] = '%' . addcslashes($text, '%_\\') . '%';
            }
            $where[] = '(' . implode(' OR ', $searchParts) . ')';
        }
        $filters = [
            'category' => ['c.slug = :category', 'category'],
            'subcategory_id' => ['p.subcategory_id = :subcategory_id', 'subcategory_id'],
            'condition' => ['p.condition_label = :condition', 'condition'],
            'education_type' => ['e.education_type = :education_type', 'education_type'],
            'class_level' => ['e.class_level LIKE :class_level', 'class_level'],
            'institution' => ['e.institution LIKE :institution', 'institution'],
            'course' => ['e.course LIKE :course', 'course'],
            'branch' => ['e.branch LIKE :branch', 'branch'],
            'semester' => ['e.semester = :semester', 'semester'],
            'stream' => ['e.stream LIKE :stream', 'stream'],
            'exam_category' => ['e.exam_category LIKE :exam_category', 'exam_category'],
            'subject' => ['e.subject LIKE :subject', 'subject'],
            'brand' => ['e.brand LIKE :brand', 'brand'],
            'locality' => ['e.locality LIKE :locality', 'locality'],
        ];
        $filterLimits = [
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
        ];
        foreach ($filters as $key => [$condition, $parameter]) {
            $value = trim((string) ($input[$key] ?? ''));
            if ($value !== '') {
                if (isset($filterLimits[$key]) && mb_strlen($value) > $filterLimits[$key]) {
                    json_response(['error' => ucfirst(str_replace('_', ' ', $key)) . ' is too long.'], 422);
                }
                if ($key === 'condition' && !in_array($value, ['new', 'like_new', 'good', 'fair'], true)) {
                    json_response(['error' => 'Choose a valid product condition.'], 422);
                }
                if ($key === 'category' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
                    json_response(['error' => 'Choose a valid product category.'], 422);
                }
                if ($key === 'education_type'
                    && !in_array($value, ['school', 'college', 'competitive_exam', 'other_learner'], true)) {
                    json_response(['error' => 'Choose a valid learner type.'], 422);
                }
                $where[] = $condition;
                $parameters[$parameter] = in_array(
                    $key,
                    ['class_level', 'institution', 'course', 'branch', 'stream', 'exam_category', 'subject', 'brand', 'locality'],
                    true
                )
                    ? '%' . addcslashes($value, '%_\\') . '%'
                    : $value;
            }
        }

        $mode = (string) ($input['mode'] ?? '');
        if ($mode === 'buy') {
            $where[] = 'p.listing_type IN ("sell_only", "rent_and_sell")';
        } elseif ($mode === 'rent') {
            $where[] = 'p.listing_type IN ("rent_only", "rent_and_sell")';
        } elseif ($mode !== '') {
            json_response(['error' => 'Mode must be buy or rent.'], 422);
        }
        if ($mode === 'rent') {
            $sortColumn = 'p.daily_rental_price';
        } elseif ($mode === '') {
            $sortColumn = 'COALESCE(p.sale_price, p.daily_rental_price)';
        }
        $sorts['price_low'] = $sortColumn . ' ASC';
        $sorts['price_high'] = $sortColumn . ' DESC';
        if (!isset($sorts[$sort])) {
            json_response(['error' => 'Unsupported sort option.'], 422);
        }

        $city = trim((string) ($input['city'] ?? ''));
        $country = strtoupper(trim((string) ($input['country'] ?? '')));
        if ($city !== '') {
            if (mb_strlen($city) > 120) {
                json_response(['error' => 'City must be 120 characters or fewer.'], 422);
            }
            $where[] = 'p.city LIKE :city';
            $parameters['city'] = '%' . addcslashes($city, '%_\\') . '%';
        }
        if ($country !== '') {
            if (!preg_match('/^[A-Z]{2}$/', $country)) {
                json_response(['error' => 'Country must be a two-letter ISO code.'], 422);
            }
            $where[] = 'p.country_code = :country';
            $parameters['country'] = $country;
        }

        foreach (['min_price' => 'min_price', 'max_price' => 'max_price'] as $inputKey => $parameter) {
            if (isset($input[$inputKey]) && $input[$inputKey] !== '') {
                $amount = filter_var($input[$inputKey], FILTER_VALIDATE_FLOAT);
                if ($amount === false || $amount < 0 || $amount > 100000000) {
                    json_response(['error' => 'Price filter is outside the allowed range.'], 422);
                }
                $operator = $inputKey === 'min_price' ? '>=' : '<=';
                $column = $mode === 'rent'
                    ? 'p.daily_rental_price'
                    : ($mode === '' ? 'COALESCE(p.sale_price, p.daily_rental_price)' : 'p.sale_price');
                $where[] = $column . ' ' . $operator . ' :' . $parameter;
                $parameters[$parameter] = number_format($amount, 2, '.', '');
            }
        }
        if (isset($parameters['min_price'], $parameters['max_price'])
            && (float) $parameters['min_price'] > (float) $parameters['max_price']) {
            json_response(['error' => 'Minimum price cannot exceed maximum price.'], 422);
        }

        $joinEducation = 'LEFT JOIN product_education e ON e.product_id = p.id';
        $from = ' FROM products p
                  INNER JOIN categories c ON c.id = p.category_id
                  INNER JOIN users seller ON seller.id = p.seller_id AND seller.account_status = "active"
                  LEFT JOIN user_profiles profile ON profile.user_id = seller.id
                  ' . $joinEducation;
        $where[] = 'c.is_active = 1';
        $whereSql = implode(' AND ', $where);

        $count = $pdo->prepare('SELECT COUNT(DISTINCT p.id)' . $from . ' WHERE ' . $whereSql);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();

        $sql = 'SELECT p.id, p.title, p.description, p.condition_label, p.listing_type,
                       p.sale_price, p.daily_rental_price, p.security_deposit,
                       p.city, p.country_code, p.created_at, c.name AS category,
                       e.class_level, e.exam_category, e.brand, e.subject,
                       profile.full_name AS seller_name,
                       (SELECT pi.image_path FROM product_images pi
                        WHERE pi.product_id = p.id ORDER BY pi.sort_order, pi.id LIMIT 1) AS image
                ' . $from . ' WHERE ' . $whereSql . '
                ORDER BY ' . $sorts[$sort] . ', p.id DESC
                LIMIT :limit OFFSET :offset';
        $statement = $pdo->prepare($sql);
        foreach ($parameters as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        json_response([
            'data' => $statement->fetchAll(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => (int) ceil($total / $perPage),
            ],
            'demo_data' => false,
        ]);
    }

    public static function product_details(int $productId): ?array
    {
        $statement = database_connection()->prepare(
            'SELECT p.id, p.title, p.description, p.condition_label, p.listing_type,
                    p.sale_price, p.daily_rental_price, p.weekly_rental_price, p.monthly_rental_price,
                    p.security_deposit, p.minimum_rental_days, p.maximum_rental_days,
                    p.quantity, p.availability_status, p.city, p.country_code,
                    e.education_type, e.class_level, e.institution, e.course, e.branch,
                    e.semester, e.stream, e.exam_category, e.subject, e.brand, e.locality,
                    c.name AS category, c.slug AS category_slug,
                    COALESCE(NULLIF(profile.public_name, ""), profile.full_name) AS seller_name,
                    p.seller_id
             FROM products p
             INNER JOIN categories c ON c.id = p.category_id AND c.is_active = 1
             INNER JOIN users seller ON seller.id = p.seller_id AND seller.account_status = "active"
             LEFT JOIN user_profiles profile ON profile.user_id = seller.id
             LEFT JOIN product_education e ON e.product_id = p.id
             WHERE p.id = :id
               AND p.moderation_status = "published"
               AND p.availability_status = "available"
               AND p.quantity > 0
               AND p.archived_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $productId]);
        $product = $statement->fetch();
        if (!is_array($product)) {
            return null;
        }

        $images = database_connection()->prepare(
            'SELECT image_path, alt_text FROM product_images
             WHERE product_id = :product_id ORDER BY sort_order, id'
        );
        $images->execute(['product_id' => $productId]);
        $product['images'] = $images->fetchAll();

        return $product;
    }

    public static function create_listing(array $input): never
    {
        SellerListings::save($input, $_FILES);
    }

}
