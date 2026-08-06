<?php
/* Reverse geocoding for event-group auto-naming — Phase 4, brief §4.1/§7.
 *
 * OpenStreetMap Nominatim (https://nominatim.openstreetmap.org/reverse),
 * free and keyless, per the brief's "avoid paid APIs" rule. Its usage policy
 * (https://operations.osmfoundation.org/policies/nominatim/) asks for two
 * things this file exists to guarantee are never skipped:
 *   - a descriptive User-Agent identifying the app and a way to reach its
 *     operator (config.example.php's 'geocode.user_agent' — a placeholder
 *     contact is fine for personal use, but replace it before this ever runs
 *     against the real service);
 *   - no more than ~1 request/second — geocode_rate_limit() below.
 *
 * CACHE FIRST, ALWAYS. geocode_reverse() is the only entry point every
 * caller should use; it checks geocode_cache (schema.sql, Phase 1) — keyed
 * on lat/lon rounded to 3 decimal places (~110m), matching that table's own
 * column precision — before ever considering a network call, and only a
 * cache MISS reaches geocode_rate_limit()/geocode_http_fetch(). A trip
 * revisited in a later year, or several photos from the same afternoon,
 * cost Nominatim exactly one request between them.
 *
 * ================================================================================
 * THIS FILE'S NETWORK CALL IS UNTESTABLE IN THIS BUILD ENVIRONMENT.
 * ================================================================================
 * This sandbox's outbound-HTTPS proxy returns a 403 for
 * nominatim.openstreetmap.org — an organizational egress policy, not a bug
 * to chase or route around, the same category of limitation as "no MySQL"
 * (tools/test-harness.php) and "no browser" every earlier phase in this repo
 * documented and built around instead of fighting. geocode_http_fetch() is
 * therefore the ONE function in this file that has never made a real call
 * against the real API in any session that built it — it is written
 * straight off Nominatim's documented reverse endpoint contract, and
 * exercised in tools/verify-grouping.php only via the stub override
 * described in its own docblock below. A future session (or Kathryn, on a
 * real host where Nominatim isn't blocked) should sanity-check one real
 * request before trusting this blind. Everything else in this file — the
 * cache lookup/store, the rate limiter's timing math, and the JSON parsing
 * in geocode_parse_response() — is exercised directly with synthetic
 * response bodies, no network involved.
 */

declare(strict_types=1);

/** Round a GPS coordinate to geocode_cache's 3-decimal-place grid (~110m). */
function geocode_round(float $coord): float
{
    return round($coord, 3);
}

/** geocode_cache lookup by rounded coordinates, or null on a miss. */
function geocode_cache_lookup(float $latRounded, float $lonRounded): ?string
{
    $row = q(
        'SELECT location_name FROM geocode_cache WHERE lat_rounded = ? AND lon_rounded = ?',
        array($latRounded, $lonRounded)
    )->fetch();
    return $row ? (string) $row['location_name'] : null;
}

/**
 * Write a freshly-resolved name into the cache. INSERT IGNORE, not an
 * upsert: uniq_coords (schema.sql) means a second write for the same
 * rounded pair is a race between two concurrent lookups, not a correction —
 * geocode_reverse() only ever calls this after a cache MISS, so whichever
 * request's INSERT lands first wins and the other is a harmless no-op,
 * exactly what IGNORE is for here.
 */
function geocode_cache_store(float $latRounded, float $lonRounded, string $locationName): void
{
    q(
        'INSERT IGNORE INTO geocode_cache (lat_rounded, lon_rounded, location_name) VALUES (?, ?, ?)',
        array($latRounded, $lonRounded, $locationName)
    );
}

/** Nominatim's documented reverse-geocoding contact/User-Agent requirement. */
function geocode_user_agent(): string
{
    return (string) cfg(
        'geocode.user_agent',
        'Keepsake/1.0 (personal photo-book app; contact: CHANGE_ME@example.com)'
    );
}

/** Build the reverse-geocode request URL for one rounded coordinate pair. */
function geocode_build_url(float $latRounded, float $lonRounded): string
{
    $endpoint = (string) cfg('geocode.endpoint', 'https://nominatim.openstreetmap.org/reverse');
    return $endpoint . '?' . http_build_query(array(
        'format'         => 'jsonv2',
        'lat'            => $latRounded,
        'lon'            => $lonRounded,
        'zoom'           => 14,
        'addressdetails' => 1,
    ));
}

/**
 * Blocks the calling request until at least 'geocode.min_interval_seconds'
 * (config.example.php, default 1.0) has elapsed since the last ACTUAL
 * network call this process made — not since the last geocode_reverse()
 * call, which may have been a free cache hit. A `static` local rather than
 * a $GLOBALS entry: the limit only needs to hold within one PHP process/
 * request, the same lifetime db()'s own connection cache uses (lib/db.php).
 */
