# Anicalls Website — Project Documentation & Change Log

This documents the current state of the site and every change made to it in this working session. It is a record, not a task list — nothing here should be re-applied; it describes what already exists in the codebase today.

---

## 1. Project Overview

**What it is:** Anicalls' marketing/lead-gen website — a workforce-transformation partner offering AI employees, global IT talent, GCC/BOT delivery, managed operations, and an AI governance platform (Agent OS™).

**Stack:**
- Static HTML pages, one shared `styles.css`, vanilla JS (no frameworks/build step)
- PHP + MySQL backend (WAMP), used for the booking modal, login modal, and contact forms
- Fonts loaded from Google Fonts via `<link>` per page
- Images in `assests/images/` (note: the project uses this consistent folder-name typo throughout — not a bug, just the established convention)

**Pages (9 total):**
| Page | Purpose |
|---|---|
| `index.html` | Homepage — hero, four-pillar gateway (AI Workforce / GCC / Talent / Strategy), industries, footprint, contact forms |
| `ai-workforce.html` | 20 AI employee profiles as expandable drawers |
| `talent.html` | 86 IT capability cards across 8 categories, filterable |
| `gcc.html` | GCC-as-a-Service / Build-Operate-Transfer model + BOT cost calculator |
| `managed-operations.html` | 12 managed service lines |
| `agent-os.html` | AI governance platform + compliance/trust centre |
| `solutions.html` | T&M and AI+Human cost calculators, model comparisons, AI-readiness quiz |
| `case-studies.html` | Illustrative delivery snapshots |
| `book-consultation.html` | Standalone multi-step consultation booking form (not linked from nav — direct-traffic landing page) |

