# Changelog

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

