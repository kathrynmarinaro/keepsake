# Keepsake — Project Brief

**Part of Kathryn's self-hosted app suite.** Reuses the suite's established CSS design system, layout conventions, mobile-first responsive patterns, PHP/MySQL file structure, and auth pattern (login page, consistent with RSS Reader and Personal CRM). The CSS is not "reuse the tokens" — it's **use the house stylesheet itself**: every sibling app (Grocery, Personal CRM, Inspiration Board) carries `public/assets/styles.css` as a verbatim, byte-for-byte copy of the same file. Keepsake's copy should be pulled in the same way, at the same path, not reinvented or re-themed.

## Purpose

Keepsake is a year-round capture app for quotes, anecdotes, and photos that compiles automatically into an annually printed photo book, so the book doesn't have to be assembled from scratch at year's end. Single-user (Kathryn only).

Built with an eye toward eventually being shared as public, open-source code on GitHub — so configuration (not hardcoded values) should be used where reasonable, and no secrets should be committed. This is a design consideration, not a current requirement; it should not slow down the initial build.

---

## 1. Core Concept: Year Projects

Each calendar year is its own project/workspace with its own content pool, its own review views, and its own independently generated book layout. Regenerating one year's layout never touches another year's.

- Kathryn will backfill 2020–2025 in addition to working on 2026 in real time, meaning multiple year-projects may be "in progress" simultaneously, each at a different stage of completion.
- A year-project selector/dashboard is needed so Kathryn can jump into any year to view its status and continue work.

---

## 2. Content Types

### 2.1 Quote
- Text entry.
- "Who said it" field: Kathryn or Emma.
- Date (defaults to submission date, editable).

### 2.2 Anecdote
- A sentence or two describing something that happened.
- Date (defaults to submission date, editable).

### 2.3 Snapshot (structured, occasional — birthday & school year)
Two templates, both with a set of **optional** fixed fields plus a freeform notes field, and both paired with a **hero photo Kathryn selects manually** at submission time (not auto-pulled).

**Birthday snapshot:**
- Age
- Height
- Freeform notes

**School year snapshot** (first day / last day of school):
- Grade
- School
- Teacher
- Favorite color
- Dream job (what she wants to be when she grows up)
- Favorite class
- Freeform notes

Snapshot entries always render as a dedicated full page in the book, using a consistent template design that repeats year to year (so birthday pages look like each other across years, and school-year pages look like each other across years).

