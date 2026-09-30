=== 152FZ Cookie & Consent Guard ===
Contributors: wp-panda
Tags: privacy, cookie consent, 152-fz, gdpr, analytics
Requires at least: 6.2
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later

Opt-in cookie categories, tracker gating, form consent and consent audit log.

== Installation ==

1. Zip the 152fz-cookie-consent directory and upload it via Plugins → Add New → Upload Plugin, then activate.
2. Set a Privacy Policy page in Settings → Privacy and review its legal text with counsel.
3. Go to Settings → 152ФЗ Cookies. Set the banner and category descriptions, fill the cookie register and configure form integrations. Test as a logged-out visitor in a clean browser profile.
4. Run the HTML scanner on the homepage and representative published pages. Audit browser Network/Application panels for cookies, localStorage and dynamic requests before and after consent. Purge all HTML/page/CDN caches after changes.

== Features ==

* Banner, optional overlay, category dialog, search and floating settings button; optional categories default off, necessary always on. Visitors can withdraw via the dialog or [rcc_revoke_button].
* Server-side HTML output-buffer gating of recognised script URLs and embeddable iframes, plus best-effort dynamic script/iframe gating. Inline scripts may be gated with a script tag containing rcc-category="analytics" or via rcc_enqueue_script($handle, $src, $category). Custom rules: category|literal-substring per line. Hooks: rcc_tracker_rules, rcc_before_banner, rcc_consent_updated, rcc_sanitized_options.
* Consent ID, UTC timestamp, HMAC-hashed server-observed IP, User-Agent, category choices, action, URL and logged-in user ID. 6/12/24 month cron retention, filtered admin log and CSV export. WordPress cron depends on traffic or a real cron runner. Deleting the plugin deletes its logs and settings.
* Form checkbox shortcode [rcc_consent_checkbox], automatic HTML form checkbox and server-side validation hooks for supported form plugins. Excludes WooCommerce cart, checkout, payment and account forms. Shortcodes [rcc_settings], [rcc_accept_button], [rcc_cookie_policy].
* Import/export JSON for settings. WooCommerce functional cart and checkout cookies are not blocked by the built-in rules.

== Important limitations ==

This plugin alone does NOT certify 152-FZ compliance. Legal basis, operator notices, Russian data localisation, consent wording, records and third-party contracts must be assessed separately. The scanner only inspects server HTML and Set-Cookie headers, not browser-executed storage or external pages. It cannot stop server-side Set-Cookie headers, direct document.write, browser extensions, every dynamically created tracker, or remove already-set third-party cookies after withdrawal. Third-party plugins may require their own opt-in configuration and form field placement; validate each AJAX form against its installed version. Checkout Blocks and payment gateways are intentionally not modified. For strict guarantees use a reviewed Content Security Policy and audit actual requests. If a full-page cache removes wp_head/wp_footer or serves stale nonces, exclude consent endpoints from caching and purge the cache.
