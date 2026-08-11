<?php
/* Shared page chrome: the doctype, the head, the screen header, the floating
 * add button and the closing scripts.
 *
 * WHY THIS FILE EXISTS. Until now every screen in public/ hand-rolled its own
 * <!doctype>, <head>, <header class="screen-head"> and bottom tab bar — four
 * copies of the same fifteen lines, which had already drifted (index.php put
 * "Log out" in .head-actions, layout.php put a "Years" link in the same slot,
 * review.php had neither and a .back-row instead). The moment a menu button
 * has to appear on every screen, that drift becomes the bug: the menu is on
 * three screens and missing from the fourth, and nothing says so.
 *
 * Ported in shape from Personal CRM's lib/layout.php, which carries the same
 * three functions for the same reason. NOT named lib/layout.php here because
 * that name is already taken by the book-layout engine, which is a completely
 * different thing and much older.
 *
 * WHY THE BOTTOM TAB BAR IS GONE. It had two entries, "Years" and "Add".
 * "Add" is now the floating + (page_foot's $fab), because adding is an action
 * and not a place — it was the only tab that did not navigate anywhere you
 * could come back to. "Years" is now the back arrow on the one screen below
 * the top level. A fixed bar that costs 56px of a phone screen to hold one
 * link that says "up" is not paying for itself, and removing it is what makes
 * room for the + to sit where a thumb actually is.
 *
 * WHERE THE MENU LIVES, AND WHY IT IS TWO DIFFERENT BUTTONS. The top level
 * gets a hamburger, because its menu is about the app (sign out, export
 * everything). A project screen gets a kebab, because its menu is about that
 * one project (rename it, export it, delete it) — the same kebab, with the
 * same three entries, that sits on that project's card in the list. Same
 * glyph for the same scope is the whole convention: ☰ means "this app", ⋮
 * means "this thing here". A project screen therefore has NO hamburger; the
 * app-level actions are one back-tap away and duplicating them would make the
 * kebab ambiguous about what "Export" was about to export.
 */

declare(strict_types=1);

/**
 * A URL into a project screen.
 *
 * Keyed by id, not by year, which is the change that let a project stop being
 * a calendar year — see schema.sql on year_projects.year. Every link between
 * the two tabs goes through here so there is one place that knows the query
 * string's shape.
 *
 * @param int    $id
 * @param string $tab    'content' (the default) or 'book'.
 * @param array  $params Extra query parameters — the content tab's view/type
 *               filters, the book tab's selected layout version.
 * @param string $fragment Without the '#'.
 */
function project_url(int $id, string $tab = 'content', array $params = array(), string $fragment = ''): string
{
    $query = array('id' => $id);
    if ($tab !== 'content') {
        /* 'content' is the default, so it is left out rather than spelled out:
           a shared or bookmarked link to a project should be the short one. */
        $query['tab'] = $tab;
    }
    $query += $params;

    return 'project.php?' . http_build_query($query)
        . ($fragment !== '' ? '#' . rawurlencode($fragment) : '');
}

/**
 * Open the document: doctype, head, <body>, <main class="wrap">.
 *
 * Everything after this call is page content until page_foot().
 *
 * @param array $opts
 *   'title'      string  Appended to "Keepsake — ". Omit for the bare app name.
 *   'body_class' string  Extra class on <body>.
 *   'body_attrs' array   Extra <body> attributes, name => value. Used for the
 *                        data-* hooks review.js and layout.js read on boot.
 */
function page_head(array $opts = array()): void
{
    $title     = (string) ($opts['title'] ?? '');
    $bodyClass = (string) ($opts['body_class'] ?? '');
    $bodyAttrs = is_array($opts['body_attrs'] ?? null) ? $opts['body_attrs'] : array();

    $fullTitle = $title === '' ? 'Keepsake' : 'Keepsake — ' . $title;

    $attrs = '';
    if ($bodyClass !== '') {
        $attrs .= ' class="' . h($bodyClass) . '"';
    }
    foreach ($bodyAttrs as $name => $value) {
        /* Skipping nulls rather than emitting an empty attribute: review.php
           renders data-year-project-id="" when no project resolved, and
           `dataset.yearProjectId` then reads as "" rather than undefined,
           which is a different thing on the JS side. */
        if ($value === null) {
            continue;
        }
        $attrs .= ' ' . h((string) $name) . '="' . h((string) $value) . '"';
    }
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($fullTitle) ?></title>
<link rel="stylesheet" href="<?= asset('assets/styles.css') ?>">
</head>
<body<?= $attrs ?>>
<main class="wrap">
<?php
}

