<?php
/* Session-based auth for Keepsake's single allowed user.
 *
 * ---------------------------------------------------------------------------
 * THE GATE IS ON. Every screen in this app is private — there is no public
 * surface, no embeddable view and no second role, matching the rest of the
 * suite (see PLAN.md).
 *
 * It gets its OWN session cookie name (see auth_start_session()), so signing
 * in or out here never disturbs the RSS Reader, Grocery or Personal CRM on
 * the same host.
 *
 * It FAILS OPEN when no password is configured yet — see require_login_page().
 * That matches every sibling in the suite: locking Kathryn out of her own app
 * with no way back in is worse than an unlisted URL reachable to anyone who
 * finds it. Run tools/make-hash.php and paste the result into config.php as
 * 'password_hash' to arm the gate.
 * ---------------------------------------------------------------------------
 *
 * SINGLE PASSWORD IN config.php, NOT A `users` TABLE. Phase 0 originally
 * built a real `users` table with a username, kept through this pass's first
 * reconciliation as a deliberate divergence — Kathryn later decided she
 * doesn't want a username for a one-person app, so this now matches every
 * sibling exactly: one `password_hash` value living in config.php, checked
 * with auth_is_configured()/auth_attempt_login(string $password). There is no
 * `users` table in schema.sql any more.
 *
 * LOGIN THROTTLING IS PORTED FROM PERSONAL CRM (which ports it from Grocery,
 * the sibling with auth_attempt_delay() factored out as a pure, tested
 * function rather than left inline — see personal-cms/CLAUDE.md). Escalating
 * delay plus a hard lockout window, both keyed to the client IP server-side in
 * a `login_attempts` table, because a session-based counter protects nothing:
 * an attacker just drops the cookie between guesses. */

declare(strict_types=1);

function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    session_set_cookie_params(array(
        // Browser-session cookie, not a persistent one: Phase 0's original
        // decision, kept as-is (unrelated to this pass).
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ));

    // Its own cookie name, so signing in or out of this app doesn't disturb
    // a session for one of the siblings sharing the same host.
    session_name((string) cfg('session_name', 'keepsake_session'));
    session_start();
}

function auth_is_logged_in(): bool
{
    auth_start_session();
    return !empty($_SESSION['authed']);
}

/**
 * True once a password hash is configured — i.e. the gate is usable at all.
 *
 * Fails OPEN (returns false, which require_login_page() treats as "don't
 * gate") until config.php has a real 'password_hash' — matching every
 * sibling: an unconfigured deploy is reachable by anyone who finds the URL
 * rather than reachable by nobody, including Kathryn.
 */
function auth_is_configured(): bool
{
    $hash = (string) cfg('password_hash', '');
    return $hash !== '' && $hash !== 'CHANGE_ME';
}

/* ------------------------------------------------------- login throttling */

/* A single password on the public internet needs more than a fixed delay, or
 * an attacker gets unlimited guesses at whatever rate the server allows.
 *
 * Counting is keyed to the client address in the database, not the session —
 * an attacker just drops the cookie, so session counters protect nothing. */

const AUTH_WINDOW_MINUTES = 15;   // how far back failures are counted
const AUTH_LOCK_AFTER     = 10;   // failures in that window before refusing
const AUTH_SLOW_AFTER     = 3;    // failures before delays start escalating
const AUTH_MAX_DELAY      = 4;    // seconds — cap so a request can't hang

/**
 * REMOTE_ADDR only, deliberately.
 *
 * X-Forwarded-For is trivially spoofed, and trusting it would let an attacker
 * present a new address per request and bypass this entirely. The cost is
 * that behind a proxy every visitor may share one address — which is why
 * there is no permanent lockout below, only a window that always expires.
 */
function auth_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : 'unknown';
}

/**
 * Throttle state for this client: recent failure count and, if locked out,
 * how many seconds remain.
 *
 * Fails OPEN if the table is missing — deploying this code before running
 * schema.sql should not lock Kathryn out of her own app. It logs instead.
 *
 * @return array{failures:int, blocked_for:int}
 */
function auth_throttle_state(): array
{
    $none = array('failures' => 0, 'blocked_for' => 0);

    // Every timestamp comparison happens inside SQL, on MySQL's clock, so PHP
    // and the database can never disagree about whether a lockout has expired.
    try {
        $row = q(
            'SELECT COUNT(*) AS failures,
                    GREATEST(0, COALESCE(
                      TIMESTAMPDIFF(SECOND, NOW(), MAX(attempted_at) + INTERVAL ? MINUTE), 0
                    )) AS blocked_for
               FROM login_attempts
              WHERE ip = ?
                AND succeeded = 0
                AND attempted_at > NOW() - INTERVAL ? MINUTE',
            array(AUTH_WINDOW_MINUTES, auth_client_ip(), AUTH_WINDOW_MINUTES)
        )->fetch();
    } catch (Throwable $e) {
        error_log('auth: throttle unavailable (run schema.sql?): ' . $e->getMessage());
        return $none;
    }

    $failures = (int) ($row['failures'] ?? 0);

    // The window runs from the most recent failure, so hammering the lock
    // keeps it shut rather than letting attempts leak through as it ages out.
    return array(
        'failures'    => $failures,
        'blocked_for' => $failures >= AUTH_LOCK_AFTER ? (int) $row['blocked_for'] : 0,
    );
}

