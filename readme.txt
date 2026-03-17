=== WP Simple Donations ===
Contributors: hexplor, devlom
Donate link: https://wpsimpledonations.com/
Tags: donations, fundraising, crowdfunding, paypal, payu, przelewy24
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 8.3
Stable tag: 2.6.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fundraising and donation plugin for WordPress. Accept donations via PayPal, PayU, and Przelewy24 with goals, rewards, and progress tracking.

== Description ==

WP Simple Donations is a free, open-source fundraising and donation plugin for WordPress. Create campaigns, accept one-time donations, set funding goals with progress bars, and offer reward tiers — all with a modern, responsive design that adapts to your theme.

= Payment Gateways =

* **PayPal** — REST API Orders v2 with JS SDK. In-page popup checkout (no redirect). Configure with Client ID + Secret.
* **PayU** — REST API v2.1 with OAuth2. Most popular payment gateway in Poland. Redirect-based checkout with webhook notifications.
* **Przelewy24** — REST API v1 with SHA-384 signatures. Second largest gateway in Poland. Redirect-based checkout.
* **Manual** — Offline/bank transfer payments. Confirm manually in admin panel.

= Key Features =

* **Simple donations** — one-time payments with no end date, perfect for nonprofits
* **Funding goals** — set a target amount with a visual progress bar
* **Reward tiers** — support levels with limited availability (Kickstarter-style)
* **Campaign scheduling** — start and end dates with countdown
* **Modern CSS** — responsive card-based design with CSS custom properties for easy theming
* **Shortcodes** — `[fundraiser_panel]`, `[pledges_panel]`, `[donate_button]`, `[progress_bar]`
* **5 Widgets** — fundraiser panel, simple donation, recent fundraisers, fundraisers list, pledges panel
* **Theme templates** — full template hierarchy for single, checkout, and confirmation pages
* **Email notifications** — automatic confirmations and thank-you messages
* **Multisite support** — works with WordPress Multisite
* **Polish translation** — complete pl_PL localization included
* **User access control** — granular capabilities for team management
* **Sample data** — creates an example campaign on first activation

= Dedicated Gateway Buttons =

Each payment gateway gets its own branded button on the donation form — no confusing radio buttons. PayPal renders its gold SDK button, Przelewy24 shows a red branded button with logo, PayU gets a green button. Clean and intuitive for donors.

= Easy Theming =

Override CSS custom properties to match your brand without writing a full stylesheet:

`
.wdf-default {
    --wdf-accent: #e11d48;
    --wdf-radius: 12px;
    --wdf-success: #059669;
}
`

= External Services =

This plugin connects to third-party payment services to process donations:

* **PayPal** (paypal.com) — Payment processing via REST API. [Privacy Policy](https://www.paypal.com/webapps/mpp/ua/privacy-full). The PayPal JS SDK is loaded from `paypal.com/sdk/js` on pages with active donation forms.
* **PayU** (payu.com) — Payment processing via REST API. [Privacy Policy](https://www.payu.com/en/privacy-policy/). Data sent: order amount, donor name/email, transaction ID.
* **Przelewy24** (przelewy24.pl) — Payment processing via REST API. [Privacy Policy](https://www.przelewy24.pl/en/privacy-policy). Data sent: order amount, donor email, transaction ID.

No data is sent to any service until a donor initiates a payment.

== Installation ==

1. Download the plugin ZIP from [GitHub Releases](https://github.com/devlom/wp-simple-donations/releases/latest) or install from the WordPress plugin directory.
2. Upload via **Plugins > Add New > Upload Plugin** in WordPress admin.
3. Activate the plugin.
4. Go to **Donations > Settings** and enable at least one payment gateway.
5. Enter your API keys for the selected gateway (Client ID + Secret for PayPal, POS ID + keys for P24/PayU).
6. Create your first campaign under **Donations > Add New**.

On first activation, the plugin creates a sample campaign with test pledges so you can see how everything looks.

== Frequently Asked Questions ==

= What PHP version is required? =

PHP 8.3 or higher. The plugin uses modern PHP features like closures and typed properties.

= Can I use multiple gateways at once? =

Yes. Enable as many gateways as you want — each gets its own dedicated button on the donation form.

= Does it work with block themes (Twenty Twenty-Four, etc.)? =

Yes. The plugin uses its own responsive CSS that works with any theme, classic or block-based.

= Can I customize the look? =

Yes. Override CSS custom properties (`--wdf-accent`, `--wdf-radius`, `--wdf-success`, etc.) or add custom CSS in **Donations > Settings > Presentation**. You can also create custom style files in `wp-content/wdf-styles/`.

= Is it translatable? =

Yes. Complete Polish (pl_PL) translation is included. Other languages can be added via PO/MO files in the `languages/` directory.

= Where do I report bugs? =

On GitHub: [github.com/devlom/wp-simple-donations/issues](https://github.com/devlom/wp-simple-donations/issues)

== Screenshots ==

1. Donation form with PayPal and Przelewy24 buttons
2. Campaign panel with stats, progress bar, and form
3. Admin settings — payment gateway configuration
4. Confirmation page after successful donation

== Changelog ==

= 2.6.6 =
* New: PayU payment gateway (REST API v2.1, OAuth2, webhook notifications)
* New: PayPal rewritten to modern REST API Orders v2 + JS SDK (popup checkout)
* New: Dedicated branded buttons per payment gateway
* New: Modern responsive CSS style with custom properties
* New: One-step checkout flow (no redundant gateway form)
* New: Payment flow validation with clear error messages
* New: Admin notice when no gateway is active
* New: Sample data seeder on first activation
* Removed: Dotpay gateway (service discontinued)
* Removed: PayPal Adaptive Payments and IPN (deprecated by PayPal)
* Removed: 5 legacy CSS themes (basic, dark, fresh, minimal, note)
* Security: Fixed SQL injection in pledge ID generation
* Security: Added CSRF protection on settings save
* Security: Sanitized all gateway transaction fields
* Security: Fixed nonce handling in multiple locations
* Fix: Broken HTML label attributes in manual gateway
* Fix: Email validation using WordPress is_email()

= 2.6.5 =
* Rebranded from WPMU DEV Fundraising to WP Simple Donations
* Updated all user-facing strings and translation files
* Added Przelewy24 payment gateway
* PHP 8.3+ compatibility
* Security hardening (nonces, sanitization, escaping)
* Removed BuddyPress integration

== Upgrade Notice ==

= 2.6.6 =
Major update: new PayU gateway, rewritten PayPal (popup checkout), modern CSS, security fixes. Old CSS themes and Dotpay gateway removed — settings migrated automatically.
