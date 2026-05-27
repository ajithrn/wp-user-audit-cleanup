# Technical Details

## Spam Score — How It Works

The spam score is a number between 0 and 100 that estimates how likely a user account is spam. It is calculated on-the-fly each time the Users list is loaded — nothing is stored in the database.

The score is the sum of individual weighted factors. If the total exceeds 100, it is capped at 100.

## Scoring Factors

| Factor | Weight | Condition | Rationale |
|---|---|---|---|
| No login recorded | 25 | `_wuac_last_login` user meta is empty | Users who have never logged in are more likely to be bot-created accounts. On activation, the plugin backfills this using WordPress session tokens so legitimate existing users aren't penalized. |
| Recent registration | 5 | Registered within the last 24 hours | Freshly created accounts haven't had time to prove legitimacy. This factor naturally expires after 24 hours. |
| Disposable email domain | 30 | Email domain matches the bundled/custom disposable domain list | Disposable email services are heavily used by spammers to create throwaway accounts. The plugin ships with 200+ known domains and allows admins to add/remove entries. |
| Digit-heavy username | 10 | Username contains 5 or more consecutive digits | Automated account creation tools often generate usernames like `user38291` or `john12345678`. |
| Gibberish username | 15 | Username has high entropy, low vowel ratio, or consonant clusters | Bot-generated usernames like `xkj3892kd`, `htgfr45`, `zzxxyy` have detectable randomness patterns. Uses Shannon entropy and vowel-consonant ratio analysis. |
| Bot username pattern | 15 | Username matches common bot templates | Detects patterns like all-digit usernames, `firstname.lastname` + long digit suffix, repeating characters, keyboard walks (`qwerty`, `asdfgh`), and alternating consonant-digit sequences. |
| Display name is email | 15 | `display_name` exactly matches `user_email` | WordPress sets the display name to the email by default during registration. Real users typically update this; bots don't bother. |
| Display name matches username | 10 | `display_name` equals `user_login` (case-insensitive) | Bots rarely customize their display name. WordPress defaults it to the username; legitimate users usually set a real name. |
| Display name has spam/URL | 25 | Display name contains URLs or spam keywords | Bots often stuff display names with URLs, casino/crypto/pharmacy keywords, or other spam content. Very strong signal. |
| Suspicious email pattern | 15 | Email local part has excessive dots (3+), high digit ratio (>50%), all-numeric, gibberish, or multiple consecutive special chars | Catches bot-generated emails even on legitimate providers like Gmail/Outlook. E.g., `xkj3892kd@gmail.com`, `928374651@outlook.com`. |
| Plus-addressing in email | 5 | Email uses `user+tag@domain.com` format | Plus-addressing can be used to create multiple accounts from a single email. |
| Spam URL in profile | 15 | User website URL contains spam TLDs or spam keywords | Bots frequently stuff their profile URL with links to spam sites using TLDs like `.xyz`, `.top`, `.tk`, etc. |
| No approved comments | 5 | Zero approved comments in `wp_comments` | A user who registered but never commented shows no engagement with the site. Weak signal alone but compounds with others. |
| No WooCommerce orders | 5 | Zero orders in WooCommerce (only when WooCommerce is active) | On a store, a registered user with no purchase history may be a bot. This check is skipped entirely if WooCommerce is not installed. |

## Maximum Possible Score

| Scenario | Max Score |
|---|---|
| Without WooCommerce | 190 → capped to **100** |
| With WooCommerce | 195 → capped to **100** |

Since the score is capped at 100, not every factor needs to apply for a user to reach the maximum.

## Risk Levels

The plugin uses three risk levels for display in the Users list:

| Level | Score Range | Badge Color |
|---|---|---|
| Low | 0–39 | Green |
| Medium | 40–69 | Yellow |
| High | 70–100 | Red |

Hovering over the score badge shows a tooltip with the full breakdown of which factors triggered.

## Auto-Flagging Threshold

The user scanner (runs on activation or manually from Settings) auto-flags users with a score of **70 or higher** as spam. The current admin is never auto-flagged.

## Session Token Backfill

WordPress stores `session_tokens` in `wp_usermeta` for any user who has ever authenticated. On plugin activation (or manual scan), the plugin checks this meta:

- **Has session tokens** → User has logged in before. Their `_wuac_last_login` is set to their registration date as a baseline, so they don't get the 25-point "no login" penalty.
- **No session tokens** → User has genuinely never logged in. `_wuac_last_login` stays empty, and the 25-point penalty applies.

This ensures existing users aren't unfairly penalized when the plugin is installed on a site with an established user base.

## Login Tracking

The login tracker hooks into the `wp_login` action, which fires for **ALL user roles** (administrators, editors, authors, contributors, subscribers, customers, etc.). Every successful login is recorded regardless of role.

## How Factors Combine — Examples

**Example 1: Obvious spam bot**
- Never logged in (+25), disposable email (+30), gibberish username (+15), display name is email (+15), no comments (+5) = **90**

**Example 2: Bot with real email provider**
- Never logged in (+25), suspicious email pattern (+15), bot username pattern (+15), display name = username (+10), no comments (+5) = **70** ← auto-flagged

**Example 3: Legitimate new user**
- Registered today (+5), no comments yet (+5), no orders yet (+5) = **15**

**Example 4: Established customer**
- Has logged in (0), real email (0), real name (0), has comments (0), has orders (0) = **0**

**Example 5: Profile spam bot**
- Never logged in (+25), spam URL in profile (+15), display name has spam (+25), no comments (+5) = **70** ← auto-flagged

## Email Lookup — Wildcard Pattern Search

The Email Lookup tool supports both exact email addresses and wildcard patterns:

| Input | Type | Matches |
|---|---|---|
| `user@example.com` | Exact match | Only that specific email |
| `*.ru` | Pattern | All emails ending in `.ru` |
| `*@yandex.*` | Pattern | All Yandex emails (any TLD) |
| `*casino*@*` | Pattern | Emails with "casino" in the local part |
| `*+*@gmail.com` | Pattern | All plus-addressed Gmail accounts |

Patterns use `*` as a wildcard (converted to SQL `LIKE` with `%`). You can mix exact emails and patterns in the same lookup.

## Technical Details

- Class: `WUAC_Spam_Score` in `includes/class-wuac-spam-score.php`
- All methods are static — no instance needed
- Score is never persisted; always computed fresh
- `get_breakdown()` returns detailed factor-by-factor analysis
- WooCommerce order check uses `SHOW TABLES` to detect HPOS before querying
- Comment check only counts approved comments (`comment_approved = '1'`)
- Disposable domain matching is case-insensitive
- Gibberish detection uses Shannon entropy + vowel-consonant ratio analysis
- Email quality checks: dot count, digit ratio, local part length, special char clusters
- Username pattern matching: 6 regex patterns + keyboard walk dictionary