/**
 * The screen header: an optional back arrow, the heading, an optional
 * subtitle under it, and an optional menu trigger on the right.
 *
 * @param array $opts
 *   'heading' string  REQUIRED. The <h1>.
 *   'sub'     string  A quieter line under the heading — a project's subtitle,
 *                     or its year when the book has been given a name of its
 *                     own. Omitted entirely when empty rather than rendered as
 *                     a blank line, so the rule under the header does not move
 *                     depending on whether a subtitle exists.
 *   'back'    string  href for the back arrow. Omit for a top-level screen.
 *   'menu'    string  'hamburger' | 'kebab'. Renders the trigger with
 *                     id="app-menu"; the items are attached by the screen's
 *                     own module through menu.js. Omit for no menu.
 */
function page_screen_head(array $opts = array()): void
{
    $heading = (string) ($opts['heading'] ?? '');
    $sub     = trim((string) ($opts['sub'] ?? ''));
    $back    = (string) ($opts['back'] ?? '');
    $menu    = (string) ($opts['menu'] ?? '');

    if ($back !== ''): ?>
  <div class="row back-row">
    <a class="link-btn" href="<?= h($back) ?>">&larr; Back</a>
  </div>
<?php endif; ?>
  <header class="screen-head">
    <div class="screen-head-text">
      <h1><?= h($heading) ?></h1>
      <?php if ($sub !== ''): ?><p class="screen-head-sub"><?= h($sub) ?></p><?php endif; ?>
    </div>
    <?php if ($menu !== ''): ?>
    <div class="head-actions">
      <?php page_menu_button($menu) ?>
    </div>
    <?php endif; ?>
  </header>
<?php
}

/**
 * The menu trigger itself, as a button that does nothing until menu.js wires
 * it. Split out from page_screen_head() because the project LIST renders one
 * of these per card, where there is no screen header to hang it on.
 *
 * @param string $kind 'hamburger' or 'kebab'.
 * @param array  $attrs Extra attributes — the per-card kebabs need an id and a
 *               data-project so one delegated handler can tell them apart.
 */
function page_menu_button(string $kind, array $attrs = array()): void
{
    $isKebab = $kind === 'kebab';

    /* Inline SVG rather than a glyph character. "☰" and "⋮" render at wildly
       different weights across iOS versions and "⋮" in particular is nearly
       invisible at body weight — and neither can inherit .icon-btn's stroke
       width. Two paths cost less than one font fallback bug. */
    $label = $isKebab ? 'Project menu' : 'Menu';

    $out = '';
    foreach ($attrs as $name => $value) {
        $out .= ' ' . h((string) $name) . '="' . h((string) $value) . '"';
    }
    ?>
<button type="button" class="icon-btn" aria-label="<?= h($label) ?>" aria-haspopup="dialog"<?= $out ?>>
  <?php if ($isKebab): ?>
  <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.6" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/><circle cx="12" cy="19" r="1.6" fill="currentColor" stroke="none"/></svg>
  <?php else: ?>
  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
  <?php endif; ?>
</button>
<?php
}

/**
 * Close the document: the floating add button, then the page's modules.
 *
 * @param array $opts
 *   'fab'     array  The floating +. array('href' => …, 'label' => …), or
 *                    array('id' => …, 'label' => …) for one that opens a sheet
 *                    instead of navigating. Omit for no +.
 *   'scripts' array  Asset-relative module paths, in order.
 */
function page_foot(array $opts = array()): void
{
    $fab     = is_array($opts['fab'] ?? null) ? $opts['fab'] : null;
    $scripts = is_array($opts['scripts'] ?? null) ? $opts['scripts'] : array();
    ?>
</main>
<?php if ($fab !== null):
    $label = (string) ($fab['label'] ?? 'Add');
    /* A link when it goes somewhere, a button when it raises a sheet. The
       list screen has to ask which project first and so cannot be a link;
       inside a project there is nothing to ask, so it is a real URL that
       survives a long-press, a new tab and a bookmark. */
    if (isset($fab['href'])): ?>
  <a class="fab" href="<?= h((string) $fab['href']) ?>" aria-label="<?= h($label) ?>">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
  </a>
<?php else: ?>
  <button type="button" class="fab" <?= isset($fab['id']) ? 'id="' . h((string) $fab['id']) . '"' : '' ?> aria-label="<?= h($label) ?>">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
  </button>
<?php endif;
endif;

foreach ($scripts as $src): ?>
<script type="module" src="<?= asset($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}
