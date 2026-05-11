=== DesignSetGo Apps ===
Contributors: designsetgo
Tags: ai, sandbox, iframe, app
Requires at least: 6.9
Tested up to: 6.9.4
Requires PHP: 8.2
Stable tag: 0.2.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Drop in any AI-built static bundle and run it as a sandboxed app on your WordPress site, wired to your posts and users.

== Description ==

**Vibe-code an app, drop it on your WordPress site, done.** DesignSetGo Apps is a sandboxed runtime for the static HTML/CSS/JS bundles you build with any AI coding tool that emits a single page or a built static project. Save it, upload it, and it lives at `/apps/your-slug` on your site, fully sandboxed, with a permissioned bridge to your posts, pages, taxonomies, current user, and per-app storage.

The plugin never fetches code from a remote URL. Only users with the `manage_options` capability can install or update apps. Visitors can only run apps the admin has already installed.

= The 60-second flow =

1. Open your AI builder of choice and ask it to build the thing: a calculator, a quiz, a product configurator, a custom landing page, an internal dashboard, or a mini-site.
2. Download the artifact or static bundle.
3. In WP-admin, go to **DSGo Apps** -> **Upload artifact**, drop the `.html` or `.zip`, give it an ID.
4. The plugin wraps it in a manifest, sandboxes it, and hands you a live URL.

That's the whole loop. No build pipeline, no plugin scaffolding, no theme work.

= Free version =

The WordPress.org version runs **one** active app per site. That's enough to cover the most common case: drop in a single AI-built page or widget and call it done. The artifact-upload flow, bridge runtime, sandbox, block embed, and Apps-as-Abilities publishing are all included here.

If you need to run multiple apps or use advanced authoring tools, DesignSetGo offers a separate commercial add-on outside WordPress.org. Details are available at https://designsetgo.dev/pricing/.

= Two isolation modes =

* **Inline (default)** - the app renders directly into a WordPress response. Real, indexable URLs at `/apps/{slug}/{path}`, multi-page support via a `routes` array, optional theme header/footer wrap, and automatic inclusion in the WP sitemap. Isolation is enforced by a strict per-request `Content-Security-Policy` header and HTML sanitization at both install time and render time.
* **Iframe** - the app runs inside a sandboxed `<iframe sandbox="allow-scripts">` with an opaque origin. Single-page and not crawlable, but the safest default for code you did not write yourself.

Both modes use the same bridge wire format. Apps written against the `@designsetgo/app-client` library work in either mode without code changes.

= Two ways to install an app =

* **Drop in a bundle (the default)** - zip up the static build, or just upload the raw `.html` from any AI artifact. The plugin handles the rest.
* **Vibe-code in your IDE, deploy from the terminal** - the optional `@designsetgo/cli` package (`npx designsetgo apps deploy`) authenticates as a `manage_options` admin via a one-time application password, packages the current working directory, and pushes it through the same install endpoint. Lite users remain subject to the 1-app cap.

= What apps can do through the bridge =

With explicit permission grants in the manifest, apps can read site info, posts, pages, taxonomies, the current user, and read/write per-app plus per-user persistent storage. Each app's own storage is isolated from every other app on the site by a per-(user, app) nonce on the storage endpoint, so one app cannot read or overwrite another app's saved data, regardless of mode.

The CLI prints the requested permissions before each install so the admin sees what they are authorizing. Only users with the `manage_options` WordPress capability can install apps.

= Two trust models - read this before installing apps you did not write =

**Iframe-mode** apps run inside `<iframe sandbox="allow-scripts">` with an opaque origin and a strict `Content-Security-Policy` (`connect-src 'none'` by default). The bridge's declared-permission gate is enforced at both the application layer and the network layer, so an iframe-mode bundle cannot issue an undeclared REST call. This is the safe default for code you did not write yourself.

**Inline-mode** apps render as real WordPress pages and share the visiting user's same-origin REST cookies. The bridge's permission gate is the recommended path, but a malicious inline-mode bundle could in principle issue same-origin calls to `/wp-json/wp/v2/...` and inherit the visiting user's WordPress capabilities. **Treat installing an inline-mode app the same as installing any third-party WordPress plugin** and only install bundles whose source you trust. For pasted AI artifacts, AI-generated code from an unknown author, or anything you have not reviewed line-by-line, choose iframe mode at install time.

= AI features built on WordPress 7.0 =

When an app calls `dsgo.ai.prompt()` or invokes an AI-backed ability, the call routes through the WordPress 7.0 AI Client to whichever Connector you configured at **Settings -> Connectors**. **You hold the provider relationship; DSGo never sees the key, stores the key, or charges for inference.** The plugin works on WordPress 6.9 too - apps that use the AI surface degrade gracefully when no Connector is configured.

Apps can also publish abilities the site's AI agent, and any other plugin using the WP 7.0 Abilities API, can invoke. This "Apps-as-Abilities" model turns every installed app into a callable tool the rest of the site's AI surface knows about.

= What's shipped =

* Iframe and inline runtimes
* AI-artifact upload importer for HTML files and static `.zip` bundles
* Manual zip upload for bundles built with a `dsgo-app.json` manifest
* TypeScript bridge client (`@designsetgo/app-client`)
* CLI (`@designsetgo/cli`) with `init`, `login`, `deploy`, `list`
* WP REST endpoints for install, list, uninstall, and bridge proxy
* AI surface through the site's WordPress 7.0 AI Client and Connectors
* Abilities consumption through `dsgo.abilities.list/invoke()`
* Apps-as-Abilities publishing through `abilities.publishes`
* Gutenberg block for embedding installed apps inside posts and pages
* Dynamic routes backed by live data sources (`wp:posts`, `wp:pages`, `wp:cpt:*`, `wc:products`)
* Sitemap provider for inline-mode routes

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate **DesignSetGo Apps** through the **Plugins** screen in WordPress.
3. Visit **DSGo Apps** in the admin menu to install your first bundle. Drop in an AI artifact `.html` or upload a built `.zip`. Or run `npx designsetgo apps deploy` from a project directory.

