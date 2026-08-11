<?php
/* POST /api/year-projects-create.php   { name?, year? }
 *
 * Make a project by hand, from the + on the project list.
 *
 * The OTHER way projects come into existence is still
 * year_project_get_or_create(), which derives a year from the date on whatever
 * is being saved and runs for anything captured from outside a project. This
 * endpoint is the one Kathryn reaches on purpose, and the only one that can
 * make a project that is not a calendar year — a trip book, whose membership
 * is explicit rather than inferred from dates.
 *
 * WHY A NAME THAT LOOKS LIKE A YEAR BECOMES A YEAR PROJECT. Typing "2019" into
 * "New project" and getting a book called "2019" with year = NULL would be a
 * trap: it would sit outside every year query, and the next photo dated 2019
 * would create a SECOND project also called 2019. So a bare four-digit year in
 * a plausible range is read as the year, not as a name — and because
 * year_project_create() resolves an existing year to its existing row, typing
 * the year of a book you already have opens that book instead of failing on
 * the unique key.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();

$name = trim((string) ($body['name'] ?? ''));
$year = isset($body['year']) && $body['year'] !== '' ? (int) $body['year'] : null;

/* 1900..2100 rather than "any four digits": it has to be wide enough for a
 * scanned photograph from before anyone alive remembers and narrow enough that
 * a book she means to call "2049" — a name, not a year — is vanishingly
 * unlikely to exist. Outside that range the string stays a name. */
if ($year === null && preg_match('/^\d{4}$/', $name) === 1) {
    $asYear = (int) $name;
    if ($asYear >= 1900 && $asYear <= 2100) {
        $year = $asYear;
        $name = '';
    }
}

if ($year === null && $name === '') {
    json_error('bad_request', 400, 'A project needs a year or a name.');
}

try {
    $id = year_project_create($year, $name === '' ? null : $name);
} catch (InvalidArgumentException $e) {
    json_error('bad_request', 400, $e->getMessage());
}

$project = year_project_get($id);
if ($project === null) {
    // Only reachable if the insert silently did nothing, which would mean the
    // table is missing — surfacing it beats handing back an id that every
    // foreign key below would then fail against.
    json_error('create_failed', 500, 'The project could not be created.');
}

json_out(array(
    'id'            => $id,
    'year'          => $project['year'] !== null ? (int) $project['year'] : null,
    'title'         => $project['title'],
    'display_title' => year_project_title($project),
    'url'           => 'project.php?id=' . $id,
));