function geocode_rate_limit(): void
{
    static $lastCallAt = 0.0;

    $minInterval = (float) cfg('geocode.min_interval_seconds', 1.0);
    $now         = microtime(true);

    if ($lastCallAt > 0.0) {
        $elapsed = $now - $lastCallAt;
        if ($elapsed < $minInterval) {
            usleep((int) round(($minInterval - $elapsed) * 1000000));
        }
    }

    $lastCallAt = microtime(true);
}

/**
 * The ONE function that talks to Nominatim over the network — isolated
 * deliberately (see this file's header) so tools/verify-grouping.php can
 * substitute a fake response via $GLOBALS['keepsake_geocode_fetch_override']
 * (a callable taking the URL and returning a raw JSON string or null),
 * CLI-only, the same override shape lib/db.php uses for the SQLite test
 * harness — and prove geocode_reverse()'s cache/naming logic without a live
 * network call.
 *
 * @return string|null Raw response body, or null on any failure (network
 *   error, non-2xx status, timeout). geocode_reverse() treats null as "no
 *   location resolved" and callers fall back to the date-range-only name —
 *   this function never throws.
 */
function geocode_http_fetch(string $url): ?string
{
    if (PHP_SAPI === 'cli'
        && isset($GLOBALS['keepsake_geocode_fetch_override'])
        && is_callable($GLOBALS['keepsake_geocode_fetch_override'])
    ) {
        return ($GLOBALS['keepsake_geocode_fetch_override'])($url);
    }

    $context = stream_context_create(array(
        'http' => array(
            'method'        => 'GET',
            'header'        => 'User-Agent: ' . geocode_user_agent() . "\r\n"
                . "Accept: application/json\r\n",
            'timeout'       => 8,
            'ignore_errors' => true, // read the body even on a 4xx/5xx, so the status check below sees it
        ),
    ));

    // @: file_get_contents() over the http wrapper warns on a connection
    // failure rather than just returning false in every PHP configuration —
    // the return value (and $http_response_header, checked next) are what's
    // trusted, matching lib/exif.php's identical @-suppression convention.
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return null;
    }

    $status = 0;
    foreach ($http_response_header ?? array() as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        }
    }
    if ($status < 200 || $status >= 300) {
        return null;
    }

    return $body;
}

/**
 * Parse a Nominatim jsonv2 reverse-geocode response body into a short,
 * human-readable location string — pure, no I/O, so it can be proven
 * against synthetic bodies with no stub needed at all.
 *
 * Prefers a populated place name (city/town/village/hamlet/municipality,
 * falling back to county for a rural pin with none of those) plus state, e.g.
 * "Myrtle Beach, South Carolina" — matching schema.sql's own example on
 * event_groups.name ("Jul 4–6 · Myrtle Beach"). Falls back to the first two
 * comma-separated segments of `display_name` (a full postal address, longer
 * than an event-group name needs) if the address block has none of those
 * fields. Returns null for anything unparseable, including Nominatim's own
 * {"error": "Unable to geocode"} shape for a pin over open ocean.
 */
function geocode_parse_response(string $json): ?string
{
    $data = json_decode($json, true);
    if (!is_array($data) || isset($data['error'])) {
        return null;
    }

    $addr = is_array($data['address'] ?? null) ? $data['address'] : array();

    $place = null;
    foreach (array('city', 'town', 'village', 'hamlet', 'municipality', 'county') as $field) {
        if (is_string($addr[$field] ?? null) && $addr[$field] !== '') {
            $place = $addr[$field];
            break;
        }
    }

    $state = is_string($addr['state'] ?? null) ? $addr['state'] : null;

    if ($place !== null) {
        return ($state !== null && $state !== '' && $state !== $place) ? "$place, $state" : $place;
    }

    if (is_string($data['display_name'] ?? null) && $data['display_name'] !== '') {
        $parts = array_map('trim', explode(',', $data['display_name']));
        $short = implode(', ', array_slice($parts, 0, 2));
        return $short !== '' ? $short : null;
    }

    return null;
}

/**
 * Resolve one GPS coordinate pair to a location string, cache-first.
 * The only function anything outside this file should call.
 *
 * Fails soft at every stage (config over hardcoding, PLAN.md; "a Nominatim
 * failure... never breaks the upload or the 'Group photos' action" — this
 * file's own brief) — a network error, a non-2xx, or an unparseable body
 * all resolve to null, which lib/grouping.php treats as "no location" and
 * falls back to the date-range-only group name rather than raising.
 */
function geocode_reverse(float $lat, float $lon): ?string
{
    $latRounded = geocode_round($lat);
    $lonRounded = geocode_round($lon);

    $cached = geocode_cache_lookup($latRounded, $lonRounded);
    if ($cached !== null) {
        return $cached;
    }

    geocode_rate_limit();

    $body = geocode_http_fetch(geocode_build_url($latRounded, $lonRounded));
    if ($body === null) {
        return null;
    }

    $name = geocode_parse_response($body);
    if ($name === null) {
        return null;
    }

    geocode_cache_store($latRounded, $lonRounded, $name);
    return $name;
}
