=== DesignSetGo Apps ===
Contributors: designsetgo
Tags: apps, sandbox, ai, iframe, bridge
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sandboxed AND connected web apps for WordPress: ship a static bundle, get a real /apps/{slug} URL plus a permissioned bridge to site data.

== Description ==

DesignSetGo Apps is a runtime for shipping web applications inside your WordPress site. The same plugin handles the entire range — drop in a one-page calculator, a multi-section embedded widget, or a full multi-page web app with its own routes, navigation, and SEO presence. Each app is a static bundle (HTML/CSS/JS) plus a `dsgo-app.json` manifest. The plugin installs the bundle, serves it at `/apps/{slug}/...`, and gives the app a permissioned `postMessage` bridge to WordPress data — posts, pages, the current user, per-app storage — without ever handing the app a REST token.

The upper end of the spectrum is real: inline-mode apps render as native WordPress pages with multi-page routes declared in the manifest, strict per-request `Content-Security-Policy` headers, optional theme header/footer wrap so the app inherits site chrome, and automatic inclusion in the WP sitemap. From the visitor's and Google's perspective, the app is just part of your site.

= Two isolation modes =

* **Inline (default)** — the plugin renders the app's HTML directly into a WordPress response. Apps get real, indexable URLs at `/apps/{slug}/{path}`, multi-page support via a `routes` array, optional theme header/footer wrap, and automatic inclusion in the WP sitemap. Isolation is enforced by a strict per-request `Content-Security-Policy` header and HTML sanitization at both install time and render time.
* **Iframe** — the app runs inside a sandboxed `<iframe sandbox="allow-scripts">` with an opaque origin. Single-page, not crawlable, but ideal for drop-in artifacts (for example, a pasted Claude Artifact bundle that hasn't been through a build pipeline).

Both modes use the same bridge wire format. Apps written against the `@designsetgo/app-client` library work in either mode without code changes.

= Two authoring paths =

* **Drop in a bundle** — zip up a static build, install it through the admin UI, get a live URL.
* **Deploy from your terminal** — the `@designsetgo/cli` package (`npx designsetgo apps deploy`) authenticates against the site, packages the current directory, and pushes a new version. Useful for Claude Code / AI-assisted workflows where the app source lives in a normal project and gets iterated on locally.

= What apps can do through the bridge =

With explicit permission grants in the manifest, apps can read site info, posts, pages, taxonomies, the current user, and read/write per-app + per-user persistent storage. The CLI prints the requested permissions before each install so the admin sees what they're authorizing. Only users with the `manage_options` WordPress capability can install apps. Each app's own storage is isolated from every other app on the site by a per-(user, app) nonce on the storage endpoint — one app cannot read or overwrite another app's saved data, regardless of mode.

= Two trust models =

The bridge enforces declared permissions strictly for **iframe-mode** apps: the bundle runs inside `<iframe sandbox="allow-scripts">` (opaque origin) with a strict `Content-Security-Policy` (`connect-src 'none'` by default), so undeclared REST calls are blocked at the network layer.

**Inline-mode** apps render as real WordPress pages and share the page's REST cookies. The bridge's permission gate is the recommended path, but a malicious inline-mode bundle could in principle issue same-origin REST calls (`/wp-json/wp/v2/...`) and inherit the visiting user's WordPress capabilities — admins should treat installing an inline-mode app the same as installing any third-party WordPress plugin and only run code they trust. For untrusted source (pasted Claude Artifacts, AI-generated code from an unknown author), use iframe mode.

= What's shipped in 0.1.0 =

* Iframe and inline runtimes (inline is the default; multi-page routes, theme wrap, sitemap inclusion all here)
* TypeScript bridge client (`@designsetgo/app-client`)
* CLI (`@designsetgo/cli`) with `init`, `login`, `deploy`, `list` — Claude Code / AI-assisted authoring works out of the box
* WP REST endpoints for install, list, uninstall, and bridge proxy
* AI surface — `dsgo.ai.prompt()` calls the site's WordPress 7.0 AI Client + Connectors (no DSGo-held keys, no inference cost to DSGo)
* Abilities consumption — `dsgo.abilities.list/invoke()` lets apps call any ability registered by another plugin (Yoast SEO Premium, etc.)
* Apps-as-Abilities — apps publish their own abilities via `abilities.publishes` for the site's AI agent and other plugins to invoke
* Gutenberg block for embedding installed apps inside posts and pages
* Sitemap provider for inline-mode routes
* PHPUnit + Vitest + Playwright test suites

= What's coming =

* Dynamic routes (`/apps/{slug}/customers/:id`) with build-time dataset prerendering

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate **DesignSetGo Apps** through the **Plugins** screen in WordPress.
3. Visit **DSGo Apps** in the admin menu to install your first bundle, or run `npx designsetgo apps deploy` from a project directory.

== Frequently Asked Questions ==

= Where do app bundles live on disk? =

Under `wp-content/uploads/dsgo-apps/{slug}/`. The plugin extracts the zip on install and serves files from there.

= Do I need a paid Anthropic / OpenAI key to use this? =

Only if your apps actually want to call AI. The runtime, bridge, multi-page routing, abilities consumption, and storage all work without any provider key.

When an app does call `dsgo.ai.prompt()` or invokes an AI-backed ability, the call routes through the WordPress 7.0 AI Client to whichever Connector you configured at **Settings → Connectors**. You hold the provider relationship; DSGo never sees the key, stores the key, or charges for inference. The plugin works on WordPress 6.9 too — apps that use the AI surface degrade gracefully when no Connector is configured.

= Is the source open? =

Yes. Plugin runtime, bridge client, CLI, and bridge protocol are all open source under GPL-2.0-or-later.

== Screenshots ==

1. Apps admin screen — install, list, and uninstall apps from a single page.
2. App settings — choose a root app, configure the URL prefix, opt into theme wrap.
3. A live inline-mode app rendered at `/apps/example/`.

== Upgrade Notice ==

= 0.1.0 =
Initial public release.

== Changelog ==

= 0.1.0 =
* Initial release.
* Iframe runtime with sandboxed `<iframe sandbox="allow-scripts">` rendering.
* Inline runtime (default): multi-page rendering at `/apps/{slug}/{path}`, strict per-request CSP, install-time + render-time HTML sanitization, optional theme header/footer wrap, sitemap integration.
* TypeScript bridge client with iframe and inline transports auto-selected by `manifest.isolation`.
* CLI (`@designsetgo/cli`) with `init`, `login`, `deploy`, `list` commands; `--from-artifact` flag for pasted Claude Artifact bundles.
* REST API for install, list, uninstall, and bridge proxy with per-method permission enforcement.
* AI surface — `dsgo.ai.prompt()` routes through the user's WordPress 7.0 Connector; no DSGo-held keys.
* Abilities consumption — `dsgo.abilities.list/invoke()` for calling abilities registered by other plugins.
* Apps-as-Abilities — manifest `abilities.publishes` registers app-provided abilities the site's AI agent can invoke.
* Gutenberg block (`dsgo-apps/embed`) for embedding installed apps inside posts and pages.
