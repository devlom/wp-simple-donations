# Fundraising WordPress Plugin

A crowdfunding and donation plugin for WordPress. Create, host, and manage fundraisers — from simple donations with recurring payments to advanced crowdfunding with goals and reward packages.

> **Fork notice:** Originally developed by WPMU DEV (v2.6.4.9, unsupported/archived). This fork modernizes the codebase for PHP 8.3+, adds security hardening, removes the BuddyPress dependency, and integrates a Przelewy24 payment gateway.

## Features

- **Simple Donations** — Continuous donations with no end date, great for non-profits
- **Advanced Crowdfunding** — Authorize pledges and process payments after reaching a goal (Kickstarter-style)
- **Recurring Payments** — Weekly, monthly, quarterly, or yearly
- **Reward Packages** — Set support levels with limited availability rewards
- **Start & End Dates** — Schedule campaigns to create urgency
- **Progress Bar** — Visual funding progress tracking
- **Automated Emails** — Thank you messages and confirmation emails
- **Display Styles** — 5 built-in CSS themes (Basic, Dark, Fresh, Minimal, Note) or custom
- **Shortcode Generator** — Insert fundraising features into any post/page
- **5 Widgets** — Fundraiser panel, simple donation, recent fundraisers, fundraisers list, pledges panel
- **Theme-Overridable Templates** — Full template hierarchy for single, checkout, and confirmation pages
- **User Access Control** — Granular capabilities for team management
- **Multisite Support** — Works with WordPress Multisite
- **Polish Translation** — Complete pl_PL localization

## Payment Gateways

| Gateway | Description |
|---------|-------------|
| **Przelewy24** | Polish payment gateway — REST API v1, sandbox & live modes |
| **PayPal** | Adaptive Payments with IPN |
| **Manual** | Offline/manual payment processing |

## Requirements

- WordPress 5.0+
- PHP 8.3+

## Installation

1. Download or clone this repository into `wp-content/plugins/fundraising/`
2. Activate the plugin via **Plugins** in the WordPress admin
3. Configure settings under **Fundraising > Settings**
4. Configure at least one payment gateway
5. Create your first fundraiser under **Fundraising > Add New**

## URL Structure

```
/fundraisers/                    — Archive (all fundraisers)
/fundraisers/{slug}/             — Single fundraiser
/fundraisers/{slug}/pledge/      — Checkout page
/fundraisers/{slug}/thank-you/   — Confirmation page
```

The base slug (`fundraisers`) is configurable in plugin settings.

## Template Overrides

Place templates in your theme directory to override defaults:

- `wdf_funder-{ID}.php` / `wdf_funder-{slug}.php` / `wdf_funder.php` — Single fundraiser
- `wdf_checkout-{ID}.php` / `wdf_checkout-{slug}.php` / `wdf_checkout.php` — Checkout
- `wdf_confirm-{ID}.php` / `wdf_confirm-{slug}.php` / `wdf_confirm.php` — Confirmation

Custom template functions can be loaded via the `WDF_CUSTOM_TEMPLATE_FUNCTIONS` constant.

## Modernization Changelog

- **Phase 0** — Removed BuddyPress integration (unused dependency)
- **Phase 1** — PHP 8.3+ compatibility (`create_function()` → closures, `&$this` removal, property visibility)
- **Phase 2** — Security hardening (nonce verification, input sanitization, output escaping)
- **Phase 3** — Przelewy24 payment gateway integration

## License

GPL-2.0-or-later
