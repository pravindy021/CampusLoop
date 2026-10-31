# CampusLoop
<<<<<<< HEAD

**Pass It On. Save More. Learn Better.**

CampusLoop is a student-focused marketplace prototype with a responsive storefront and a Core PHP/MySQL backend. The storefront's sample cards are illustrative only; the signed-in buyer dashboard displays real database listings. Seller listing management, secure image intake, authentication, catalogue filters, wishlist support, product-specific text messaging, and message notifications are implemented. Checkout, rental booking, payments, refunds, moderation screens, and password recovery are not implemented.

## Project layout

- `index.html`, `styles.css`, `app.js` — responsive storefront and explicitly demo-only sample cards/interactions.
- `public/` — public document root, PHP front controller, router, static-file allowlist, and page scripts.
- `app/` — PDO authentication, catalogue search, seller listing and image handling, wishlist, messaging/notifications, and shared session/CSRF helpers.
- `config/` — environment-based application/database configuration; keep private and outside the web root.
- `database/schema.sql`, `database/seed.sql`, `database/migrations/` — MySQL/MariaDB schema, expandable student marketplace categories, production-safe base roles/categories, and additive migrations.
- `views/auth/`, `views/dashboard/` — sign-in/registration, buyer and seller dashboards, listing forms, messaging and notification pages, and read-only order/rental records.
- `bin/create-admin.php` — CLI-only initial administrator provisioning.
- `storage/` — private image and log storage; never expose it through a web server.
- `check-campusloop.ps1`, `setup-campusloop.ps1`, `run-campusloop.ps1`, `stop-campusloop.ps1` — Windows diagnostics, first-time preparation, server launcher, and safe stop.
- `tests/checklist.md` — manual verification checklist and known gaps.

## Windows quick start

### 1. Install PHP

1. Install PHP 8.1 or later for Windows (for example, the official PHP Windows ZIP build) and extract it to a stable folder such as `C:\php`.
2. Copy `php.ini-development` to `php.ini` in that folder. Open `php.ini` and set `extension_dir="ext"`.
3. Enable these extensions by removing the leading `;` from their lines: `extension=pdo_mysql`, `extension=mbstring`, and `extension=fileinfo`. PHP 8 normally includes `json` and `session`; enable them only if `php -m` shows they are missing.
4. Add the PHP folder (for example, `C:\php`) to your Windows **Path** environment variable. Close and reopen PowerShell.
5. Verify the installation:

   ```powershell
   php -v
   php --ini
   php -m
   ```

   `php --ini` tells you which `php.ini` is loaded. Changes to a different file will not enable extensions for the CLI.

### 2. Install and start MySQL

1. Install MySQL Server 8+ (or a compatible MariaDB version) and remember the administrator password you choose.
2. Start the MySQL service from Windows Services. Add the MySQL `bin` folder to Path if you want to use the `mysql` command below.
3. From the project folder, import the initial schema into a **new, empty** local database using the administrator account:

   ```powershell
   cmd /c "mysql -u root -p < database\schema.sql"
   ```

   Enter the administrator password at the prompt. The schema creates the `second_story` database. Import the built-in roles and marketplace categories:

   ```powershell
   cmd /c "mysql -u root -p second_story < database\seed.sql"
   ```

   The seed creates no demo account, product, or fake activity. Do not import the initial schema or seed over an existing database; neither is an upgrade script.

4. In an administrator MySQL session, create the application account with a unique password and least-privilege access. Use that same password when the setup script prompts for it:

   ```sql
   CREATE USER 'second_story_app'@'127.0.0.1' IDENTIFIED BY 'choose-a-unique-password';
   GRANT SELECT, INSERT, UPDATE, DELETE ON second_story.* TO 'second_story_app'@'127.0.0.1';
   ```

   Replace the example password yourself; do not share it. The app account cannot create or alter schema.

### 3. Prepare and run CampusLoop

Open PowerShell in the project directory (`D:\Desktop\Campus Loop`) and run:

```powershell
.\check-campusloop.ps1
.\setup-campusloop.ps1
```

The diagnostic prints PHP/version/extension status, PHP configuration path, MySQL client/service status, required project files, local configuration status, and storage write access. Setup creates a git-ignored `.env` only when one does not already exist, generates an application secret, and optionally accepts the database password without echoing it. It does not change an existing `.env`, import SQL, or modify database records.

Review `.env` and set the database host, port, name, username, and password to match your local server. Default database name and username are `second_story` and `second_story_app`. Keep `.env` private; it is excluded from Git.

Start the app:

```powershell
.\run-campusloop.ps1
```

The launcher loads `.env` into its own process environment, checks PHP 8.1+, required extensions, MySQL service/database connectivity and port availability, then runs the existing command with `public` as the document root and `public\router.php` as router. It uses `127.0.0.1:8000` by default, opens the browser, and remains in the foreground until Ctrl+C. To use a different local port, run `.\run-campusloop.ps1 -Port 8010`. To skip automatic browser opening, add `-NoBrowser`.

