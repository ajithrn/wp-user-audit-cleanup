# Technical Details

## Spam Score — How It Works

The spam score is a number between 0 and 100 that estimates how likely a user account is spam. It is calculated on-the-fly each time the Users list is loaded — nothing is stored in the database.

The score is the sum of individual weighted factors. If the total exceeds 100, it is capped at 100.

## Scoring Factors

| Factor | Weight | Condition | Rationale |
|---|---|---|---|
| No login recorded | 30 | `_wuac_last_login` user meta is empty | Users who have never logged in are more likely to be bot-created accounts. On activation, the plugin backfills this using WordPress session tokens so legitimate existing users aren't penalized. |
| Recent registration | 10 | Registered within the last 24 hours | Freshly created accounts haven't had time to prove legitimacy. This factor naturally expires after 24 hours. |
| Disposable email domain | 30 | Email domain matches the bundled/custom disposable domain list | Disposable email services are heavily used by spammers to create throwaway accounts. The plugin ships with 200+ known domains and allows admins to add/remove entries. |
| Digit-heavy username | 10 | Username contains 5 or more consecutive digits | Automated account creation tools often generate usernames like `user38291` or `john12345678`. Legitimate users rarely have long digit sequences. |
| Display name is email | 20 | `display_name` exactly matches `user_email` | WordPress sets the display name to the email by default during registration. Real users typically update this; bots don't bother. |
| No approved comments | 10 | Zero approved comments in `wp_comments` | A user who registered but never commented shows no engagement with the site. Combined with other factors, this strengthens the spam signal. |
| No WooCommerce orders | 10 | Zero orders in WooCommerce (only when WooCommerce is active) | On a store, a registered user with no purchase history may be a bot. This check is skipped entirely if WooCommerce is not installed. Supports both HPOS (`wc_orders` table) and legacy post-based storage. |

## Maximum Possible Score

| Scenario | Max Score |
|---|---|
| Without WooCommerce | 110 → capped to **100** |
| With WooCommerce | 120 → capped to **100** |

Since the score is capped at 100, not every factor needs to apply for a user to reach the maximum.

## Risk Levels

The plugin uses three risk levels for display in the Users list:

| Level | Score Range | Badge Color |
|---|---|---|
| Low | 0–39 | Green |
| Medium | 40–69 | Yellow |
| High | 70–100 | Red |

## Auto-Flagging Threshold

The user scanner (runs on activation or manually from Settings) auto-flags users with a score of **70 or higher** as spam. The current admin is never auto-flagged.

## Session Token Backfill

WordPress stores `session_tokens` in `wp_usermeta` for any user who has ever authenticated. On plugin activation (or manual scan), the plugin checks this meta:

- **Has session tokens** → User has logged in before. Their `_wuac_last_login` is set to their registration date as a baseline, so they don't get the 30-point "no login" penalty.
- **No session tokens** → User has genuinely never logged in. `_wuac_last_login` stays empty, and the 30-point penalty applies.

This ensures existing users aren't unfairly penalized when the plugin is installed on a site with an established user base.

## How Factors Combine — Examples

**Example 1: Obvious spam bot**
- Never logged in (+30), disposable email (+30), display name is email (+20), no comments (+10) = **90**

**Example 2: Legitimate new user**
- Registered today (+10), no comments yet (+10), no orders yet (+10) = **30**

**Example 3: Established customer**
- Has logged in (0), real email (0), has comments (0), has orders (0) = **0**

**Example 4: Suspicious but not definitive**
- Never logged in (+30), digit username (+10), no comments (+10) = **50**

## Technical Details

- Class: `WUAC_Spam_Score` in `includes/class-wuac-spam-score.php`
- All methods are static — no instance needed
- Score is never persisted; always computed fresh
- WooCommerce order check uses `SHOW TABLES` to detect HPOS before querying
- Comment check only counts approved comments (`comment_approved = '1'`)
- Disposable domain matching is case-insensitive
- Digit sequence check uses regex: `/\d{5,}/`
