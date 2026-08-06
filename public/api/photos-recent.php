<?php
/* GET /api/photos-recent.php?limit=24
 *
 * Most-recently-uploaded photos, for public/assets/photo-picker.js — the
 * inline picker behind the quick-add "attach to a photo" bundling option
 * (brief §2.5) and a snapshot's hero-photo selector (brief §2.3). This is
 * NOT the year-browsing gallery Phase 3 builds; it's just "what did I
 * capture recently".
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';

require_login_api();
require_same_origin();
require_method('GET');

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 24;

$photos = array_map(
    static function (array $row): array {
        return array(
            'id'            => (int) $row['id'],
            'thumb_url'     => $row['thumb_path'],
            'original_url'  => $row['original_path'],
            'caption'       => $row['caption'],
            'location_text' => $row['location_text'],
            'entry_date'    => substr((string) $row['captured_at'], 0, 10),
        );
    },
    photos_recent($limit)
);

json_out($photos);
