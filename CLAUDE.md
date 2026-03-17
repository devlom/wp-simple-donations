# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WordPress Fundraising plugin — a fork of the archived WPMU DEV plugin (v2.6.4.9), modernized for PHP 8.3+. Crowdfunding and donation plugin supporting simple donations with recurring payments, advanced crowdfunding with goals/rewards, PayPal/Manual/Przelewy24 gateways, multisite support, and Polish localization.

**Text domain:** `wdf` (all translatable strings use `__('...', 'wdf')` / `_e('...', 'wdf')`)

## Development Environment

No build tools, bundlers, or package managers. Plain PHP, jQuery, and CSS. No composer.json or package.json. To develop, place the plugin directory in `wp-content/plugins/` and activate via WordPress admin.

### Translation workflow

PO/MO files in `languages/`. After editing `wdf-pl_PL.po`, recompile with:
```bash
msgfmt -o languages/wdf-pl_PL.mo languages/wdf-pl_PL.po
```

## Architecture

### Core Class & Entry Point

`fundraiser.php` — Contains the main `WDF` class (~2,260 lines), a singleton loaded on `plugins_loaded`. Handles post type registration, rewrite rules, admin meta boxes, payment processing, template routing, and asset enqueueing. This is the central hub of the plugin.

### Custom Post Types

- **`funder`** — Fundraisers (public, with archive at `/fundraisers/`)
- **`donation`** — Pledges/donations (private, admin-only)
- **Custom statuses:** `wdf_approved`, `wdf_complete`, `wdf_canceled`, `wdf_refunded`

### Payment Gateway System

Abstract base: `lib/classes/class.gateway.php` (`WDF_Gateway`). Implementations in `lib/gateways/`:
- `przelewy24.php` — Przelewy24 REST API v1 (primary gateway for Polish payments). Uses Basic Auth (posId:apiKey), SHA-384 signatures with CRC key. Flow: register transaction → redirect to P24 → IPN notification → verify. Stores inter-step data in WP transients (`wdf_p24_{session_id}`).
- `paypal.php` — PayPal Adaptive Payments + IPN
- `manual.php` — Offline/manual processing
- `dotpay.php` — Dotpay (deprecated, kept for historical transaction records only)

Gateways are loaded dynamically. Payment processing fires action hooks: `wdf_gateway_process_{type}_{gateway}`. State is managed via `$_SESSION` variables (`funder_id`, `wdf_pledge`, `wdf_gateway`, `wdf_recurring`, etc.).

### Key Directories

- `lib/` — Core library: data structures, form renderers, template functions, classes
- `lib/widgets/` — 5 WordPress widgets (fundraiser panel, simple donation, recent fundraisers, fundraisers list, pledges panel)
- `lib/gateways/` — Payment gateway implementations
- `lib/classes/` — Base classes (gateway abstract)
- `lib/external/` — Third-party libraries
- `styles/` — Pre-built CSS themes (basic, dark, fresh, minimal, note)
- `js/` — Frontend and admin jQuery scripts (no build step)
- `languages/` — Translations (pl_PL complete, en_US base)

### Template System

Theme-overridable templates with hierarchy:
- Single: `wdf_funder-{ID}.php` → `wdf_funder-{slug}.php` → `wdf_funder.php`
- Checkout: `wdf_checkout-{ID}.php` → `wdf_checkout-{slug}.php` → `wdf_checkout.php`
- Confirmation: `wdf_confirm-{ID}.php` → `wdf_confirm-{slug}.php` → `wdf_confirm.php`

Template functions live in `lib/template-functions.php`. Custom template functions path can be set via `WDF_CUSTOM_TEMPLATE_FUNCTIONS` constant.

### URL Routing

```
/fundraisers/                              — Archive
/fundraisers/{slug}/                       — Single fundraiser
/fundraisers/{slug}/pledge/                — Checkout (query var: funder_checkout)
/fundraisers/{slug}/thank-you/             — Confirmation (query var: funder_confirm)
```

### Capabilities

`wdf_add_fundraisers`, `wdf_manage_all_fundraisers`, `wdf_manage_pledges`, `wdf_edit_settings` — mapped via custom `map_meta_cap()`.

### Settings

Stored in `wp_options` as `wdf_settings`. Defaults defined in `WDF::_vars()`.

## Code Conventions

- PHP 8.3+ required (closures instead of `create_function()`, no `&$this`, `public` property visibility, `session_status()` instead of `session_id()`)
- WordPress coding standards: hooks/filters for extensibility, `wp_enqueue_*` for assets
- Heavy use of `apply_filters()` and `do_action()` for extension points
- jQuery for all frontend JS (WordPress bundled)
- Security: nonce verification on all forms and AJAX, input sanitization at boundaries, output escaping in templates, ABSPATH guards on all PHP files

## Modernization History

- **Phase 0** — Removed BuddyPress integration (no `lib/bp/` directory, no BP-related code)
- **Phase 1** — PHP 8.3+ compatibility
- **Phase 2** — Security hardening (nonces, sanitization, escaping)
- **Phase 3** — Przelewy24 payment gateway
