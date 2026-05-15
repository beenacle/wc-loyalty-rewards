# Changelog

## 1.1.0
- New: Admin **Analytics dashboard** under Loyalty & Rewards → Analytics.
  - KPI cards: points issued, points redeemed, redemption rate, active members, avg points/order, outstanding balance, liability value (in store currency).
  - Charts: points issued vs redeemed over time (day/week/month), earning by context, members per tier.
  - Date range presets (Last 7 / 30 / 90 days, YTD) and custom range picker.
  - AJAX-powered, capability-gated (`manage_woocommerce`), nonce-verified.
- New: `Analytics_Service` with 5-minute transient caching, auto-invalidated on earn/redeem/admin-adjust hooks.
- New: Chart.js 4.4.4 bundled locally in `assets/vendor/` (no CDN dependency).

## 1.0.7
- Fixed CSS and JS asset versioning to use plugin version instead of hardcoded '1.0.0'
- Assets now properly cache-bust when plugin version is updated
- Fixed missing return statement after wp_send_json_error() in AJAX redemption handler
- Fixed fee name detection mismatch - redemption discount now properly subtracted when calculating earned points

## 1.0.6
- Fixed race condition in points locking mechanism that could cause duplicate ledger entries or incorrect balance calculations
- Replaced non-atomic transient-based lock with atomic `wp_cache_add()` for proper concurrency control

## 1.0.0
- Initial release of Loyalty & Rewards for WooCommerce.
- Dynamic tiers, configurable earning rules, referral bonuses, redemption (manual/auto), import/export.
- My Account tab with balance, lifetime, tier, recent ledger, referral link.
- Admin tools: ledger, adjustments, users list column, coupon exclusions, shortcodes tab.
- Lifetime recalc utility, reward notices, fragment updates for cart/checkout.

