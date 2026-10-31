# Backend verification checklist

The project can be syntax-checked with PHP. Database-backed authentication, catalogue, listing, upload, and authorization checks require PHP 8.1+ with `pdo_mysql`, `mbstring`, `json`, `session`, and `fileinfo`, plus a running MySQL-compatible server. On Windows, start with `.\check-campusloop.ps1` and follow `README.md`.

## Environment limitation noted during this update

On 2026-11-01, PHP 8.5.12 was available and syntax checks passed for all 22 PHP files, but `pdo_mysql`, `mbstring`, and `fileinfo` were not enabled, and no MySQL client or service was detected. Database connectivity, account flows, listing queries/uploads, and role permissions could therefore not be exercised in this environment.

## Automated / syntax checks

- Run `php -l` on every `*.php` file.
- For a new empty database only, import `database/schema.sql` and `database/seed.sql`; confirm the seed creates built-in roles/categories but no sample user or product. Do not rerun the initial schema against a populated database.
- Request `/api/v1/csrf`, register a new account, inspect that the password is hashed, and verify that the session ID changes.
- Register in Buyer mode and Seller mode; confirm both receive buyer role and Seller mode is assigned only when selected.
- Verify invalid mode values, invalid fields, mismatched passwords, missing terms acceptance, and duplicate normalized email are rejected.
- Log in in each mode; verify server-selected redirects go to the corresponding protected dashboard.
- Verify a buyer can activate seller mode with CSRF and then access both dashboards without creating a second account.
- Verify anonymous users are redirected to the selected-mode login page and authenticated users without seller role receive no seller dashboard access.
- Log out with CSRF; confirm the session cookie is cleared and protected dashboards require sign-in again.
- Verify already-authenticated users visiting login/register are sent to a dashboard rather than shown the auth form.
- Confirm invalid CSRF and mismatched `Origin` headers are rejected.
- Confirm repeated invalid sign-ins are throttled and the response does not reveal whether an email exists.
- Anonymous requests to messaging and notification APIs must return 401; anonymous message/notification pages must redirect to sign-in.

## Authorization and data integrity

- Confirm anonymous requests cannot read or change a wishlist.
- Verify Buyer Dashboard results come only from active-category, active-seller listings that are published, available, in stock, and not archived.
- Verify keyword, category, listing mode, price range, condition, city, and country filters reject invalid inputs and use pagination.
- Verify keyword search covers category, class, course, exam, subject, brand, and city; dedicated learner type/class/exam/brand/locality filters are validated and parameterized.
- Verify a listing detail URL returns only an eligible listing, returns 404 for missing/ineligible listing IDs, and escapes listing and seller content.
- Add and remove wishlist entries with valid CSRF; confirm duplicate saves are idempotent and users cannot change another user's wishlist.
- Confirm order and rental views show only records belonging to the signed-in buyer and do not fabricate records or submit transactions.
- Confirm missing or unsafe image paths render a placeholder and product amounts are not assigned a currency absent from the schema.
- Confirm a buyer cannot create seller listings until seller mode is activated.
- Confirm seller activation never grants administrator privileges.
- Verify seller dashboard metrics reflect actual seller-owned products, completed seller order-item quantities, completed orders, and requested rental bookings only.
- Confirm seller listing filters and pagination cannot show another seller's products.
- Confirm seller listing edits do not create or alter `product_availability` date ranges; the existing table is not wired to the seller form or booking conflict checks yet.
- Create a valid listing as a seller; verify drafts remain private and submissions are pending moderation.
- Verify prices, category/subcategory relationships, conditions, handover options, listing type, rental duration, and education attributes are validated server-side.
- Verify registration accepts optional learner details for school, college, competitive-exam, and other learners without requiring an institutional ID.
- Verify listing audience, class/level, course, stream, exam, subject, brand, institution, and locality metadata round-trip through create/edit, search, and product details.
- Verify Books & Education listings require the legal-sharing attestation server-side and save the confirmation; the attestation is not a copyright verification.
- Verify a seller cannot edit or archive another seller's listing or approve any listing.
- Verify material edits reset a published listing to `pending_approval` and clear any previous rejection note.
- Verify only drafts can be deleted; pause/resume are restricted to published listings in the appropriate state; archive cannot be reversed by seller actions.
- Upload valid JPEG/PNG/WebP images and confirm random filenames, configured count/size limits, image content/dimensions checks, private storage, and primary-image ordering.
- Reject spoofed MIME types, executable files, oversized files, malformed image content, excessive dimensions, and attempts to remove images from another listing.
- Confirm unpublished listing images are visible only to their owning seller; public image access is limited to approved, available, non-archived listings.
- Confirm sale-order and rental-request views contain only seller-associated database rows and have no misleading state/payment controls.
- Test repeated form submissions, storage failures, and database failures; confirm staged image files are cleaned up and errors are safe.
- Verify listing search excludes drafts, pending, rejected, paused, archived, and sold listings.
- Verify SQL sort input is restricted to the documented whitelist and search filters are parameterized.
- On a copy of an existing database, back up first and apply `20261101_add_learner_discovery_metadata.sql` once; verify existing products/users remain and the new categories/subcategories are available.
- Apply `20261101_add_messaging_and_notifications.sql` once on a backed-up database; verify pre-existing notifications remain and new tables/nullable references exist.
- Verify a buyer can start one product-specific conversation with an active listing seller, but cannot message their own listing or start a conversation for an unavailable listing.
- Verify a conversation participant can read and reply, and an unrelated account cannot list, read, send to, block, or report that conversation.
- Verify messages are limited to valid UTF-8 text up to 2,000 characters, render as text (not HTML), and do not expose stored email/mobile fields.
- Verify a participant's incoming messages and linked notifications become read only when loaded, older-message paging works, and unread counters update for both participants.
- Verify a block prevents further messages from either participant and reports are recorded with the correct reporter and conversation.
- Verify opening/marking one notification read cannot affect another user's notifications; mark-all is scoped to the signed-in user and CSRF-protected.
- Review deletion behavior on orders, payments, rentals, refunds, reviews, and audit history.

## Not implemented yet

- Password-reset token issuance and delivery; token delivery needs a configured email provider.
- Admin moderation pages, rejection workflows and moderation history.
- Message-image attachments, profile pictures, admin conversation-report review, and transaction-linked conversations.
- Order fulfillment actions, rental accept/reject, availability conflicts, handover/return workflows, and payment integration.
- Product category/subcategory administration.
- Purchase placement, inventory locking, rental booking conflict checks and state transitions.
- Payment-provider callbacks/webhooks, refunds, commission configuration, payouts, reviews, complaints, and order/rental notifications.
- End-to-end tests against a configured PHP/MySQL environment.
