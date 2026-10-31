USE second_story;

ALTER TABLE user_profiles
    ADD COLUMN education_type ENUM('school', 'college', 'competitive_exam', 'other_learner') NULL AFTER institution,
    ADD COLUMN education_detail VARCHAR(120) NULL AFTER education_type,
    ADD COLUMN stream VARCHAR(120) NULL AFTER education_detail,
    ADD COLUMN exam_category VARCHAR(120) NULL AFTER stream,
    ADD COLUMN preferred_language VARCHAR(60) NULL AFTER exam_category,
    ADD COLUMN locality VARCHAR(120) NULL AFTER preferred_language,
    ADD INDEX idx_profiles_education (education_type, education_detail, exam_category);

ALTER TABLE product_education
    ADD COLUMN education_type ENUM('school', 'college', 'competitive_exam', 'other_learner') NULL AFTER institution,
    ADD COLUMN class_level VARCHAR(80) NULL AFTER education_type,
    ADD COLUMN stream VARCHAR(120) NULL AFTER semester,
    ADD COLUMN exam_category VARCHAR(120) NULL AFTER stream,
    ADD COLUMN brand VARCHAR(120) NULL AFTER subject,
    ADD COLUMN locality VARCHAR(120) NULL AFTER brand,
    ADD INDEX idx_product_education_audience (education_type, class_level, exam_category);

INSERT INTO categories (name, slug, description, sort_order) VALUES
    ('Electronics & Gadgets', 'electronics-gadgets', 'Second-hand technology and study gadgets.', 1),
    ('Books & Education', 'college-school', 'Books and legally shareable learning materials for every learner.', 2),
    ('School Essentials', 'school-essentials', 'School supplies, bags, uniforms, and learning essentials.', 3),
    ('College & Hostel Essentials', 'college-hostel-essentials', 'Furniture, appliances, and everyday campus essentials.', 4),
    ('Stationery & Art', 'stationery-art', 'Study stationery, craft materials, and art supplies.', 5),
    ('Sports & Fitness', 'sports-fitness', 'Sports equipment and fitness accessories.', 6),
    ('Daily Student Lifestyle', 'daily-student-lifestyle', 'Bags, clothing, travel, and personal organization.', 7),
    ('Home Accessories & Appliances', 'home-accessories-appliances', 'Furniture, appliances, and room essentials.', 8),
    ('Other Products', 'other-products', 'Other student needs; listings are subject to moderation.', 9)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    sort_order = VALUES(sort_order),
    is_active = TRUE;

INSERT INTO subcategories (category_id, name, slug)
SELECT c.id, items.name, items.slug
FROM categories c
JOIN (
    SELECT 'college-school' AS category_slug, 'School textbooks and workbooks' AS name, 'school-textbooks-workbooks' AS slug
    UNION ALL SELECT 'college-school', 'NCERT and board books', 'ncert-board-books'
    UNION ALL SELECT 'college-school', 'College and reference books', 'college-reference-books'
    UNION ALL SELECT 'college-school', 'Exam preparation books', 'exam-preparation-books'
    UNION ALL SELECT 'college-school', 'Notes and practice material', 'notes-practice-material'
    UNION ALL SELECT 'electronics-gadgets', 'Computers and tablets', 'computers-tablets'
    UNION ALL SELECT 'electronics-gadgets', 'Phones and accessories', 'phones-accessories'
    UNION ALL SELECT 'electronics-gadgets', 'Calculators and study tech', 'calculators-study-tech'
    UNION ALL SELECT 'electronics-gadgets', 'Audio and peripherals', 'audio-peripherals'
    UNION ALL SELECT 'school-essentials', 'Bags and stationery', 'school-bags-stationery'
    UNION ALL SELECT 'school-essentials', 'Uniforms and shoes', 'uniforms-shoes'
    UNION ALL SELECT 'school-essentials', 'Art and sports supplies', 'school-art-sports'
    UNION ALL SELECT 'college-hostel-essentials', 'Furniture and lighting', 'furniture-lighting'
    UNION ALL SELECT 'college-hostel-essentials', 'Bedding and storage', 'bedding-storage'
    UNION ALL SELECT 'college-hostel-essentials', 'Kitchen and daily essentials', 'kitchen-daily-essentials'
    UNION ALL SELECT 'college-hostel-essentials', 'Bicycles and travel', 'bicycles-travel'
    UNION ALL SELECT 'stationery-art', 'Notebooks and writing', 'notebooks-writing'
    UNION ALL SELECT 'stationery-art', 'Art and craft supplies', 'art-craft-supplies'
    UNION ALL SELECT 'stationery-art', 'Lab and project materials', 'lab-project-materials'
    UNION ALL SELECT 'sports-fitness', 'Sports equipment', 'sports-equipment'
    UNION ALL SELECT 'sports-fitness', 'Fitness and yoga', 'fitness-yoga'
    UNION ALL SELECT 'daily-student-lifestyle', 'Bags and accessories', 'bags-accessories'
    UNION ALL SELECT 'daily-student-lifestyle', 'Clothing and shoes', 'clothing-shoes'
    UNION ALL SELECT 'daily-student-lifestyle', 'Travel and organization', 'travel-organization'
) items ON items.category_slug = c.slug
ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = TRUE;