== Frequently Asked Questions ==

= How many apps can I run on the free version? =

One. The Lite plugin lets you run a single active app per site. Trash the existing app to install a different one.

= What's the difference between the free version and the separate add-on? =

The free version is the runtime: sandbox, bridge, artifact upload, block embed, and abilities publishing, capped at 1 active app per site. DesignSetGo also offers a separate commercial add-on for people who need to lift that cap or use advanced authoring workflows.

= Where do app bundles live on disk? =

Under `wp-content/uploads/designsetgo-apps/{slug}/`. The plugin extracts the zip on install and serves files from there.

= Do I need a paid Anthropic or OpenAI key to use this? =

Only if your apps actually want to call AI. The runtime, bridge, multi-page routing, abilities consumption, and storage all work without any provider key.

When an app does call `dsgo.ai.prompt()` or invokes an AI-backed ability, the call routes through the WordPress 7.0 AI Client to whichever Connector you configured at **Settings -> Connectors**. You hold the provider relationship; DSGo never sees the key, stores the key, or charges for inference. The plugin works on WordPress 6.9 too - apps that use the AI surface degrade gracefully when no Connector is configured.

= Is the source open? =

Yes. The plugin runtime, bridge client, CLI, and bridge protocol are open source under GPL-2.0-or-later.

= What personal data does the plugin store? =

Apps that use `dsgo.user.storage.*` save per-user values into WordPress user metadata, scoped per-app. Apps that use the email bridge cause the plugin to keep a per-app audit log (capped at 200 entries) recording the recipient *type*, the subject line, and a one-way SHA-256 hash of the recipient address, never the address itself.

The plugin integrates with WordPress's built-in privacy tooling: requests made through **Tools -> Export Personal Data** and **Tools -> Erase Personal Data** include DesignSetGo Apps data, and a suggested privacy-policy paragraph is registered via `wp_add_privacy_policy_content` so admins can paste it into their site policy from **Settings -> Privacy -> Policy Guide**.

= Does the plugin call any external services? =

The plugin core does not transmit data to DesignSetGo, and does not phone home for updates, license checks, or analytics.

Apps installed by the admin can, however, make outbound calls to third-party services that the admin has explicitly allowlisted in the manifest:

* `dsgo.http.fetch` - an HTTP proxy for app-to-external-API calls (Stripe, Notion, Airtable, and similar services). The manifest must declare each host, and the admin approves the allowlist at install time. Requests originate from the WordPress server, not from DesignSetGo.
* `dsgo.ai.prompt` and AI-backed abilities - route through the WordPress 7.0 AI Client to whichever Connector you configured at **Settings -> Connectors**. Prompts go directly from your site to whichever provider you chose (Anthropic, OpenAI, Google, and similar services). You hold the API key and the billing relationship.
* The email bridge sends through your site's own `wp_mail()`, so it follows whatever SMTP plugin or transactional-mail integration you already use.

DesignSetGo never receives, proxies, or stores any of these calls.

Site owners are responsible for reviewing the privacy policies and terms of any external provider they configure or allowlist, including AI connector providers, SMTP providers, and third-party APIs reached through `dsgo.http.fetch`.

== Screenshots ==

1. Drop an AI artifact into the WP-admin importer; the app is live in seconds.
2. The DSGo Apps admin screen - install, list, and uninstall apps from a single page.
3. Embed an installed app as a Gutenberg block inside any post or page.

== Upgrade Notice ==

= 0.2.1 =

Lite is now capped at 1 active app per site. Adds a "Cap reached" admin notice. No data migration and no breaking changes.

= 0.1.0 =

Initial public release.

== Changelog ==

= 0.2.1 =

* **Lite cap.** The free plugin now enforces a 1-active-app cap. Re-installing the same slug counts as an update and bypasses the cap; trashed apps do not count. The `dsgo_apps_lite_app_cap` filter lets a companion add-on lift the cap when active.
* **Cap-reached admin notice.** When the apps-list page is at the cap, an info notice explains the limit and points to optional companion features.
* **REST install endpoints** (`apps`, `apps/import-html`, `apps/install-starter`) return the new `lite_cap_reached` error code with HTTP 403 when the cap is hit.
* No changes to the bridge protocol or existing app-side API. Bundles built for 0.1.x continue to run unchanged.

= 0.1.0 =

* Initial release.
* Iframe runtime with sandboxed `<iframe sandbox="allow-scripts">` rendering.
* Inline runtime with multi-page rendering at `/apps/{slug}/{path}`, strict per-request CSP, install-time and render-time HTML sanitization, optional theme header/footer wrap, and sitemap integration.
* TypeScript bridge client with iframe and inline transports auto-selected by `manifest.isolation`.
* CLI (`@designsetgo/cli`) with `init`, `login`, `deploy`, `list` commands and a `--from-artifact` flag for pasted AI artifact bundles.
* REST API for install, list, uninstall, and bridge proxy with per-method permission enforcement.
* AI surface through the user's WordPress 7.0 Connector with no DSGo-held keys.
* Abilities consumption for calling abilities registered by other plugins.
* Apps-as-Abilities publishing through manifest `abilities.publishes`.
* Gutenberg block (`designsetgo-apps/embed`) for embedding installed apps inside posts and pages.
* Dynamic routes with live data sources (`wp:posts`, `wp:pages`, `wp:cpt:*`, `wc:products`).