### 2.4 Photo
- Date — **defaults to EXIF creation date** if available (falls back to submission date if no EXIF date exists), editable.
- Location — optional, can be manually entered.
- Caption — optional.
- Multi-select upload supported (batch upload multiple photos in one session).
- Crop tool during upload, reusing the Inspiration Board's existing upload/crop UI pattern.
- Batch upload flow: step through each uploaded photo one-by-one to crop and caption before finishing (same pattern as Inspiration Board's batch flow).
- "Skip for book" toggle (available in desktop review view) — excludes a photo from the book layout without deleting it. Default state for every uploaded photo is **included** (Kathryn only uploads photos she intends to use).
- Optional: "make this a full page" flag — marks a photo as a standout that should get its own full page in the layout rather than being grouped with others.

### 2.5 Photo Captions vs. Standalone Text
*(Revised after Phase 2 — the original brief described a photo+quote/anecdote "bundle"; Kathryn clarified the model below instead, and Phase 2's implementation was updated to match. Kept here rather than silently edited so the reasoning survives.)*

- A photo's caption is typed **directly during photo upload/capture** — it's the photo's own `caption` field (Section 2.4), nothing more.
- A Quote or Anecdote is **always a standalone, dated entry**. It is never attached to a specific photo as that photo's caption. Its date is what lets it land near related photos in the book layout (see Section 4.3) — proximity by date, not a link to one photo.
- There is no "bundle" content type distinct from the above: a photo's caption and a quote/anecdote's date are two separate mechanisms, not one submitted-together unit.

---

## 3. Date & Year Assignment Logic

| Entry type | Default date source | Editable? |
|---|---|---|
| Quote / Anecdote (no photo) | Submission date | Yes |
| Photo | EXIF creation date (fallback: submission date) | Yes |
| Snapshot | Submission date | Yes |

- The year a piece of content belongs to (i.e., which year-project it's assigned to) is **auto-determined from its date** (EXIF year for photos). This is what makes backfilling months/years of old photos practical — no manual year-picking required.
- The auto-assigned year is editable, in case a date correction needs to move an entry into a different year-project.

---

## 4. Book Layout Engine

This is the core, hardest part of the app. Goal: avoid the "one photo per page" look of tools like Google Photos. Instead, produce visually intentional spreads of 1–4 photos each, grouped by actual events, flowing chronologically, with text woven in.

### 4.1 Event Grouping (Hybrid)
- **Auto-detection**: the app groups photos into "events" based on date-gap detection (a gap of X days between photos starts a new event group). Exact threshold to be tuned once real data is available.
- **Auto-naming**: each detected group is auto-named using its date range plus a location, where location is derived via **reverse geocoding of photo GPS EXIF data** (e.g., OpenStreetMap Nominatim — free, no API key required, consistent with the suite's "avoid paid APIs" principle). Falls back to date range only if no GPS data is available.
- **Manual override**: Kathryn can rename any group, and can merge or split groups during review — critical for avoiding the "stray unrelated photo on the Myrtle Beach page" problem.

### 4.2 Photo Selection Within a Group
- All uploaded, non-skipped photos within a group are included by default.
- Kathryn culls via the "skip for book" toggle in desktop review — no deletion needed, easily reversible.

### 4.3 Page Arrangement Logic
Within an event group, the app further sub-groups photos by **day / close timing** (so a beach day and a dinner-out day don't land on the same page), then arranges photos onto pages using:

- **Orientation matching** as the primary driver — pairing portrait/landscape photos in combinations that look good together (e.g., two portraits side by side, a landscape pair stacked, etc.).
- **Intentional visual variety** as a secondary influence — the algorithm should avoid monotonous repetition (e.g., not ten 2-up spreads in a row) by varying page density (1, 2, 3, or 4 photos per page) across the book, informed by both orientation matching and a rhythm/variety heuristic.
- **Manually flagged "full page" photos** always get their own dedicated page, breaking out of the grouping logic at that point.
- **Quotes/anecdotes** that fall within an event's date range (by their `entry_date`) occupy one of the page's slots, styled distinctly (e.g., a card treatment) rather than as a photo. A quote/anecdote is always placed this way — see 2.5's revision — never as a photo's caption.
  - If a standalone text entry exceeds **~180 characters** (tunable default), it gets a full page of its own instead of sharing a slot. This threshold should be treated as a starting point to adjust after seeing a real draft.
- **A photo's typed caption** (2.4/2.5) renders inline with that photo, within its existing slot — this isn't a separate content type competing for a slot, just text attached to the photo occupying it.
- **Snapshot entries** always render as their own dedicated full-page template (see 2.3), regardless of surrounding event grouping.

### 4.4 Manual Adjustment (Fallback)
- Drag-and-drop interface to rearrange photos across pages/pages themselves, available but expected to be rarely needed given the auto-arrange goals above.

### 4.5 Regeneration Behavior ("Middle Ground")
- After layout generation, manual edits (swap a hero photo, move a photo, adjust a page) are **local by default** — they don't ripple forward into other already-generated pages.
- An explicit **"reflow from here"** action is available to regenerate everything downstream of a given point, for use when a change is significant enough to be worth re-flowing later content.
- Layout generation ("create book layout") can be run multiple times per year if Kathryn wants to preview/compare different auto-generated results, though the primary real-world use is once, at year's end.

### 4.6 Cover & Title Page
- **Title**: defaults to the year (e.g., "2026"). An optional subtitle field is available for editing during the pre-layout content review step.
- **Cover photo**: manually selected by Kathryn (same pattern as snapshot hero photos) — not auto-selected.

---

## 5. Workflow / Screens

### 5.1 Capture (Mobile-First)
- Quick-add flow for quotes, anecdotes, and snapshots.
- Photo upload with multi-select, EXIF-based auto-date, crop tool, batch step-through for captions (reusing Inspiration Board's pattern).
- Photo + text bundling option at submission time.
- Backfill mode: functionally the same submission flow, just used repeatedly/in bulk for populating past years (2020–2025) — no special date-scoping UI, since EXIF handles dating automatically.

### 5.2 Review / Browse (Primarily Desktop)
- Two views, both supported from the start:
  - **Chronological timeline/calendar view** (default).
  - **Flat list/grid view, filterable by type** (quote / anecdote / snapshot / photo).
- Full edit capability on any entry: text, dates, category/type, location, captions, crop.
- Photo "skip for book" toggle and "full page" flag management.
- Event group review: rename, merge, split auto-detected groups.
- Pre-layout step: set/edit the book's subtitle for the year.
- This is the staging ground — everything should be reviewed and correctable here before layout generation.

### 5.3 Layout Generation ("Create Book Layout")
- Triggered manually per year-project.
- Runs the grouping + arrangement engine described in Section 4.
- Can be re-run to generate alternate layouts.

### 5.4 Page Review (Post-Generation)
- Visual, page-by-page review of the generated book.
- Local edits (swap photos, adjust a page) plus optional "reflow from here."
- Cover photo selection happens here or just prior (final step before export).

### 5.5 Export
- Export to a print-ready PDF.
- **Trim size: 8.5" x 8.5"**, with standard bleed/margin conventions.
- Designed to be **printer-agnostic** — the same PDF should be uploadable to multiple print-on-demand services (confirmed candidates: **Lulu** and **Mixam**, both quoted at 8.5x8.5", full color, softcover, 100 pages: Lulu ≈ $25, Mixam ≈ $35) so Kathryn can order from more than one and compare quality before settling on a printer.

---

## 6. Explicitly Out of Scope (for this phase)

- Multi-user support / other family members submitting entries.
- Automated "smart" cover photo selection.
- AI/heuristic quality-based photo selection (e.g., sharpness scoring) — all inclusion/exclusion is manual via "skip for book."
- Paid geocoding or other paid API dependencies — free/self-hosted-friendly options only (e.g., Nominatim).
- In-app printer ordering/integration — export stops at PDF; ordering happens directly on the printer's own site.
- Milestone-specific tracking beyond the birthday/school-year snapshot templates (not needed at Emma's current age/stage).

---

## 7. Key Technical Notes for Build

- **EXIF extraction** needed for photo date and GPS data.
- **Reverse geocoding**: use a free service (e.g., OpenStreetMap Nominatim) to convert GPS coordinates to human-readable location names for event group naming; cache results locally in MySQL to avoid repeated lookups, consistent with the suite's dataset-caching pattern (free-exercise-db, Hugging Face GroceryList dataset).
- **Upload/crop UI**: reuse Inspiration Board's existing multi-photo upload, crop, and batch step-through-and-caption components as the base.
- **PDF generation**: needs to support the 8.5x8.5" trim size, bleed/margin conventions compatible with both Lulu and Mixam specs.
- **Auto-arrange algorithm** (date-gap event detection, day-level sub-grouping, orientation-based pairing, variety heuristic) is the most novel/complex piece of this build and will likely need iteration after seeing real output — treat the ~180-character text threshold and the date-gap threshold as tunable defaults, not fixed constants.
- Auth: standard login page, consistent with RSS Reader / Personal CRM patterns.
- Structure code with public GitHub sharing in mind eventually (config over hardcoding, no committed secrets) — not a blocker for this build phase.

---

## 8. Open Items for Future Consideration (Not This Build)

- Public-facing companion content (not applicable here — noted in suite-level backlog for Book Tracker, not Keepsake).
- Whether the date-gap threshold for event detection needs to differ by trip type (e.g., a multi-week vacation vs. a single day out) — to be evaluated after real usage.
- **Chunked photo upload.** The upload flow is deliberately synchronous —
  one request, no queue (see `lib/imageproc.php`'s header for why: there's
  no vision/color pipeline here to defer work for, unlike Inspiration
  Board). The tradeoff, found in real post-launch use: a big batch is one
  long-running request that has to stay open end to end, and a dropped
  connection or a server timeout partway through loses the *whole* batch,
  not just whichever photo was slow. The current mitigation is process,
  not code — a UI hint recommending ~10-15 photos per batch and a
  `beforeunload` warning against navigating away mid-upload
  (`public/assets/capture.js`). If batches keep needing to be larger than
  that's comfortable for, the real fix is splitting one large upload into
  several smaller sequential requests client-side, so a failure partway
  through only costs the current chunk — not a rewrite of the "no queue"
  decision itself, which was made for a different reason (nothing to defer
  processing for) and still holds regardless of how the transfer is split.