/** Seconds this client must wait, or 0 when it may try. */
function auth_blocked_for(): int
{
    return auth_throttle_state()['blocked_for'];
}

/**
 * How long to stall this attempt, in seconds, given how many failures this
 * address already has in the window.
 *
 * Baseline delay so a wrong password can't be timed, then escalation once the
 * address starts looking like a guessing loop: 0.25s flat, then 0.5, 1, 2, 4,
 * capped. An attacker's throughput collapses while one honest typo still
 * costs nothing noticeable.
 *
 * Pulled out of auth_attempt_login() as a pure function so it can be tested
 * without a database, a session or a real password — same reasoning
 * personal-cms/CLAUDE.md gives for keeping this factored out.
 */
function auth_attempt_delay(int $failures): float
{
    if ($failures < AUTH_SLOW_AFTER) {
        return 0.25;
    }
    return min(0.25 * (2 ** ($failures - AUTH_SLOW_AFTER + 1)), (float) AUTH_MAX_DELAY);
}

function auth_record_attempt(bool $succeeded): void
{
    try {
        q(
            'INSERT INTO login_attempts (ip, succeeded) VALUES (?, ?)',
            array(auth_client_ip(), $succeeded ? 1 : 0)
        );

        // Clear this address's failures on success so one good login resets
        // the counter, and prune old rows so the table can't grow unbounded.
        if ($succeeded) {
            q('DELETE FROM login_attempts WHERE ip = ? AND succeeded = 0', array(auth_client_ip()));
        }
        if (random_int(1, 20) === 1) {
            q('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 30 DAY');
        }
    } catch (Throwable $e) {
        error_log('auth: could not record attempt: ' . $e->getMessage());
    }
}

/**
 * Verify a password attempt and start the session. Returns success.
 *
 * Callers must check auth_blocked_for() first — this records the attempt and
 * escalates its own delay, but does not enforce the lockout itself.
 */
function auth_attempt_login(string $password): bool
{
    auth_start_session();

    if (!auth_is_configured() || $password === '') {
        return false;
    }

    $hash = (string) cfg('password_hash', '');

    usleep((int) round(auth_attempt_delay(auth_throttle_state()['failures']) * 1_000_000));

    if (!password_verify($password, $hash)) {
        auth_record_attempt(false);
        return false;
    }

    auth_record_attempt(true);

    // Regenerate the session id on privilege change to prevent session
    // fixation.
    session_regenerate_id(true);
    $_SESSION['authed']   = true;
    $_SESSION['login_at'] = time();

    return true;
}

function auth_logout(): void
{
    auth_start_session();
    $_SESSION = array();

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', array(
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'httponly' => true,
            'secure'   => $p['secure'],
            'samesite' => 'Lax',
        ));
    }

    session_destroy();
}

/* ------------------------------------------------------------- THE GATE */

/**
 * Gate an HTML screen. Called by every entry point in public/ except
 * login.php — every screen in this app is private.
 *
 * Fails OPEN when no password has been configured yet (see
 * auth_is_configured()). That is deliberate, matching every sibling: someone
 * who deploys this without setting a hash would otherwise be locked out of
 * their own app with no way in.
 */
function require_login_page(): void
{
    noindex();

    if (!auth_is_configured()) {
        return;
    }
    if (!auth_is_logged_in()) {
        // Come back to the page you were on rather than always dumping you
        // on the dashboard.
        $redirect = $_SERVER['REQUEST_URI'] ?? '/index.php';
        header('Location: /login.php?redirect=' . urlencode($redirect));
        exit;
    }
}

/**
 * The same gate for JSON endpoints (public/api/*.php, once those exist).
 * 401s instead of redirecting — a fetch() that follows a 302 to an HTML
 * login page produces a JSON parse error at the caller, which is a
 * confusing way to learn you're signed out.
 */
function require_login_api(): void
{
    if (!auth_is_configured()) {
        return;
    }
    if (!auth_is_logged_in()) {
        json_error('unauthorized', 401);
    }
}

/**
 * Keep this app out of search results.
 *
 * NOT redundant with the gate: the gate fails open when no password is
 * configured yet, and an unconfigured deploy is exactly when you least want
 * a crawler indexing anything. Called automatically by require_login_page().
 */
function noindex(): void
{
    header('X-Robots-Tag: noindex, nofollow');
}

/**
 * CSRF guard for public/api/*.php's mutating endpoints (Phase 2 is this
 * app's first batch of them). Ported exactly from Inspiration Board's
 * lib/auth.php, with only the header value changed to be Keepsake-specific.
 *
 * A cross-origin form post cannot set a custom header without passing a CORS
 * preflight this app never answers, so a fixed header value plus
 * SameSite=Lax is sufficient for a single-user app — no CSRF token to
 * generate, store or rotate. public/assets/api.js sends this header on every
 * non-GET request; a hand-rolled fetch() that forgets gets 'csrf_check_failed'
 * back rather than silently mutating data an attacker's page triggered.
 *
 * Every mutating public/api/*.php endpoint calls this alongside
 * require_login_api() and require_method(...).
 */
function require_same_origin(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, array('GET', 'HEAD', 'OPTIONS'), true)) {
        return;
    }
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'Keepsake') {
        json_error('csrf_check_failed', 403);
    }
}