**Backend (`api/`):**
- `contact.php` — generic lead capture (used by index.html's 4 contact forms), writes to `contact_inquiries` table, emails notification
- `modal-booking.php` — the sitewide booking modal (triggered by every "Book a Consultation" button)
- `booking.php` — dedicated endpoint for book-consultation.html's multi-step form, writes to `bookings` table
- `booked-slots.php` — returns already-booked time slots for the calendar
- `google-config.php`, `setup-booking-table.sql`, `setup-bookings-table.sql` — supporting config/schema

---

## 2. Design System (current state)

**Typography:** Inter, loaded at weights 400–900 on every page. (Originally Sora/Inter/IBM Plex Mono; briefly moved to Roboto; settled on Inter sitewide because Roboto lacks native 600/800 weights that several rules use, and Inter was also the agreed substitute for the unavailable "Fynd Sans Compact"/"Inter Display" fonts requested in a later redesign brief.)

**Color palette:** Fully monochrome (black/white/grey) — no blue, gold, or violet remain as decorative hues anywhere on the site. Key tokens in `styles.css :root`:
- `--ink` (#FAFAFA, page bg), `--surface` (#FFFFFF, card bg), `--text` (#111214), `--muted`, `--faint` — light-context text/surface scale
- `--blue`, `--blue-deep`, `--blue-bright`, `--violet`, `--gold` — retained as token *names* for backward compatibility (hundreds of existing rules reference them), but all now hold grey/black values instead of their original hues
- `--navy`, `--navy-2`, `--navy-3`, `--on-navy*` — dark-bookend scale, used by `.hero`, `.page-hero`, `.footer` (each of these blocks locally overrides `--text`/`--muted`/`--faint`/`--gold` to flip to light-on-dark values)
- `--good` (#1E8F5C, green) and error red (`#B3261E`) were deliberately **kept in color** — these are semantic status indicators (success/error), not brand decoration, and were out of scope for the monochrome conversion

**Logo:** all 9 pages (nav + footer) use `assests/Logo/2.png` (white variant, for dark backgrounds) and `assests/Logo/1.png` (black variant, for light backgrounds), toggled via `.logo-img-dark`/`.logo-img-light` classes.

---

## 3. Full Change Log (this session)

### 3.1 Image & asset fixes
- Audited every page for missing/mismatched card images; filled 14 previously icon-only cards on `ai-workforce.html` (6) and `managed-operations.html` (8) with relevant images sourced from the existing unused asset pool.
- Fixed a broken image reference (`hr-service.png`, which didn't exist) and a favicon path typo on `book-consultation.html`.
- Two per-image crop overrides added on `ai-workforce.html` cards (AI Executive Assistant, AI Business Analyst) where the shared crop point cut off the subject's head — scoped by filename via attribute selector, doesn't affect any other card.
- Card image treatment standardized: `object-fit:cover` with a shared `object-position:center 25%` bias (full-bleed, no blank margins, crops from bottom/sides before top).

### 3.2 Dead link / broken functionality cleanup
- Removed 36 dead footer links across all 9 pages (`industries.html`, `executive-roles.html`, `regions.html`, `investors.html` — none of these pages exist in the project).
- Removed a matching dead "Explore Industries In Depth" CTA on the homepage.
- **`book-consultation.html` fully rewired** — it was a completely broken orphan page (not linked from any nav): loaded CSS/JS from a nonexistent `assets/` folder (project only has `assests/`), so it rendered unstyled with no nav/footer; posted to a hardcoded wrong API path. Fixed: real nav/footer, real `styles.css`, a new CSS module for its form/stepper/sidebar built from existing design tokens, corrected API path.
- **`api/booking.php`** had a leftover `die("THIS IS MY BOOKING FILE")` debug line killing every request — removed. Its target `bookings` database table didn't exist — created it from the project's own `setup-bookings-table.sql`.
- **`index.html`'s 4 contact forms** were cosmetic-only (showed a fake "Thank you", sent no data). Wired to the real `api/contact.php` endpoint — added missing Name/Email fields to two forms that didn't have any, gave every input an `id`, rewrote the submit handler to actually POST and show real success/error states.
- Fixed a CSS bug where comparison tables (`solutions.html`) had `overflow:hidden` instead of `overflow-x:auto`, silently clipping wide tables on mobile.

### 3.3 AI Workforce card layout fixes
- Fixed images cropping from the top (root cause: `object-fit:cover` with no `object-position`, combined with short containers) — increased image band height and added position biasing.
- Fixed the "expanding one card also expands another" accordion bug — root cause was CSS Grid's default `align-items:stretch` making a closed sibling card's box visually inflate to match its open neighbor's row height; fixed with `align-items:start` on `.drawers`. (Not a JS bug — there were never any duplicate listeners; native `<details>` was already behaving correctly.)
- Added `min-height` to Managed Operations card description text so all 12 cards are equal height across every row, not just within a row.

### 3.4 IT Talent page icon system
- Added SVG `<symbol>`/`<use>` sprite system: 26 concept-based icons (database, migration, integration, finance, shield, code, etc.) shared across all 86 capability cards, each card linking to whichever icon best matches its actual title (not just its broad category) — e.g. the 8 SAP cards each get a different icon depending on what that specific card is about.
- Built and then removed (per follow-up instruction) a custom SVG hero illustration for the Enterprise Applications section.

### 3.5 Sitewide theme conversion (blue/ivory → monochrome black/grey/white)
- Converted the entire color system: every hardcoded hex and rgba color tied to the old blue/gold/violet hues (~28 hex values, ~124 rgba triplets scattered through `styles.css`, not just the CSS variables) was inventoried and replaced with grey/black equivalents.
- Kept semantic status colors (success green, error red) unchanged — flagged this as a deliberate choice rather than silently deciding it.
- Fixed a legibility regression this conversion caused: `--gold` and `--blue-bright` had no light-on-dark variant, so eyebrow labels/breadcrumbs/hero accent text became near-invisible dark-grey-on-near-black inside the hero/footer/page-hero sections. Fixed by giving those dark sections a `--gold` override and fixing `--blue-bright`'s base value to be light (it's dark-background-only by design).
- Fixed a distinctness regression: the 3-way AI/Human/GCC status-icon coding and the 4-way homepage pillar-tile coding both collapsed into near-identical greys after a naive hue→grey conversion; manually re-spread them into a clear light/mid/dark ramp so the category information still reads without color.
- Switched typography from Roboto to Inter (Roboto lacks native 600/800 weights that several rules use; Inter has the full range).

### 3.6 Content/image sections added
- **`gcc.html`** — added a two-column content/image split above the Build-Operate-Transfer cards, reusing the existing `.aos-grid` layout pattern. Image: `assests/images/gcc.png` (India map + skyline, previously unused).
- **`agent-os.html`** — same treatment for the Compliance & Trust Centre section, using `assests/images/security.png` (a "Trust Center" screen showing Security/Compliance/Privacy/GDPR/SOC2 — a strong content match, previously unused). Both pages now share one CSS class, `.split-media`, for this image treatment.

### 3.7 Homepage "Console Reveal" section — full redesign
- Rebuilt the homepage's dashboard-mockup console into a dark AI terminal: traffic-light window controls, "AI Agent Console" title bar with live pulse status, a scripted boot-sequence typewriter animation (vanilla JS, respects `prefers-reduced-motion`), line numbers, command-prompt styling, then the original 4 activity rows and 3 footer stats (unchanged content, restyled).
- Restructured the section from stacked (console → centered text → pillar grid) into a two-column split (text + CTA on the left, console on the right), matching a reference screenshot the user provided.
- Section background set to flat light grey (`#E6E6E6`); the console card itself and the 4 pillar tiles below remain dark, per the reference design.
- **Deliberately used all-new class names** (`.ai-console`, `.ai-row`, etc.) rather than restyling the shared `.console`/`.crow`/`.tag` classes, because `agent-os.html` reuses those same classes for its own "Governance View" console — confirmed via grep that `agent-os.html` is untouched by any of this.
- This went through two full build/revert cycles in-session (built → reverted to original per instruction → rebuilt to match a reference image) — the current state matches the final reference image provided.

### 3.8 Footer
- Footer background changed to match the sticky-header (`nav.scrolled`) background — light, instead of the original dark navy.
- Removed the dark-context token overrides that no longer apply now that the footer is light.
- **Caught and fixed a dependency**: the footer logo always showed the white (dark-background) variant, which would have gone invisible on the new light background — added an explicit override so the footer always shows the black logo variant, independent of nav scroll state.

---

## 4. Known gaps / explicitly out of scope

These were surfaced during the work above but intentionally not built, per the scope of what was asked:

- **Pages referenced in `anicalls-site-architecture-seo.md` that don't exist yet**: `/industries`, `/executive-roles`, `/marketplace`, `/ai-employees` (as a standalone page — the content lives on `ai-workforce.html` instead), `/trust` (lives on `agent-os.html` instead), `/case-studies` uses illustrative snapshots as the doc specifies, `/profiles`, `/careers`, `/fraud-alert`, `/contact` (lives on `index.html#contact` instead), and the entire ~300-page programmatic SEO scale-out (capability pages, AI employee pages, industry pages, resource centre) described in the v2 addendum. The current site is the hub-and-spoke *hub* + *spokes* only, not the ~300-page long tail.
- **`book-consultation.html`'s file-upload field** (job description upload) is mentioned by filename in its confirmation message but not actually uploaded anywhere — `api/booking.php` has no file-handling logic. Flagged, not built.
- **Live-status pulse dot in the footer/console** and a couple of very minor cosmetic items were flagged in-conversation as "let me know if you want this changed too" and left as-is since no follow-up was requested.

---

## 5. File map (what to touch for what)

| To change... | Edit... |
|---|---|
| Any color, spacing, font, shadow, animation sitewide | `styles.css` (single shared stylesheet) |
| Page content/copy | The individual `.html` file |
| Booking modal or login modal behavior | `assests/js/booking.js`, `assests/js/login-modal.js` (shared, loaded on every page) |
| Contact form backend | `api/contact.php` |
| Booking modal backend | `api/modal-booking.php` |
| book-consultation.html's own form backend | `api/booking.php` |
| IT Talent capability card icons | The `<symbol>` sprite block near the top of `talent.html`, plus `.cap-icon`/`.ck-row` in `styles.css` |
| Homepage console | `#aiConsole` block in `index.html` + `.ai-console*` rules in `styles.css` + the boot-sequence script in `index.html`'s inline `<script>` |

**Important cross-page dependency to remember:** `index.html` and `agent-os.html` both use the *original* `.console`/`.console-head`/`.crow`/`.tag`/`.ic` classes for their own separate console widgets — wait, this was changed: `index.html` now uses its own `.ai-console*` classes (see §3.7), so **only `agent-os.html` still uses the original shared `.console` classes**. Any future change to `.console`/`.crow`/`.tag` in `styles.css` affects `agent-os.html` only.
