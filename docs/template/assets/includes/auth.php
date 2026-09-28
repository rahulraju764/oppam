<?php
/* =============================================================================
   AUTH SEAM — TEMPLATE STUB
   =============================================================================
   This site is a front-end skeleton. There is no database, no session and no
   form handling anywhere, by design — the backend team owns that.

   TODAY
     "Logged out" is decided by a per-page flag. Public pages (index, login,
     register) set  $is_public = true;  on line 1, before this file is required.
     Every other page omits it and is therefore treated as a member page.

   TODO(backend): SWAP POINT — this is the only function you need to rewrite.
     Replace the body of is_logged_in() with the real check:

         if (session_status() === PHP_SESSION_NONE) { session_start(); }
         return !empty($_SESSION['user_id']);

     Every header/footer branch across the site flips automatically, because
     nothing else in the codebase reads $is_public. The flags then become dead
     code and can be deleted at your leisure.

     This file is required on line 1 of every page, before any output, so it is
     safe to call session_start() here.
   ============================================================================= */

/* =============================================================================
   SITE_LIVE — the one switch that turns the demo into a real site
   =============================================================================
   FALSE  = staging/demo. head.php forces `noindex, nofollow` on EVERY page,
            whatever that page asks for, so a half-built site with dummy members
            ("Sally Roberts", VIS446178) can never end up in Google.
   TRUE   = live. Each page's own $page_robots applies.

   TODO(backend): flip this to true at launch, and delete robots.txt.
   ============================================================================= */
if (!defined('SITE_LIVE')) {
    define('SITE_LIVE', false);
}

function is_logged_in()
{
    global $is_public;
    return empty($is_public);   // a public page means: treat visitor as logged out
}

/* Where the logo / "Home" / end-of-wizard "continue" should point.

   'dashboard' and './', not 'dashboard.php' and 'index.php' — the site serves
   extensionless URLs (see .htaccess) and the home page IS the directory. './'
   rather than '' because an empty href means "this page". */
function home_url()
{
    return is_logged_in() ? 'dashboard' : './';
}

/* Is the current page one of $pages? Used for the nav's active/aria-current state.
   Pass a parent AND its children so a section stays lit on its sub-pages, e.g.
       nav_active('matches.php', 'daily-matches.php', 'single-profile.php')
   lights "Matches" on all three. Lives here, not in header.php, because the mobile
   tab bar (footer2.php) needs it too — ONE active-state mechanism, not two. */
if (!function_exists('nav_active')) {
    function nav_active(...$pages)
    {
        return in_array(basename($_SERVER['PHP_SELF']), $pages, true);
    }
}

/* =============================================================================
   CSRF — the template half
   =============================================================================
   Every POST form on the site renders csrf_field(). That half is done and cannot
   now be forgotten form-by-form when handlers land: the field is already in the
   markup, so a backend handler that checks the token will find one.

   The VALIDATION half is the backend's, and it does not exist yet — there is no
   session to hold a token against, so nothing here proves anything. Do not read
   the hidden input on any page and conclude a request is genuine.

   TODO(backend): SWAP POINT — rewrite csrf_token() only.

       if (session_status() === PHP_SESSION_NONE) { session_start(); }
       if (empty($_SESSION['csrf'])) {
           $_SESSION['csrf'] = bin2hex(random_bytes(32));
       }
       return $_SESSION['csrf'];

     ...and add the checker, called at the top of every POST handler BEFORE it
     touches $_POST:

       function csrf_check() {
           if ($_SERVER['REQUEST_METHOD'] !== 'POST') { return; }
           $sent = $_POST['csrf_token'] ?? '';
           if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
               http_response_code(419); exit('Session expired. Please try again.');
           }
       }

     hash_equals(), not ===: a timing-safe compare. And keep the token per
     session, not per form — one rotating token per form breaks the back button
     and every second tab.

     Two forms need care beyond the field itself:
       • login.php / register.php / forgot-password.php — the token must exist
         for a LOGGED-OUT visitor too, so the session has to start on GET, not
         only once someone is authenticated. Regenerate the session id on
         successful login (session_regenerate_id(true)) or the pre-login token
         becomes a fixation handle.
       • profile-photos.php and verification.php are multipart. PHP silently
         discards the WHOLE body — $_POST included — when the upload exceeds
         post_max_size, so an empty $_POST there means "too big", not "forged".
         Check $_SERVER['CONTENT_LENGTH'] before reporting a CSRF failure.

   GET forms (search.php's two) deliberately carry NO token. They are read-only
   and a token in a query string ends up in logs, referrers and shared URLs.
   ============================================================================= */
