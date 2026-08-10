<?php
/**
 * Correcting a photo's date re-groups it.
 *
 * This is the bug that put eight of Kathryn's 2025 photos into one "event"
 * spanning March to November: they were uploaded with the wrong date, clustered
 * on it, corrected afterwards, and nothing re-ran the grouping. It stayed
 * invisible for months because a stale group looks exactly like a real one
 * until you read the dates.
 *
 * The behaviour is a deliberate exception to the rule stated everywhere else —
 * that auto-grouping never touches a photo already in a group — so the
 * exception's edges are what this file actually pins down. It must fire on a
 * real date change, and it must NOT fire when she assigns a group by hand or
 * saves some other field.
 *
 * The endpoint itself needs a session and an HTTP request, so the logic is
 * exercised through the same functions it calls, in the same order.
 *
 * Usage: php tools/verify-regroup.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/test-harness.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/repo.php';

/* bootstrap.php is not loaded — it needs a real config.php this build
 * environment does not have. grouping.php and geocode.php both call cfg(), so
 * the same test-only shim tools/verify-grouping.php uses stands in, reading
 * config.example.php directly. */
if (!function_exists('cfg')) {
    function cfg(string $path, $default = null)
    {
        $node = $GLOBALS['config'] ?? array();
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }
        return $node;
    }
}
$GLOBALS['config'] = require __DIR__ . '/../config.example.php';

require __DIR__ . '/../lib/geocode.php';
require __DIR__ . '/../lib/grouping.php';

$failures = 0;

function check(string $label, bool $ok): void
{
    global $failures;
    if (!$ok) { $failures++; }
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $label);
}

/** What public/api/photos-update.php does, in the order it does it. */
function save_photo(int $id, array $body): ?int
{
    $photo  = photo_get($id);
    $fields = array();

    if (array_key_exists('entry_date', $body)) {
        $time = substr((string) $photo['captured_at'], 10) ?: ' 00:00:00';
        $fields['captured_at'] = $body['entry_date'] . $time;
    }
    if (array_key_exists('caption', $body)) {
        $fields['caption'] = $body['caption'];
    }
    if (array_key_exists('event_group_id', $body)) {
        $fields['event_group_id'] = $body['event_group_id'];
    }

    $dateMoved = isset($fields['captured_at'])
        && $fields['captured_at'] !== (string) $photo['captured_at'];
    $regroup = $dateMoved && !array_key_exists('event_group_id', $body);

    if ($regroup) { $fields['event_group_id'] = null; }

    photo_update($id, $fields);

    if ($regroup) { event_grouping_run((int) $photo['year_project_id']); }

    $after = photo_get($id);
    return $after['event_group_id'] !== null ? (int) $after['event_group_id'] : null;
}

harness_pdo();

q('INSERT INTO year_projects (year) VALUES (?)', array(2025));
$yearId = (int) db()->lastInsertId();

function add_photo(int $yearId, string $at): int
{
    q('INSERT INTO photos (year_project_id, original_path, thumb_path, width, height, captured_at)
       VALUES (?, ?, ?, ?, ?, ?)',
        array($yearId, 'o.jpg', 't.jpg', 3024, 4032, $at));
    return (int) db()->lastInsertId();
}

/* Two real events, a fortnight apart, plus the photo that will move between
 * them — which starts life mis-dated into the March event, exactly as hers did. */
$march = array(add_photo($yearId, '2025-03-10 09:00:00'), add_photo($yearId, '2025-03-10 09:30:00'));
$july  = array(add_photo($yearId, '2025-07-20 14:00:00'), add_photo($yearId, '2025-07-20 14:30:00'));
$strays = add_photo($yearId, '2025-03-10 21:20:16');

event_grouping_run($yearId);

$marchGroup = (int) photo_get($march[0])['event_group_id'];
$julyGroup  = (int) photo_get($july[0])['event_group_id'];

check('the two real events grouped separately', $marchGroup !== $julyGroup);
check('the mis-dated photo landed in the March group',
    (int) photo_get($strays)['event_group_id'] === $marchGroup);

echo "\nCorrecting the date...\n";

$now = save_photo($strays, array('entry_date' => '2025-07-20'));

check('the photo left the March group', $now !== $marchGroup);
check('...and joined the July one it actually belongs to', $now === $julyGroup);
check('its time of day is preserved, as the endpoint promises',
    substr((string) photo_get($strays)['captured_at'], 10) === ' 21:20:16');

echo "\nWhat must NOT trigger it...\n";

/* Saving another field leaves the grouping alone. */
$before = (int) photo_get($march[0])['event_group_id'];
save_photo($march[0], array('caption' => 'A caption'));
check('saving a caption does not regroup', (int) photo_get($march[0])['event_group_id'] === $before);

/* Re-saving the same date is not a change. */
save_photo($march[0], array('entry_date' => '2025-03-10'));
check('re-saving the same date does not regroup',
    (int) photo_get($march[0])['event_group_id'] === $before);

/* An explicit assignment is a decision and outranks the clusterer — even when
 * the date moves in the same request, which is the case that would otherwise
 * silently undo her. */
save_photo($march[1], array('entry_date' => '2025-07-20', 'event_group_id' => $marchGroup));
check('a hand-picked group survives a date change in the same save',
    (int) photo_get($march[1])['event_group_id'] === $marchGroup);

/* And a date change on an ungrouped photo places it, rather than needing the
 * button pressed afterwards. */
$loose = add_photo($yearId, '2025-01-05 08:00:00');
check('a brand-new photo starts ungrouped', photo_get($loose)['event_group_id'] === null);
$placed = save_photo($loose, array('entry_date' => '2025-07-21'));
check('correcting its date places it straight into the July group', $placed === $julyGroup);

print "\n";
if ($failures > 0) {
    printf("%d check(s) FAILED.\n", $failures);
    exit(1);
}
print "ALL CHECKS PASSED\n";
