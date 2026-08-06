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
 * It FAILS OPEN when no login exists yet — see require_login_page(). That
 * matches every sibling in the suite: locking Kathryn out of her own app with
 * no way back in is worse than an unlisted URL reachable to anyone who finds
 * it. Seed a user with tools/seed_user.php to arm the gate.
 * ---------------------------------------------------------------------------
 *
 * ONE STRUCTURAL DIVERGENCE FROM THE SIBLINGS, KEPT DELIBERATELY: this app
 * has a real `users` table (id, username, password_hash) rather than a single
 * password hash living in config.php. Keepsake's Phase 0 built it this way
 * before the sibling repos were reachable, and Part 1's reconciliation pass
 * (see PLAN.md) preserves that choice rather than replacing it — a username
 * costs nothing extra for a single-user app and the config-vs-table question
 * is orthogonal to the folder/function-style convention this pass exists to
 * fix. "Configured" below therefore means "at least one row in `users`",
 * where the siblings mean "password_hash is set in config.php".
 *
 * Login throttling (the escalating-delay curve, login_attempts table) is
 * NOT ported in this pass — Part 1 changes code SHAPE, not auth semantics,
 * and Phase 0 never had it. Worth doing before this app is reachable on the
 * open internet; see PLAN.md for the flag. */

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
        // decision, kept as-is (this pass changes code shape, not behavior).
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
    return !empty($_SESSION['user_id']);
}

/**
 * @return array{id:int,username:string}|null
 */
function auth_current_user(): ?array
{
    if (!auth_is_logged_in()) {
        return null;
    }

    return array(
        'id'       => (int) $_SESSION['user_id'],
        'username' => (string) ($_SESSION['username'] ?? ''),
    );
}

/**
 * True once at least one user exists — i.e. the gate is usable at all.
 *
 * Fails OPEN (returns false, which require_login_page() treats as "don't
 * gate") if the users table itself isn't there yet either — deploying this
 * code before running schema.sql should not be indistinguishable from a
 * crash, and querying a missing table would throw rather than degrade.
 */
function auth_is_configured(): bool
{
    try {
        $row = q('SELECT COUNT(*) AS c FROM users')->fetch();
        return ((int) ($row['c'] ?? 0)) > 0;
    } catch (Throwable $e) {
        error_log('auth: could not check users table (run schema.sql?): ' . $e->getMessage());
        return false;
    }
}

/**
 * Verify a login attempt and start the session. Returns success.
 */
function auth_attempt_login(string $username, string $password): bool
{
    auth_start_session();

    $username = trim($username);
    if ($username === '' || $password === '') {
        return false;
    }

    $user = q(
        'SELECT id, username, password_hash FROM users WHERE username = ? LIMIT 1',
        array($username)
    )->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    // Regenerate the session id on privilege change to prevent session
    // fixation.
    session_regenerate_id(true);
    $_SESSION['user_id']  = (int) $user['id'];
    $_SESSION['username'] = $user['username'];

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
 * Fails OPEN when no user has been seeded yet (see auth_is_configured()).
 * That is deliberate, matching every sibling: someone who deploys this
 * without running tools/seed_user.php would otherwise be locked out of their
 * own app with no way in.
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
 * NOT redundant with the gate: the gate fails open when no user is seeded
 * yet, and an unconfigured deploy is exactly when you least want a crawler
 * indexing anything. Called automatically by require_login_page().
 */
function noindex(): void
{
    header('X-Robots-Tag: noindex, nofollow');
}
