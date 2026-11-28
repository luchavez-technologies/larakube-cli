# LaraKube Cloud: a Play Store-style app store

**Status:** Planned, not started (2026-11-28). Design: Cloud Figma file, section "App store · App page" (desktop 1280 and phone 390, node `12:20`).

## Why
Apps are only visible inside a server's Apps tab and as a logo wall on the landing page. A non-developer should be able to browse apps before signing up, open a page that explains one in plain words, see what it looks like, read what others thought, and press Install. Guests are asked to create an account or sign in, then land back on the same app.

## What exists to build on (Cloud)
- `apps` (`CatalogApp`): slug, name, tagline, description, category, publisher, tags, `promoted_order`, `new_until`, `installable`, `default_tier`.
- `publishers` (official, or a team), `app_releases` (+ `release_requirements` for CPU, memory and engines), signed releases.
- Installs: `server_apps`, `POST servers/{server}/apps` (`ServerAppController::store`) through the runner.
- Public: `welcome.tsx` (logo wall, phone demo), `/api/v1/apps/promoted`, `ToolLogo`.
- People model from `community-apps-store.md` (on hold): publisher = a team, accepted maintainers, contributors from GitHub. It plugs in later and is not needed for official apps.

## Pages (public, no sign-in needed)
1. **`/apps` (browse):**
   - search;
   - category chips;
   - sections: Featured (`promoted_order`), New (`new_until`), Popular (installs), then by category;
   - cards with icon, name, "instead of …" line, rating and a Get button.
