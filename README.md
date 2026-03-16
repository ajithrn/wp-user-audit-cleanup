# WordPress User Audit & Cleanup

A WordPress plugin that enhances the admin Users screen with advanced filtering, spam detection, and bulk management capabilities.

## Features

- **Last Login Tracking** — Records and displays when each user last logged in as a sortable column.
- **Spam Score** — Calculates a 0–100 spam likelihood score based on login history, registration recency, disposable email usage, username patterns, display name, comment activity, and WooCommerce order history.
- **Existing User Scanner** — Detects prior logins via WordPress session tokens, backfills login data, and auto-flags high-risk accounts.
- **Advanced Filters** — Filter users by registration date range, last login date range, login status, high risk score, or disposable email domain.
- **Bulk Spam Flagging** — Flag or unflag users as spam directly from the Users list.
- **Spam View** — Dedicated "Spam" view with count, bulk delete, and CSV export.
- **Spam Email Lookup** — Paste a list of suspected spam emails to find and delete matching accounts.
- **Disposable Email Detection** — Bundled list of 200+ disposable email domains with auto-flagging on registration.
- **Inactive User Cleanup** — Find and delete users who registered N+ days ago but never logged in.
- **CSV Export** — Export flagged spam users with details and spam scores.
- **Domain Management** — Add or remove disposable email domains via the settings page.
- **Data Erasure** — One-click removal of all plugin data from the database.

## Requirements

- WordPress 5.9+
- PHP 7.4+

## Installation

1. Clone or download this repository into your `wp-content/plugins/` directory.
2. Activate the plugin through **Plugins → Installed Plugins** in the WordPress admin.
3. Navigate to **Users** to see the new columns, filters, and bulk actions.

## Usage

- **Users List** — New "Last Login" and "Spam Score" columns appear automatically. Use the filter row above the table to narrow results.
- **Users → User Audit** — Unified dashboard with three tabs:
  - **Email Lookup** — Bulk email matching and deletion
  - **Inactive Cleanup** — Find and remove users who never logged in
  - **Settings** — Domain management, user scanner, and data erasure

## Screenshots

1. Users list with Last Login and Spam Score columns
2. Filter row with date pickers and login status dropdown
3. Spam Email Lookup page with bulk email matching
4. Audit Settings page with disposable domain management

## Contributing

1. Fork the repository.
2. Create a feature branch: `git checkout -b feature/my-feature`
3. Commit your changes: `git commit -m 'Add my feature'`
4. Push to the branch: `git push origin feature/my-feature`
5. Open a Pull Request.

Please follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/).

## Roadmap

### Advanced Email Spam Detection (Next)

- **Email entropy scoring** — Detect gibberish local parts (e.g., `xkj3892kd@gmail.com`) using randomness analysis.
- **Gmail dot-trick normalization** — Identify duplicate accounts using Gmail dot variations (`u.s.e.r` vs `user`).
- **Plus-addressing detection** — Flag emails using `+tag` variations for multiple account creation.
- **MX record validation** — Verify that email domains have valid mail servers configured.
- **Role-based email detection** — Flag registrations using `admin@`, `info@`, `test@`, `noreply@` prefixes.
- **Domain clustering** — Detect suspicious patterns when many users register with the same uncommon domain.
- **Third-party API integration** — Optional integration with StopForumSpam, Abstract API, or ZeroBounce for real-time email validation.

### WooCommerce Card Testing Detection

- **Failed order ratio** — Flag users with multiple failed/cancelled orders and zero completed ones.
- **Small amount orders** — Detect users with repeated orders below a configurable threshold (e.g., 3+ orders under $5).
- **Rapid order velocity** — Flag users placing multiple orders within a short time window (e.g., 5+ orders in 1 hour).
- **Multiple payment method failures** — Detect different card numbers tried in quick succession via order meta.
- **Mismatched billing info** — Flag users with different billing names/addresses across orders.

## License

This project is licensed under the GPL-2.0-or-later — see the [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html) for details.
