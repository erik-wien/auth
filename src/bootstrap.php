<?php
/**
 * src/bootstrap.php — Web request bootstrap.
 *
 * Call auth_bootstrap() once per request, after opening $con, before any output.
 *
 * Responsibilities:
 *  1. Emit security headers (CSP nonce, HSTS, X-Content-Type-Options, etc.).
 *  2. Accept per-project CSP source additions via $cspExtras.
 *  3. Start session with hardened cookie options.
 *  4. Handle sId cookie session recovery.
 *
 * Does NOT require_once other library files — Composer autoload.files handles that.
 *
 * @param array $cspExtras Keyed by CSP directive, value is extra sources to append.
 *   Example: ['script-src' => 'https://cdn.jsdelivr.net', 'font-src' => 'https://fonts.gstatic.com']
 *   Each key may appear once; multiple sources: space-separate them in the value string.
 */
function auth_bootstrap(array $cspExtras = []): void {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;

    // Make nonce available to templates as a global.
    global $_cspNonce;
    $_cspNonce = base64_encode(random_bytes(16));

    // ── Security headers ──────────────────────────────────────────────────────

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');

    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    // Build CSP, merging per-project extras into the base directives.
    $base = [
        'default-src'    => "'self'",
        'script-src'     => "'self' 'nonce-{$_cspNonce}'",
        'style-src'      => "'self' 'unsafe-inline'",
        'img-src'        => "'self' data:",
        'connect-src'    => "'self'",
        'font-src'       => "'self'",
        'frame-ancestors'=> "'none'",
        'base-uri'       => "'self'",
        'form-action'    => "'self'",
    ];

    foreach ($cspExtras as $directive => $sources) {
        if (isset($base[$directive])) {
            $base[$directive] .= ' ' . $sources;
        } else {
            $base[$directive] = $sources;
        }
    }

    $csp = implode('; ', array_map(
        fn($d, $v) => "{$d} {$v}",
        array_keys($base), $base
    ));
    header("Content-Security-Policy: {$csp}");

    // ── Session ───────────────────────────────────────────────────────────────

    $sessionOpts = [
        'cookie_lifetime' => 60 * 60 * 24 * 4,
        'cookie_httponly' => true,
        'cookie_secure'   => $isHttps,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ];

    session_start($sessionOpts);

    if (empty($_SESSION['sId'])) {
        if (isset($_COOKIE['sId']) && preg_match('/^[a-zA-Z0-9\-]{22,128}$/', $_COOKIE['sId'])) {
            // Attempt to restore a previous session from the sId cookie.
            session_abort();
            session_id($_COOKIE['sId']);
            session_start($sessionOpts);
            // use_strict_mode may reject a stale/unknown sId and silently create a new
            // session with a different ID, leaving $_SESSION['sId'] empty.  Fall through
            // to the initialisation block below so every request in this browser gets a
            // consistent session rather than each request creating an independent one
            // (which would break CSRF token continuity between login.php and authentication.php).
        }
        // Whether we just attempted restoration or arrived with no sId cookie at all,
        // initialise sId if it is still unset (brand-new session or strict-mode rejection).
        if (empty($_SESSION['sId'])) {
            $_SESSION['sId'] = session_id();
            setcookie('sId', $_SESSION['sId'], [
                'expires'  => time() + 60 * 60 * 24 * 4,
                'path'     => '/',
                'httponly' => true,
                'secure'   => $isHttps,
                'samesite' => 'Lax',
            ]);
        }
    }
}
