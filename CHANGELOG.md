# Changelog

## 1.1.3
Refund accounting, redemption safety, packaging, and block-checkout fixes.
- Fixed: **Auto-update was silently disabled in released builds.** The GitHub update checker requires `vendor/`, but the release workflow never ran `composer install`, so distributed ZIPs shipped without it. CI now installs production dependencies (and a committed `composer.lock` pins them) before packaging.
- Fixed: **"Prorate on partial refund" did nothing, and partial refunds never adjusted points.** Reversal only ran on a full status change to `refunded`/`cancelled`. Partial (and full) refunds now reconcile earned points to the refunded share of the order via `woocommerce_order_refunded` when `refund_behavior = prorate`; repeated partial refunds each claw back only the incremental amount.
- Changed: **Refund/cancel reversals now reduce lifetime points (and therefore tier eligibility).** Previously lifetime never decreased, so a fully refunded order could keep a customer's tier multiplier inflated forever. `Recalculate Lifetime Points` now nets out refund reversals to match. (Admin/manual adjustments and redemption-refund restorations remain excluded from lifetime.)
- Fixed: **Point redemption could exceed the order's value.** The discount is now hard-capped at the cart total after coupons (in addition to the optional `max_percent` cap), so the order total can no longer be driven negative when `max_percent = 0`.
- Fixed: **Referral attribution was lost on the block/Store API checkout.** The `?ref=` cookie is now also captured through `woocommerce_store_api_checkout_update_order_from_request`, not just the legacy classic-checkout hook.
- Fixed: **CSV export formula/injection.** Exported cells beginning with `=`, `+`, `-`, `@`, TAB, or CR are now prefixed so spreadsheet apps treat them as text.
- Fixed: **CSV import dropped the first record when the file had no header row** (the data line was consumed as the header).
- Fixed: **Uninstall left orphaned user meta** (`_wclr_anniversary_last_ordinal`, `_wclr_referral_rewarded`) when "Delete data on uninstall" was enabled.
- Misc: removed a deprecated `current_time('timestamp')` call in the flash-multiplier window check (now timezone-correct) and corrected a misleading lock-timeout comment.

## 1.1.2
Follow-up hardening of secondary earning paths.
- Fixed: **Self-referral via a second account.** Referral rewards are now blocked when the referrer and the referred customer are the same person, matched on normalized account/billing email (Gmail dot/`+alias` variants are collapsed), in addition to the existing same-user-id check — at both signup and reward time.
- Fixed: **Referral attribution lost under HPOS.** The `?ref=` checkout cookie wrote the referrer to legacy post meta but it was read back via the order object, so cookie-based attribution never resolved. It is now written through the order object (HPOS-correct).
- Fixed: **Birthday bonus could be claimed with no real birthday.** The birthday parser no longer accepts relative/textual values such as `today`/`now` (which `strtotime()` resolved to the cron-run day); only strict numeric dates are honored.
- Fixed: **Admin/manual point adjustments no longer count toward lifetime points** (and therefore tiers). Lifetime now reflects earned points only, consistent with the "Recalculate Lifetime Points" utility. Run that utility once if you want existing balances trued up.

## 1.1.1
Security/integrity release — closes several points-leak loopholes.
- Fixed: **Anniversary bonus could be paid twice for one membership year.** Deduplication now tracks the membership-anniversary ordinal (full years since registration) instead of the calendar year, and never pays an anniversary for an account younger than one year (defence in depth even if the cron date-guard is bypassed). Leap-year (Feb 29 → Feb 28) handling preserved.
- Fixed: **Redeemed points were not always deducted.** The redemption discount is now persisted to the order at checkout (`_wclr_pending_redeem_points`) and the spend is finalized from server-side, idempotent hooks (`woocommerce_payment_complete`, status → paid) instead of relying solely on the session-dependent `woocommerce_thankyou` page. Prevents customers keeping both the discount and the points on off-site/async-gateway checkouts.
- Fixed: **Earned points were never reversed on refund/cancel.** `order_earning.refund_behavior` and `redemption.return_on_refund` now actually take effect: earned points are reversed and redeemed points restored when an order moves to `cancelled`/`refunded` (idempotent, and excluded from lifetime/tier totals).
- Fixed: **Order points could be double-awarded** under concurrent status-change events (bulk edits, gateway IPN retries, integrations). The earn guard is now re-checked atomically under a per-order lock.
- Fixed: **Balance lost-updates / duplicate awards** when no persistent object cache is present. `add_points()` now serializes the balance read-modify-write with a cross-process MySQL named lock (`GET_LOCK`) in addition to the object-cache lock.
- Fixed: **Signup bonus could be re-claimed by deleting and re-registering an account.** Added a durable, email-hash signup guard recorded in the ledger that survives account deletion.

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