if (!function_exists('csrf_token')) {
    function csrf_token()
    {
        /* Placeholder. Constant per request, meaningless across requests — there
           is no session to anchor it to yet. Named so it is obvious in View
           Source that nothing is being protected. See the swap point above. */
        static $demo = null;
        if ($demo === null) {
            $demo = 'demo-no-session-' . bin2hex(random_bytes(8));
        }
        return $demo;
    }
}

/* The hidden input itself. Print it as the FIRST child of every POST <form>:

       <form method="post" action="register">
           <?php csrf_field(); ?>

   The name is 'csrf_token' everywhere — one name, so csrf_check() needs no
   per-form knowledge. */
if (!function_exists('csrf_field')) {
    function csrf_field()
    {
        echo '<input type="hidden" name="csrf_token" value="'
            . e(csrf_token()) . '">';
    }
}

/* =============================================================================
   OUTPUT ESCAPING
   =============================================================================
   e() is the ONLY way a value should reach the page. It exists here — in the file
   every page already requires on line 1 — so there is never a reason to reach for
   a raw <?php echo ?> again.

   This matters more than it looks while the data is hardcoded. Today $matches and
   $me are literals in the page, so nothing can be injected. The moment the backend
   swaps them for a profile row, "About me" / "Name" / "Occupation" become attacker-
   controlled strings rendered into HTML on every browse page at once. Escaping now
   is a find-and-replace; escaping later is an audit of ~120 call sites under
   incident pressure.

   ENT_QUOTES  — escapes ' as well as ", so e() is safe inside single-quoted
                 attributes too (data-dot='...' on the hero carousel).
   ENT_SUBSTITUTE — invalid UTF-8 becomes U+FFFD instead of returning an empty
                 string, which is how escaping functions silently blank a field.
   ============================================================================= */
if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/* Echo an escaped value. `<?php ee($m['name']); ?>` reads better in markup than
   `<?php echo e($m['name']); ?>` and is one fewer thing to forget. */
if (!function_exists('ee')) {
    function ee($value)
    {
        echo e($value);
    }
}

/* =============================================================================
   URLs
   =============================================================================
   site_url() derives the site's absolute base from the request, so the same code
   serves http://localhost/Projects/.../matrimony/ and https://oppam.example/ with
   no config file to keep in sync. Used for <link rel=canonical> and og:url, which
   MUST be absolute — a relative canonical is ignored and a relative og:url makes
   every share card point at nothing.

   TODO(backend): when the real domain is known, hardcode it here instead. Deriving
   the host from $_SERVER['HTTP_SERVER'] trusts a client-supplied header, which is
   fine for a canonical tag but not for anything security-bearing (password-reset
   links, redirects). Real host allow-listing belongs in the vhost, not here.
   ============================================================================= */
if (!function_exists('site_url')) {
    function site_url($path = '')
    {
        static $base = null;

        if ($base === null) {
            $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            $scheme = $https ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

            // The directory the site is served from — '' at a domain root,
            // '/Projects/work/active/Matrimony/matrimony' under XAMPP.
            $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
            $dir = rtrim($dir, '/');

            $base = $scheme . '://' . $host . $dir . '/';
        }

        return $base . ltrim($path, '/');
    }
}

/* 'search.php' -> 'search'. The site serves extensionless URLs and 301s the .php
   form to them (see .htaccess), so this is the form every link and every
   canonical must use. Anything that is not one of our own pages — an anchor, a
   mailto:, an absolute URL — is handed back untouched. */
if (!function_exists('url')) {
    function url($page)
    {
        if ($page === 'index.php' || $page === '') {
            return './';
        }
        if (preg_match('~^([a-z0-9-]+)\.php($|[#?])~i', $page, $m)) {
            return $m[1] . substr($page, strlen($m[1]) + 4);
        }
        return $page;
    }
}

/* The canonical URL of the page being rendered — absolute, and extensionless.

   basename(PHP_SELF) still reports 'search.php' under the rewrite, because the
   rewrite is internal: Apache serves search.php, the browser shows /search. That
   is also why nav_active() above still matches on '.php' names. */
if (!function_exists('canonical_url')) {
    function canonical_url()
    {
        $page = basename($_SERVER['PHP_SELF']);
        return site_url($page === 'index.php' ? '' : url($page));
    }
}
