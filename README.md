# Loyalty & Rewards for WooCommerce

Reusable loyalty and rewards plugin for WooCommerce. Fully OOP, namespaced, PHP 8.0+, WordPress 6+, WooCommerce 8+. Features dynamic tiers, configurable earning rules, referral program, redemption, admin tools, import/export, and shortcodes.

## Requirements
- PHP 8.0+
- WordPress 6.0+
- WooCommerce 8.0+

## Installation
1) Upload `wc-loyalty-rewards` to `wp-content/plugins/`.
2) Activate in **Plugins**.
3) Ensure WooCommerce is active.
4) Configure under **Loyalty & Rewards** (top-level menu).

## Key Features
- Configurable earning triggers: orders, signup, referral (first order), login activity, anniversary.
- Dynamic tiers (DB-backed) with multipliers, CRUD and reordering.
- Redemption with manual/auto modes, coupon exclusions, refund handling.
- Referral codes and first-order rewards; My Account shows code/link and recent referrals.
- Cart/checkout widgets with auto-redeem and estimated points; My Account endpoint with balance, lifetime, tier, recent ledger, referral info.
- Admin tools: global ledger, per-user adjustments, import/export CSV, users list column, lifetime recalc.
- Hooks and filters for extensibility; translation-ready.

## Data Storage
- User meta: `_wclr_points_balance`, `_wclr_lifetime_points`, flags for triggers.
- Tables (created with dbDelta):
  - `wclr_points_ledger`: transactions (earn/spend/adjustment) with context, order/admin refs.
  - `wclr_referrals`: referral tracking (referrer, referred, status, first order).
  - `wclr_tiers`: dynamic tier definitions.

## Settings Overview (Loyalty & Rewards → Settings)
- General: enable program, uninstall data, base rate, base multiplier.
- Earning: orders (tax/shipping/min/refund behavior/coupon exclusions), signup, referral bonuses, login (X logins/week), anniversary.
- Redemption: enable, rate, max %, auto modes (max/percent), refund return, manual input toggle, coupon exclusions.
- Display: My Account tab, cart, checkout widgets.
- Shortcodes: listed in Utilities tab.

## Shortcodes
- `[wclr_points_balance]`
- `[wclr_tier_info]`
- `[wclr_referral_block]`
- `[wclr_recent_ledger limit="10"]`
- `[wclr_redeem_widget]`

## Admin Tools
- Points Ledger: view latest entries; import/export CSV; **Recalculate Lifetime Points** button rebuilds `_wclr_lifetime_points` from positive `earn` ledger rows.
- Users list column: shows balance and tier; sortable.
- User profile section: view balance/lifetime/tier, recent ledger, manual adjustments.

## Hooks (examples)
- Actions: `wc_loyalty_rewards_before_earn_points`, `wc_loyalty_rewards_after_earn_points`, `wc_loyalty_rewards_before_redeem_points`, `wc_loyalty_rewards_after_redeem_points`, `wc_loyalty_rewards_user_tier_changed`, `wc_loyalty_rewards_after_admin_adjustment`.
- Filters: `wc_loyalty_rewards_earn_rate`, `wc_loyalty_rewards_referral_bonus`, `wc_loyalty_rewards_signup_bonus`, `wc_loyalty_rewards_anniversary_bonus`, `wc_loyalty_rewards_login_rule`, `wc_loyalty_rewards_redemption_config`, `wc_loyalty_rewards_cart_points_preview`.

## Import / Export
- Export: CSV with `user_id,user_email,points_balance,lifetime_points`.
- Import: same columns; `lifetime_points` defaults to `points_balance` if omitted. Imports replace current balances/lifetime.

## Uninstall
- If “Delete data on uninstall” is checked, custom tables and plugin options/meta are removed on uninstall.

## Development
- Namespaced, no external frameworks.
- Text domain: `wc-loyalty-rewards`, domain path: `/languages`.
- Generate POT: `wp i18n make-pot . languages/wc-loyalty-rewards.pot`.