2. **`/apps/{slug}` (an app's page), as in the Figma design:**
   - **Header:** icon, name, tagline (what it does, in plain words), who made it (the upstream author, e.g. "n8n GmbH") and who lists it ("Listed by LaraKube ✓"), tags, and **Install on my server**.
   - **Stats strip:** rating and number of reviews, installs (rounded: "1K+"), memory needed, version.
   - **Screenshots & video:** a scrolling strip. A YouTube video shows as a still image with a play button and only loads YouTube when pressed (`youtube-nocookie.com`, no tracking before the click).
   - **About:** a longer description (Markdown) and "replaces …" bullets.
   - **Details:** made by, license, source code link, needs (from the latest release's requirements), updated.
   - **Ratings & reviews:** average, a 1–5 breakdown, then reviews.
   - **Similar apps.**
   - **Phone:** an install bar sticks to the bottom.
3. **SEO and sharing:** see the SEO section; the store is meant to bring in search traffic.

## SEO (a goal of the store, not an extra)
Today Cloud renders in the browser only (`inertia({ ssr: false })`), so the first HTML has just the generic `<title>` from `app.blade.php`: no description, no Open Graph, no structured data. `robots.txt` allows everything and no environment sends `noindex`, so staging can be indexed as a duplicate of production. There is no sitemap.
- **Indexing per environment:** only production may be indexed. Every other environment (staging, local) sends `X-Robots-Tag: noindex, nofollow` and a `Disallow: /` robots.txt. A config flag (`SEARCH_INDEXING=true` only in production's settings) decides. Do this first; it protects production's ranking now.
- **Meta in the first HTML, without turning on SSR:** public pages pass their meta to the Blade root through `Inertia::render(...)->withViewData(['meta' => …])`, and `app.blade.php` prints it:
  - per-page `<title>` and `<meta name="description">`;
  - `<link rel="canonical">` on the production host;
  - Open Graph and Twitter tags.

  Crawlers and link previews get them without JavaScript.
- **Open Graph images:** one per app (icon, name, tagline on the brand background), generated once and stored, refreshed when the listing changes.
- **Structured data:** JSON-LD in the first HTML.
  - Each app page gets a `SoftwareApplication` with name, description, `applicationCategory`, `operatingSystem: "Linux server"`, `offers` (price 0, or the real price later), `aggregateRating` only when there are real reviews, and `author` for the upstream maker.
  - Breadcrumbs go on every public page.
- **`sitemap.xml`:** generated from the catalog: `/`, `/apps`, each category, each app page, each alternatives page, with `lastmod` from the listing. Referenced from robots.txt on production.
- **Pages people search for:**
  - `/apps/category/{category}` (e.g. "self-hosted automation");
  - `/alternatives/{product}` (e.g. "open-source Zapier alternative") from the existing "instead of …" data, each listing the apps that replace it.

  Each has its own copy, not just a list.
- **Speed (a ranking factor):** public pages stay light. Images are WebP with sizes, below-the-fold screenshots lazy-load, the YouTube player loads only when pressed, fonts are preloaded.
- **Server-side rendering: the user turns it on later.** Build the public pages so they work unchanged when it is on:
  - no `window`/`document`/`localStorage` at module level or during render (only in effects);
  - no browser-only libraries imported at the top of a public page;
  - dates and numbers formatted the same way on server and client.

  The meta in the first HTML (above) stays as the fallback either way.
- **Check:** view-source shows the title, description, canonical, OG and JSON-LD for an app page. Google's Rich Results Test passes. Staging returns `noindex`. `sitemap.xml` lists every published app.

## Install, including for guests
- **Install** for a guest goes to sign-up/sign-in with the app as the intended destination, then back to the app page with the install sheet open.
- **The install sheet:**
  1. pick the server (or "Create your first server", which comes back here afterwards);
  2. the app's options (admin email, address) as today;
  3. confirm.

  It reuses `ServerAppController::store` and the runner unchanged.
- Already installed on one of your servers: the button becomes "Open" (its address) plus "Installed on my-server".

## Ratings and reviews
- **Who:** only someone whose team has installed the app (a row in `server_apps`) can rate it, shown as "Verified install". This keeps out drive-by spam.
- **What:** one review per person per app, 1–5 stars, optional text, editable. The publisher can reply once per review.
- **Moderation:** anyone signed in can report a review. Reports and hidden reviews go to a Filament queue for staff (`PlatformRole`). Nothing is auto-deleted.
- **Aggregates:** average, count and per-star counts are cached on the app and updated when a review changes.
- "Comments/feedback" are the reviews (Play Store style). Threaded discussion or Q&A is out of scope for v1.

## Data (new)
- **On `apps`:**
  - `long_description` (Markdown);
  - `upstream_name`, `upstream_url`, `source_url`, `license`, `docs_url`, `website_url`;
  - `rating_average`, `rating_count`, `rating_breakdown` (json);
  - `install_count` (cached).
- **`app_media`:** app_id, type (`screenshot` | `youtube`), path or YouTube video ID (stored as the ID only, never a full URL), caption, position, `device` (`desktop` | `phone`, so the phone page shows phone screenshots first).
- **`app_reviews`:** app_id, user_id, team_id, rating (1–5), body, status (`published` | `hidden`), publisher_reply, replied_at, timestamps. Unique `(app_id, user_id)`.
- **`app_review_reports`:** review_id, user_id, reason, resolved_at, resolved_by.
- **Screenshots** live in Cloud's object storage (the S3 bucket the app already uses), uploaded in Filament, served from the public storage address. Community apps will later show images from the publisher's repo instead (per `community-apps-store.md`).

## Admin (Filament)
- Edit an app's listing: long description, upstream author and links, license, screenshots (upload, reorder, caption, desktop/phone), YouTube video ID, featured order.
- The review moderation queue.

## Shared with the LaraKube CLI and Desktop
- `GET /api/v1/apps/{slug}`: the listing as JSON (texts, media URLs, rating, requirements). Cloud is the source (same rule as templates and provider catalogs), so Desktop's Apps page can show the same page later.

## Phases
0. **Indexing guard (small, first):** `noindex` and a closed robots.txt everywhere except production.
1. **Browse and app pages from existing data:** `/apps`, `/apps/{slug}`, category pages (public), header, about, details, requirements, similar apps; meta, canonical, JSON-LD and sitemap. Phone-checked.
2. **Media and listing content:** the `app_media` table, the new `apps` columns, Filament editing, screenshots and the click-to-load video.
3. **Install from the page:** guest sign-up/sign-in returning to the app, the install sheet, the Open/Installed states.
4. **Ratings and reviews:** tables, verified-install rule, write/edit, publisher reply, report, Filament moderation, cached aggregates.
5. **Alternatives pages and generated OG images.**
6. **API for Desktop/CLI**, and the community publishers plan plugs in (people, maintainers, contributors).

## Verification
- Feature tests per phase:
  - guests can view but not install or review;
  - only verified installers can review, once per app;
  - hidden reviews never show;
  - aggregates match.
- Browser check of `/apps` and an app page at desktop and phone width, in light of the Figma design.
- The full Cloud suite and PHPStan.

## Open questions
1. Install counts on the page: show rounded public numbers ("1K+"), or hide until an app has enough installs?
2. Can staff review apps themselves (they install them while testing), or should staff reviews be excluded?
3. Paid apps later (the community plan's "paid later"): leave room for a price on the button, or not yet?
