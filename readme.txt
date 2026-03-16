=== WordPress User Audit & Cleanup ===
Contributors: ajithrn
Tags: users, spam, cleanup, audit, security
Requires at least: 5.9
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enhances the WordPress admin Users screen with advanced filtering, spam detection, and bulk management capabilities.

== Description ==

WordPress User Audit & Cleanup adds powerful user management tools to your WordPress admin. Identify spam accounts, track login activity, and clean up your user database with ease.

**Key Features:**

* **Last Login Tracking** — Automatically records when each user last logged in and displays it in a sortable column.
* **Spam Score** — Computes a 0–100 spam likelihood score based on login history, registration recency, disposable email usage, username patterns, display name, comment activity, and WooCommerce order history.
* **Advanced Filters** — Filter users by registration date range, last login date range, login status (never logged in, has logged in), high risk score, or disposable email domain.
* **Bulk Spam Flagging** — Flag or unflag users as spam directly from the Users list with bulk actions.
* **Spam View** — Dedicated "Spam" view link on the Users screen showing all flagged accounts with a count.
* **Spam Email Lookup** — Paste a list of suspected spam emails to find and delete matching user accounts.
* **Disposable Email Detection** — Bundled list of 200+ disposable email domains. New registrations from these domains are automatically flagged as spam.
* **Inactive User Cleanup** — Find and delete users who registered more than N days ago but never logged in.
* **CSV Export** — Export a report of all flagged spam users with their details and spam scores.
* **Domain Management** — Add or remove disposable email domains from the detection list via a settings page.
* **Data Erasure** — One-click removal of all plugin data from the database.

**No Custom Tables**

The plugin stores all data in the standard `wp_usermeta` table using two meta keys (`_wuac_last_login` and `_wuac_spam_flag`). No custom database tables are created.

**Admin Only**

All plugin features are restricted to administrators with the `manage_options` capability.

== Installation ==

1. Upload the `wp-user-audit-cleanup` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Users** to see the new columns, filters, and bulk actions.
4. Use **Users → Spam Email Lookup** for bulk email matching and inactive user cleanup.
5. Use **Users → Audit Settings** to manage disposable domains and erase plugin data.

== Frequently Asked Questions ==

= Does this plugin create custom database tables? =

No. All data is stored in the existing `wp_usermeta` table using prefixed meta keys.

= What happens when I deactivate the plugin? =

All plugin data (login timestamps and spam flags) is retained in the database. You can remove it manually from the Audit Settings page before deactivating, or uninstall the plugin to remove all data automatically.

= How is the spam score calculated? =

The score (0–100) is the sum of applicable factors:

* No login recorded: 30 points
* Registered within last 24 hours: 10 points
* Disposable email domain: 30 points
* Username contains 5+ consecutive digits: 10 points
* Display name matches email address: 20 points
* No approved comments: 10 points
* No WooCommerce orders (only if WooCommerce is active): 10 points

= Can I customize the disposable email domain list? =

Yes. Go to **Users → Audit Settings** to add or remove domains from the detection list.

= What does "High Risk" mean in the filter dropdown? =

It shows users with a spam score of 70 or higher.

== Screenshots ==

1. Users list with Last Login and Spam Score columns.
2. Filter row with date pickers and login status dropdown.
3. Spam Email Lookup page with bulk email matching.
4. Audit Settings page with disposable domain management.

== Planned Features ==

**WooCommerce Card Testing Detection (v1.3.0)**

* Failed order ratio analysis — flag users with many failed orders and no completed ones.
* Small amount order detection — identify repeated micro-transactions used for card validation.
* Rapid order velocity — detect multiple orders placed within a short time window.
* Mismatched billing info across orders.

**Advanced Email Spam Detection (v1.4.0)**

* Email entropy scoring to detect gibberish addresses.
* Gmail dot-trick and plus-addressing normalization.
* MX record validation for email domains.
* Role-based email detection (admin@, test@, noreply@).
* Domain clustering for suspicious registration patterns.
* Optional third-party API integration (StopForumSpam, ZeroBounce).

== Changelog ==

= 1.2.0 =
* Added session token-based login detection for existing users on activation.
* New "Scan Users" button on the settings page to backfill login data and auto-flag spam.
* Spam score now includes "no approved comments" factor (+10 points).
* Spam score now includes "no WooCommerce orders" factor (+10 points, only when WooCommerce is active).
* Supports WooCommerce HPOS (High-Performance Order Storage) and legacy post-based orders.
* Users with session tokens get their registration date backfilled as baseline login timestamp.

= 1.1.0 =
* Improved UI/UX across all admin pages with card-based layouts.
* Color-coded spam score badges (low/medium/high) in the users list.
* Styled "Never" label for users who have not logged in.
* Redesigned filter row with labeled groups and date range separators.
* Wrapped spam view actions in a flex container for consistent alignment.
* Email Lookup page now uses result summary badges and structured form layout.
* Settings page uses card sections with a red danger zone for data erasure.
* Replaced all inline styles with dedicated CSS classes.
* Added responsive breakpoints for mobile admin views.

= 1.0.0 =
* Initial release.
* Last login tracking with sortable column.
* Spam score calculation and display.
* Registration date and last login date range filters.
* Login status filter (Never Logged In, Has Logged In, High Risk, Disposable Email).
* Bulk Flag as Spam / Unflag Spam actions.
* Spam view with Delete Spam Users and Export CSV buttons.
* Spam Email Lookup admin page.
* Inactive User Cleanup section.
* Disposable email domain detection with bundled 200+ domain list.
* Settings page with domain management and data erasure.
* CSV export for flagged spam users.

== Upgrade Notice ==

= 1.2.0 =
Improved spam detection with comment and WooCommerce order checks. Session token-based login backfill for existing users.

= 1.1.0 =
UI/UX improvements with card-based layouts, color-coded spam scores, and responsive design.

= 1.0.0 =
Initial release.