Open [http://127.0.0.1:8000/](http://127.0.0.1:8000/). Stop from another PowerShell window with:

```powershell
.\stop-campusloop.ps1
```

The stop script reads the launcher's private state file and verifies the recorded PID, process start time, PHP executable, and server/router command line before stopping that single process. It will not stop an unrelated PHP process.

### Troubleshooting

- **`php` not found:** add the folder containing `php.exe` to Windows Path, then close and reopen PowerShell.
- **Wrong/missing `php.ini`:** run `php --ini`; enable the required extensions in the exact loaded file. Restart the launcher after changing it.
- **Extension missing:** verify `extension_dir="ext"` and that the extension DLL is present under PHP's `ext` folder; run `php -m` again.
- **MySQL service stopped/not detected:** start the installed MySQL/MariaDB service in Windows Services. If you use a remote database, set `DB_HOST` in `.env`; the launcher will skip the local service check but still test the connection.
- **Database connection failed:** confirm the schema import, service/host/port, application username, password, and grants. Do not use the MySQL administrator account for the app.
- **Port 8000 occupied:** stop the application that owns the port, or use `.\run-campusloop.ps1 -Port 8010` and `.\stop-campusloop.ps1 -Port 8010`.
- **Upload errors:** ensure the project `storage\uploads` directory is writable by PHP and set `upload_max_filesize`/`post_max_size` in the loaded `php.ini` high enough for the configured image limits.
- **PowerShell blocks scripts:** if your machine's policy allows it, run `Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass` in that PowerShell window only; this does not change the machine-wide policy.
- **Server errors:** check `storage\logs\campusloop-8000.err.log` and run `.\check-campusloop.ps1`.

The public document root is `public/`. Do not expose private configuration, SQL, source, or storage directories through a production web-server alias. `SESSION_SECURE=0` is for local HTTP only.

## Database setup and upgrades

`database/schema.sql` is the initial schema, not an incremental migration. For an existing installation, back up the database and review the specific additive migration before applying it. Each migration is one-time only. For a fresh install, `database/seed.sql` inserts only the built-in roles and marketplace categories; it does not create demo users, products, or fake activity. The listing rejection-reason migration is:

```powershell
cmd /c "mysql -u root -p second_story < database\migrations\20261027_add_product_rejection_reason.sql"
```

The learner-discovery migration adds optional learner profile/listing metadata and marketplace categories without deleting existing categories or records:

```powershell
cmd /c "mysql -u root -p second_story < database\migrations\20261101_add_learner_discovery_metadata.sql"
```

The messaging migration adds conversations, messages, block/report records, and links notifications to conversations/messages:

```powershell
cmd /c "mysql -u root -p second_story < database\migrations\20261101_add_messaging_and_notifications.sql"
```

Apply each migration at most once; neither is an upgrade script for a fresh database. The learner-discovery migration preserves existing profile and listing rows; the messaging migration adds tables and nullable notification references without deleting existing data. For a fresh install, use the updated `schema.sql` and `seed.sql` instead of running these migrations.

To provision an initial administrator from a trusted CLI session, set `INITIAL_ADMIN_EMAIL` and `INITIAL_ADMIN_PASSWORD` in the process environment, run `php bin/create-admin.php`, then remove those variables. The password must be at least 16 characters. This is a one-time CLI-only operation; ordinary registration cannot grant the admin role.

## Implemented backend routes

All API routes are same-origin JSON endpoints under `/api/v1`:

- `GET /csrf` — start an anonymous session and issue a CSRF token.
- `POST /auth/register` — validates input and mode, creates an account with `password_hash()`, optionally stores learner type, class/course, stream, exam, institution, language, and location, assigns buyer access (plus seller access when selected), and signs in. No school or college ID is required.
- `POST /auth/login` — verifies credentials, throttles repeated attempts, rotates the session ID, and returns a server-selected mode dashboard URL.
- `POST /auth/logout` — CSRF-protected session logout.
- `GET /auth/me` — current account and server-assigned roles.
- `POST /seller/activate` — lets a signed-in buyer enable seller mode; cannot grant administrator role.
- `POST /seller/listings` — creates a seller-owned draft or submits a listing for approval; accepts optional validated image uploads.
- `POST /seller/listings/{id}` — edits a seller-owned non-archived listing; submitted edits return to approval.
- `POST /seller/listings/{id}/actions` — pauses/resumes an approved listing, archives a listing, or deletes a draft, subject to server-side ownership and state checks.
- `GET /media/products/{filename}` — serves generated product-image filenames from private storage; unpublished images are available only to their owning seller.
- `GET /categories` — active categories.
- `GET /products` — published/available catalogue search with paging, category/subcategory, keyword (including class, course, exam, subject, brand and location), learner type, class, exam, brand, condition, rent/buy, price, location, and whitelisted sorting.
- `GET /products/{id}` — authenticated Buyer view of a published, available listing and its stored details; no checkout or booking actions are provided.
- `GET /conversations`, `POST /conversations` — list a signed-in user's product-specific conversations or start one from an available listing with an initial message.
- `GET /conversations/{id}/messages`, `POST /conversations/{id}/messages` — read a participant-only message history or send a text reply; new messages create in-app notifications.
- `POST /conversations/{id}/block`, `POST /conversations/{id}/report` — block/unblock a conversation or file a report for later moderation.
- `GET /conversations/unread-count` — unread message count for the signed-in user.
- `GET /notifications`, `GET /notifications/unread-count`, `POST /notifications/{id}/read`, `POST /notifications/read-all` — list and update the signed-in user's in-app notifications.
- `GET /messages`, `GET /notifications` — authenticated message and notification pages. The message interface polls for updates every five seconds; it does not use a websocket.
- `GET /wishlist`, `POST /wishlist/{id}`, `DELETE /wishlist/{id}` — authenticated wishlist operations.
- `GET /buyer/dashboard`, `GET /buyer/wishlist`, `GET /buyer/orders`, `GET /seller/dashboard` — session-authenticated, role-protected HTML views.
- `GET /seller/listings`, `/seller/listings/new`, `/seller/listings/{id}/edit`, `/seller/orders`, `/seller/rentals` — protected seller listing management and read-only order/rental views.

Mutating endpoints require `X-CSRF-Token` from `/csrf`. Password reset issuance/email delivery requires a configured email provider and is not exposed until delivery can be implemented safely. Authentication pages use server-side validation; never use browser storage as authority for accounts or transactions.

## Seller functionality and limitations

- Seller listings support database-backed creation, editing, search/status filters, pause/resume, archive, draft deletion, category/subcategory validation, learner audience and education/product metadata, pickup/delivery selection, and multiple product image uploads. Books & Education listings require a seller attestation that the material can be legally shared or resold; CampusLoop does not independently verify copyright or ownership. Drafts still require the database's required core listing fields.
- Images are limited by `PRODUCT_IMAGE_MAX_COUNT` (default 5) and `PRODUCT_IMAGE_MAX_BYTES` (default 5 MiB each; maximum 20 MiB), accepted only as JPEG/PNG/WebP with server MIME and image-header checks, and stored under private `storage/uploads/products` with random filenames. Image dimensions and pixel count are bounded. Ensure PHP can write to this directory and configure `upload_max_filesize` and `post_max_size` to accommodate the selected limits.
- Seller summary metrics use listing, order-item, order, and rental-booking rows. Sale-order and rental-request screens show seller-associated records only and are read-only.
- There is no admin moderation UI or moderation-history table. New or materially edited listings remain `pending_approval` until an authorized moderation workflow is built; sellers cannot approve listings. A rejection-reason column is available for that integration.
- Product-specific text messaging, unread/read state, message notifications, blocking, and reporting are database-backed. Reports are recorded for a future moderation workflow; there is no admin report-review page. Message image attachments and profile pictures are not implemented.
- Order fulfillment/status actions, rental accept/reject transitions, overlap checks, availability calendars, handovers/returns, and earnings are not implemented. Existing tables alone are not sufficient to safely perform these transactions.
- Order checkout, order tracking, location sharing, courier integration, COD, Cash on Pickup, Razorpay, and payment/refund processing remain unimplemented. No payment or courier credentials are required or consumed by the current application.

- Homepage cards remain explicitly illustrative frontend demo content; the page has not yet been changed to render the live catalogue API.
- The seed seller account has an intentionally unusable password marker and cannot sign in.
- Optional learner preferences are collected at registration and stored, but profile editing and preference-ranked recommendations have not been implemented. Browsing remains available across all categories.
- The schema has a `product_availability` table for date-range records, but seller forms do not manage those records and rental booking does not check conflicts yet. Negotiable pricing and free-text condition details are not implemented.
- The Buyer Dashboard loads published, available catalogue records from the search API and provides server-side filters and pagination, listing details, database-backed wishlist actions, and read-only views of existing orders and rental bookings. Orders and rentals are displayed only when real records exist.
- The Buyer Dashboard does not implement checkout or rental booking, so listing-detail purchase and rental actions are intentionally unavailable. Order and rental request views cannot create or change transactions.
- No purchase or rental booking endpoint exists. Do not accept an order, reserve inventory, or confirm availability until transactional concurrency checks and ownership/status transitions are implemented.
- Payment rows default to demo mode. No provider, webhook signature verification, payment capture, real refund, escrow, or payout flow is implemented.
- Refunds, commissions, reviews, complaints, admin moderation/API authorization, verification review, and analytics currently have schema only.
- An administrator account can be provisioned by CLI, but administrator management routes and UI are not implemented.
- Search results, wishlist, order history, rental booking history, and product details require PHP and MySQL configuration. Product listings have no currency column; buyer views display stored numeric amounts without assigning an assumed currency. Currency conversion in the standalone storefront is illustrative, not a payment quote.
- The starter does not yet include automated PHP integration tests. Follow `tests/checklist.md` after installing the runtime and database.
- Terms and Privacy Policy content/pages are not published; provide approved policy documents and links before accepting real registrations.

Do not process real users, personal documents, payments, bookings, or uploaded files with this prototype until the missing workflows are implemented, tested, and reviewed.
=======
CampusLoop is a student-first marketplace to buy, sell, and rent electronics, college books, study materials, home appliances, and everyday essentials. Built to make products more affordable through reuse, resale, and rentals.
>>>>>>> origin/main
