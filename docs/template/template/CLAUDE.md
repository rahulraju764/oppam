# Oppam Matrimony

A Kerala-focused matrimonial / matchmaking website, served via XAMPP from
`p:\xampp\htdocs\Projects\work\active\Matrimony\matrimony`
(→ `http://localhost/Projects/work/active/Matrimony/matrimony/`).

Not a git repository.

## This is a frontend skeleton, not an application

All 31 `.php` pages are static HTML with hardcoded demo content (dummy members like
"Sally Roberts", fake IDs like `VIS446178`). There is:

- no database
- no session handling
- no `$_POST` / `$_GET` processing
- no form submission logic — forms have `action=` attributes but **no handlers**

The backend team owns all of that. Don't add a DB or sessions here; don't go looking for models
or controllers.

## The auth seam — read this before touching the header

`assets/includes/auth.php` defines **`is_logged_in()`**, and it is the **single swap point** for
real auth. Today it reads a per-page flag:

- **Public pages** (`index.php`, `login.php`, `register.php`) set `$is_public = true;` on line 1.
- **Every other page** omits the flag and is therefore treated as a member page.
- Every page `require_once`s `auth.php` **on line 1, before any output**, so a future
  `session_start()` inside it won't hit "headers already sent".

`header.php` and `footer2.php` branch on `is_logged_in()` — public pages get a Login /
Register Free nav and no mobile tab bar; member pages get the avatar dropdown and the tab bar.

**To switch the site to real auth, rewrite that one function** (`return !empty($_SESSION['user_id'])`).
Every branch site-wide flips automatically; nothing else reads `$is_public`. Search for
`TODO(backend):` to find every seam.

### CSRF — the field is already in every POST form

`auth.php` defines **`csrf_token()`** (a second swap point, same shape as `is_logged_in()`) and
**`csrf_field()`**, which prints `<input type="hidden" name="csrf_token">`. All 13 POST forms
render it as their first child. **The token is a per-request placeholder and proves nothing** —
there is no session to anchor it to — so nothing may read it and conclude a request is genuine.
The backend rewrites `csrf_token()` and adds `csrf_check()`; the field being in the markup
already is what stops it being forgotten form-by-form as handlers land. `search.php`'s two GET
forms deliberately carry no token — a token in a query string leaks into logs and referrers.
Add a POST form, add `<?php csrf_field(); ?>`.

The member pages carry a commented-out guard suggestion. **Don't enable it** while the site is a
static demo — it would lock everyone out.

## Shared includes

Every page includes these files from `assets/includes/`:

| Order | File | Purpose |
| --- | --- | --- |
| — | `auth.php` | **Line 1 of every page**, before output. Defines `is_logged_in()`, `home_url()`, `e()`/`ee()`, `site_url()`, `SITE_LIVE`. |
| 1 | `head.php` | **The entire `<head>`.** A page sets `$page_*` and includes this; it ends by including `links.php`. |
| 1b | `links.php` | All CSS. Called **from head.php** — pages don't include it. |
| 2 | `header.php` | The **preloader** (see below), then the sticky navbar — branches public vs member — then `pagenav.php` |
| 2b | `pagenav.php` | The back / prev / next bar. Called **from header.php**, not from the pages (see below) |
| 3 | `script.php` | All JS. **Include it LAST**, after both footers. |
| 4 | `footer.php` | Main footer |
| 5 | `footer2.php` | Mobile bottom tab bar — member only |

Plus four **content partials** — see "One component, one file" below:
`profile-row.php`, `profile-tile.php`, `member-card.php`, `story-card.php`.

And three **data files** that exist so two surfaces can't drift: `profiles-data.php` (the
browsable directory — `all-profiles.php` lists it and `single-profile.php`'s Prev/Next
profile arrows walk it), `plans.php` (the three
membership tiers — `index.php` and `package.php` both render them) and
`stories-data.php` (the success-story couples — `success-stories.php` and
`single-profile.php`'s rail both render them).

Site-wide chrome changes go in these files, not in the individual pages.

### The `<head>` is one file, not twenty

`assets/includes/head.php` renders every page's head. A page sets what differs and
includes it:

```php
$page_title    = 'All Profiles | Oppam Matrimony';
$page_desc     = '…';
$page_keywords = '…';
$og_title      = '…';   // optional — falls back to $page_title
$og_desc       = '…';   // optional — falls back to $page_desc
$page_robots   = 'noindex, nofollow';   // member pages
```

```php
<head>
<?php include __DIR__ . '/assets/includes/head.php'; ?>
</head>
```

This block was pasted into all 20 rendered pages that existed at the time, and **three things were wrong in
every copy at once**: `og:url` was the literal string `PAGE_URL`, `og:site_name`
was empty, and nearly every page declared `robots: index, follow` while `links.php`
declared `noindex, nofollow` in the same head. Don't reintroduce a per-page head.

- **`canonical` and `og:url` are absolute**, built by `site_url()` / `canonical_url()`
  in `auth.php` from the request. Relative values are ignored by crawlers and
  scrapers. `index.php` canonicalises to the bare directory, not `/index.php` —
  otherwise the home page has two URLs and splits its own signals.
- **`SITE_LIVE` (in `auth.php`) gates indexing.** While it is `false`, head.php
  forces `noindex, nofollow` on every page regardless of `$page_robots`, so a demo
  full of "Sally Roberts" can't be indexed. Flip it at launch.
- **Member pages carry `$page_robots = 'noindex, nofollow'` in their own right** —
  they sit behind auth and name real people.

### Escaping: `e()` / `ee()`, never a bare `echo`

`auth.php` defines both. **`ee($x)` is how a value reaches the page**; `e($x)`
returns the string for use inside a longer expression.

```php
<h2><?php ee($profile['name']); ?></h2>
```

There were ~120 raw `<?php echo $m['name']; ?>` call sites. That is harmless while
the arrays are literals in the page and becomes stored XSS on every browse page at
once the day the backend swaps them for profile rows. The only thing still echoed
raw is `options()` in `search.php`, which *returns* markup and escapes its own
values.

`ee()` uses `ENT_QUOTES | ENT_SUBSTITUTE`: safe in single-quoted attributes, and
invalid UTF-8 becomes U+FFFD rather than silently blanking the field.

### One component, one file

Three partials, in `assets/includes/`. Each reads a single `$profile` array, so
the call site looks the same whatever the page's own loop variable is called:

| Partial | Renders | Was |
| --- | --- | --- |
| `profile-row.php` | the `.profiles` result row | **5 hand-copied copies** — all-profiles, daily-matches, my-matches, interest, search |
| `profile-tile.php` | the `.profile-card` slide in a `.match-slider` | **10 copies in `dashboard.php`** — five members, pasted into both rails |
| `member-card.php` | the 6-up `.member-card` grid tile | 2 copies — index, single-profile |
| `story-card.php` | a success-story couple — `$story_variant` is `'grid'` (success-stories) or `'slide'` (the `.stories` rail) | **3 byte-identical slides in `single-profile.php`**, all of them "Allen & Riya" on one photo, all `href="#"` |

```php
<?php foreach ($matches as $m): ?>
    <?php $profile = $m; include __DIR__ . '/assets/includes/profile-row.php'; ?>
<?php endforeach; ?>
```

**`profile-row.php` routes on `$profile['pid']`, not `$profile['id']`.** `pid` is the database
key and only ever appears in the URL (`single-profile?id=<pid>`); `id` is the displayed reference
code ("VIS12370") and only ever appears as text. Don't collapse the two — a display code gets
reformatted or re-issued, and every such change would break the URLs. A row with no `pid` falls
back to a bare `single-profile` link, so a demo array without one still navigates.

`profile-row.php` takes `$profile_actions`: `'interest'` (default, Don't show /
Send Interest), `'respond'` (Decline / Accept, or a status chip — `interest.php`),
or `'none'`. Both variables are reset at the end of the partial so a page
rendering two lists can't leak settings from the first into the second.

**The copies had already drifted**, which is the argument for the partials:
`daily-matches.php` used a `.pf-dt-dm` class where the other four used `.pf-dt-m`
— the two CSS rules were byte-identical, and `responsive.css` had to name both in
six separate rules. `search.php` dropped the comma between qualification and
occupation, and hardcoded "Last seen an hour ago" on every row. `.pf-dt-dm` is now
gone from the CSS; **don't reintroduce a second class for the same line.**

### The mobile menu is an off-canvas drawer, not a Bootstrap collapse

Below 992px `#navbarSupportedContent` is a right-edge drawer over a scrim, driven by the
**NAV DRAWER** block in `custom.js` — not by Bootstrap. Three things follow from that:

- The toggler carries **no `data-bs-*` attributes**, and the panel has **no `collapse` class**.
  Collapse animates *height* and flips `display` between states, which kills a transform slide;
  `collapse` would also just hide the drawer outright. At 992px+ Bootstrap's own
  `.navbar-expand-lg .navbar-collapse { display: flex !important }` still forces the desktop
  row visible, so nothing there changed.
- The drawer is never `display:none` — it is moved with `transform` + `visibility` so the
  **close** transition runs too. The backdrop is the opposite: it toggles `hidden` a frame
  either side of the fade, or a transparent scrim would eat taps while the menu is closed.
- It is `position: fixed`, and it is **moved out of `.sticky-nav` to `<body>` below 992px**
  (`syncPortal` in the NAV DRAWER block) — the backdrop rides along. A transform on the bar
  makes it a containing block, which traps the fixed drawer inside the header; guarding only
  the *open* drawer (by never applying `.nav-hidden` while it is open) left the *closed* one
  parked off-screen at `translateX(100%)` inside a transformed bar, where its box became real
  document overflow, grew Chrome's layout viewport, and made every `position: fixed` element —
  including `.mobile-footer` — size to that instead of the screen. CSS cannot fix it
  (`overflow-x: clip` on `html` does not stop the layout viewport growing). It must go back
  into the nav at 992px+: Bootstrap's `.navbar-expand-lg .navbar-collapse { display: flex }` is
  ancestry-coupled and is what restores the desktop row.
- `.nav-drawer-head` is **sticky** at the top of the scrolling drawer, and owns the drawer's
  top padding (plus `safe-area-inset-top`) so it can sit flush at `top: 0`. The member drawer
  is taller than a phone viewport, and the close button scrolling away left no visible way out —
  Escape is not a phone gesture and the scrim is a ~50px strip at 86vw.

Matches stays a **dropdown** in the drawer — it just expands in place instead of floating, under
our own `.open` class, with the caret flipping. Bootstrap's plugin owns it on desktop only: the
JS strips `data-bs-toggle="dropdown"` below 992px (the plugin is delegated on that attribute, so
removing it is enough to detach) and restores it above. The parent is a **disclosure, not a
destination** — at *either* width — so it is a `<button>`, not an anchor: Bootstrap swallows its
click on desktop and the drawer preventDefaults it on mobile, which left its old `href` (a
duplicate of the "All Profiles" child) unreachable everywhere. It starts expanded when the member
is already inside Matches.

**`$in_matches` is defined twice — in `header.php` and in `footer2.php` — and the two lists must
match.** They are what each surface means by "inside Matches". They drifted once: the old
`details.php` was in the mobile list but not the desktop one, so the same page lit the tab bar
and nothing in the header. Change one, change the other.

Rows are full-width bands, which needs `.menus .nav-item { display: block }`: the item is a flex
*row* on desktop and would otherwise shrink-wrap the link, leaving the active pill stopping at
the end of the label. The account block flattens to always-open, and its "Account" kicker is a generated
`::before` — which is why `.menus .profile-dropdown` must be `display: block` there; left as
the desktop flex row, the kicker becomes a sibling column and shoves the block off-screen.

### The back / prev / next bar

`assets/includes/pagenav.php` renders one chrome strip under the navbar:
`[< Back] — page title — [< Prev] [Next >]`. It is called from `header.php`, so
**no page includes it and no page can opt out by forgetting** — `page_nav()` renders nothing
for a page that is not in `page_nav_map()`. `index.php` and `dashboard.php` are absent from
that map on purpose: they are the two home pages and have no parent.

**It is rendered INSIDE `<section class="nav-w100 sticky-nav">`, not after `</header>`.** Back
and Next matter most on a long page, which is exactly where they used to be scrolled off the
top; in there they inherit the bar's whole behaviour — sticky at `top: 0`, hidden on scroll
down, revealed on scroll up — with no second sticky element and no z-index race. So the bar
is `background: transparent` (`.nav-w100` is the opaque surface, and it turns frosted in
`.menu-fixed`) with a `border-top` hairline dividing it from the nav row. Nothing else moved:
the drawer and its backdrop are still portalled out to `<body>` below 992px.

The chrome is `--pagenav-h` (53px) taller on those pages, so **`body:has(.page-nav)` bumps
`--offset-sticky`** to `calc(var(--offset-nav) + var(--pagenav-h))` — otherwise `.filter-card` /
`.search-rail` stick underneath it. Without `:has()` a browser keeps the plain navbar offset;
degraded, not broken.

- **Back** is the page's *parent* (its section), never "wherever you came from" — but the
  PAGE BACK BUTTON block in `custom.js` upgrades it to `history.back()` when the referrer is
  same-origin, so scroll position survives and the history stack doesn't grow. The `href` is
  the fallback for a deep link, a new tab or no JS, so it always leads somewhere real.
- **Prev / Next** walk *siblings* — and only where a real sequence exists: the six wizard steps
  and the three Matches pages. A page with no sibling in a direction omits that button; they are
  never rendered disabled.
  **`package.php`, `contact.php` and `privacy.php` carry Back only.** They used to be chained
  `package → contact → privacy`, but pricing, a contact form and a legal page are not a sequence —
  "Next: Contact" on the pricing page promised a step that doesn't exist. Don't re-chain them.
  Likewise `my-profile.php` has no Next into the wizard: it pointed at step 1 while step 1 had no
  Prev back, so you could walk in and not out, and the page's own per-section Edit links are the
  real route in (each lands on the right step).
- `faq.php` is deliberately **not** in the map: it is a 301 stub to `package.php#faq`, so a
  Next pointing at it would bounce the visitor straight back.
- **`single-profile.php`'s Prev / Next are the previous / next MEMBER**, and they are *not* in
  the map — they depend on `?id=`. A page can override the map at runtime by setting
  `$page_nav_prev` / `$page_nav_next` / `$page_nav_title` before including `header.php`, in the
  same `[url, label]` shape; `null` explicitly drops a mapped button. Keep per-request values
  out of `page_nav_map()` — the map is what the site's structure *is*.
  The sequence comes from `assets/includes/profiles-data.php` (`profile_neighbours()`), which
  `all-profiles.php` also lists from — "next profile" only means anything if the listed order
  and the walked order are one array. **It does not wrap**: the first has no Prev, the last no
  Next, and an unknown id leaves both null so the bar falls back to Back only.
- Below 768px the title and the "to `<parent>`" tail are hidden and the steps become
  arrow-only (their labels survive on `aria-label`) — three text blocks do not fit 360px.

Add a page by adding a row to the map. Nothing else needs touching.

### The preloader

The load curtain (`#preloader`) is the first thing in `header.php`, so all 31 pages get it from
the one include. Logo + two breathing rings + an indeterminate bar; `custom.js` fades it out on
`window.load` and then **removes it from the DOM**, so it can never sit invisibly over the page
eating clicks.

Three things about it are load-bearing — don't "tidy" them away:

- The preloader block in `custom.js` is **outside the `onReady` block and first in the file**,
  and uses plain DOM. If it sat below other code, a JS error would leave the entire site behind
  a permanent curtain. (It predates the jQuery removal and was already written this way — that
  is exactly why dropping jQuery could not break it.)
- On top of that, `.preloader` carries a **CSS failsafe**: an 8s-delayed animation that hides it
  regardless of whether any JS ran at all. Keep it.
- Nothing on this screen may use `--font-accent` (Great Vibes) or any webfont-dependent styling.
  The curtain is up *precisely while the fonts are still downloading*, so Great Vibes resolves to
  its generic `cursive` fallback — Comic Sans. It must look right in an unloaded font.

`--z-preloader` (10000) sits above `--z-dropdown`, so the curtain also covers the mobile tab bar.

## URLs are extensionless — `.htaccess` owns routing

**The site serves `/search`, not `/search.php`**, and 301s the `.php` form to it.
`.htaccess` at the web root does all of it, and hardcodes no path — it reads the
install directory out of the request, so the same file works under XAMPP at
`/Projects/work/active/Matrimony/matrimony/` and at a domain root.

Four rewrite rules, and all four are needed:

1. `/index.php` and `/index` → **301 → the directory itself**. Otherwise the
   landing page has three URLs.
2. Any `*.php` a client asked for → **301 → the extensionless form.** Matched on
   `THE_REQUEST` (the raw request line), *not* the URI — on the URI it would also
   match rule 3's internal rewrite and loop forever.
3. `/search` → **internally serves `search.php`**, guarded on the target existing
   so real assets and directories are untouched.
4. Anything left — not a file, not a directory, no `.php` behind it →
   **internally serves `404.php`**.

**Rule 4 is a rewrite, not `ErrorDocument`, and `404.php` sets its own status.**
`ErrorDocument` needs a root-relative path, and Apache treats a value that doesn't
start with `/` as a literal message string to print — so there is no path-independent
way to write it, and this file hardcodes no path. The cost of the rewrite is that an
internal rewrite **does not change the response status**: without
`http_response_code(404)` at the top of `404.php`, every missing URL would return
`200 OK` with an error page in the body. That soft 404 is worse than Apache's default
page — crawlers index the error, and a broken internal link never surfaces in any
report because every URL on the site returns success. **Don't remove that line.**

Rule 4 catches missing static assets too. It does *not* catch 403s — a directory with
no index file (`Options -Indexes`) and the dotfile `FilesMatch` block are Forbidden,
not Not Found, and never reach it. That is the right status for each.

What follows from that:

- **Write internal links extensionless**: `href="search"`, `href="package#faq"`,
  `href="./"` for home. A `.php` link still works but costs every visitor a
  redirect and points at a non-canonical URL.
- **`url()` in `auth.php` converts** `'search.php'` → `'search'`. PHP data
  structures still store the `.php` name — `page_nav_map()` keys, `$wizard_pages`,
  every `nav_active()` argument — because **`basename($_SERVER['PHP_SELF'])` still
  reports `search.php`**: the rewrite is internal, so Apache really is running
  `search.php`. Convert at output (`ee(url($item['href']))`), never in the data.
- `canonical_url()` and therefore `og:url` emit the extensionless absolute URL, so
  the link, the redirect target and the canonical tag all agree.
- A `Location:` redirect must be **absolute and extensionless** — see `faq.php`,
  which would otherwise be 301'd a second time by rule 2.

`.htaccess` also sets compression, cache headers (`immutable` for assets, which is
safe because `links.php` cache-busts with `?v=<filemtime>`; `no-store` for HTML,
because these pages are per-member once auth is real) and security headers.

**Two Apache modules are not loaded in this XAMPP** — `mod_deflate` and
`mod_expires` — so those blocks are inert locally and CSS/JS go out uncompressed.
They are `<IfModule>`-guarded, so nothing breaks; uncomment the two `LoadModule`
lines in `apache/conf/httpd.conf` to turn them on. `mod_rewrite` and `mod_headers`
*are* loaded, so routing and security headers work as shipped.

## Navigation flow

(Filenames below; the URLs they are linked as are extensionless — see above.)

- **Signup:** `index.php` → `register.php` → `profile-creation.php` → education → family →
  partner → contact-details → profile-photos → **`dashboard.php`**
- **Login:** `index.php` → `login.php` → **`dashboard.php`**, with `login.php` →
  `forgot-password.php` → back to `login.php` as the recovery detour
- **Upgrade:** any "Upgrade Now" → `package.php` → "Purchase Now" → `checkout.php`
  (`index.php`'s teaser cards stop at `package.php`; `$plan_cta_url` in
  `pricing-cards.php` is what differs)
- **Verification:** dashboard sidebar / `my-matches.php` "Add Identity Badge" →
  `verification.php`

The login and register buttons are plain `<a>` anchors so the skeleton can navigate. Restore them
to `<button type="submit">` when real handlers land.

## Page map

- `index.php` — public **marketing landing page** (hero, about, 3-steps, subscription,
  testimonials, members). Every section is `.container is-chrome` (1400px).

  **The hero.** The `.register-form` is overlaid on the `.basement-carousel` by
  `.hero-form-layer` — an absolutely positioned layer that carries the page container, so the
  form's left edge lines up with the navbar. The layer is `pointer-events: none` and only the
  form re-enables them, so the carousel arrows underneath still work. Below **992px** the layer
  goes `position: static` and the form flows *under* the banner instead of covering it.

  The form is the constraint on the hero's height: it renders ~720px, so
  `.basement-images img` is **760px** tall to hold it (420px below 992px, where they no longer
  overlap). **Add a field and you must re-check the hero at 1440** — if the form outgrows the
  banner it hangs over the navbar. That is why the field labels for first name / email /
  password are `.visually-hidden` (the placeholder already says the word) and the rhythm is
  tight (12px between groups, 42px controls).

  *Do not* put `overflow-x: hidden` on `.matrimony-slides` to chase a phantom scrollbar: with
  `overflow-y` left visible the browser computes y to `auto`, and the overlaid form gets
  clipped against the banner.
- `dashboard.php` — logged-in **dashboard** (sidebar, Daily Recommendations slider,
  All Matches slider)

(There used to be a `home.php` landing page while `index.php` held the dashboard. That was
renamed: `home.php` → `index.php`, and the old `index.php` → `dashboard.php`.)

- **Auth** — `login.php`, `register.php`
- **Profile-creation wizard** — six pages that cross-link to each other as steps:
  `profile-creation.php` → `education.php` → `family.php` → `partner.php` →
  `contact-details.php` → `profile-photos.php`
- **Browse / match** — `all-profiles.php`, `my-matches.php`, `daily-matches.php`, `search.php`,
  `interest.php`, `single-profile.php` (another member's profile — see the caveat below)

  **The three "matches" pages are not interchangeable.** They used to be, and the links between
  them were wrong in both directions:

  | Page | Is | Was |
  | --- | --- | --- |
  | `all-profiles.php` | the full browsable directory | `matches.php` |
  | `my-matches.php` | this member's own funnel (Latest / Yet to be Viewed / …) + Interest Received | `profile.php`, whose `<h1>` said "My Profile" |
  | `my-profile.php` | the member's own profile — unchanged | — |

  `profile.php` was a near-duplicate of `matches.php` with a heading that claimed to be
  `my-profile.php`, and nothing in the nav reached it. Both are now in the header Matches
  dropdown, the footer Explore column and the mobile sheet. When adding a link, pick by what
  the label promises: *browse everyone* → `all-profiles.php`, *my funnel* → `my-matches.php`,
  *my own profile* → `my-profile.php`.

  **`single-profile.php` is the ONLY member profile view — don't add a second one.**
  There used to be two. `details.php` was a near-duplicate ("Krishna Priya TS", "Do you like
  her?") with the *better* data layout — a tabbed Personal Information / Partner Preference
  panel of grouped `.dtl-basic-info` blocks (Basic / Contact / Professional / Religious) —
  but the *worse* page: no real `<h1>`, no Similar Profiles, and exactly one inbound link, the
  mislabelled Success Stories card on `search.php`. That tab panel has been merged into
  `single-profile.php` and `details.php` is deleted.

  Two bugs were fixed in the merge, don't reintroduce either:

  - The panel's **Partner Preference tab was a byte-copy of the Personal Information tab** — in
    *both* pages. `details.php` literally annotated it "SAME CONTENT". It now carries the fields
    the wizard actually collects on `partner.php` (age/height range, marital + physical status,
    religion/caste, education, occupation, income).
  - `single-profile.php`'s old flat 25-row `<table>` **repeated Raasi / Horoscope / Religion /
    Employment twice** and had no headings.

  **The Success Stories cards link to `success-stories.php`.** All three (`search.php`,
  `my-matches.php`, the footer's "Wedding Success Stories") were deliberately unlinked for a
  long time — a wedding-stories card that dumped you inside a stranger's profile was the
  original bug — and stayed that way until a real page existed. It does now.

- **Member features** — `messages.php`, `notifications.php`, `verification.php`

  These three are **layouts, not features**. Every control is inert and says so in a
  `TODO(backend):`. Build against these shells rather than inventing new ones.

  - `messages.php` is a two-pane inbox inside the standard 3/6/3: conversation list in the
    left rail, open thread in the centre. It is **not** a 3/9 page and must not become one.
    Conversations are `?thread=` on the one page. Below 992px the panes stack — the real
    page should show one at a time.
  - `notifications.php` restored **both header bells to anchors**. They were
    `<button disabled>` only because the page did not exist. The unread count is hardcoded
    `3` in *both* bells — keep them in step.
  - `verification.php`'s upload form has `enctype` and a file input but **no handler**. Read
    the block at the top of that file before wiring it: documents must be validated
    server-side and stored **outside the web root**.

- **Static** — `package.php` (membership tiers), `contact.php`, `faq.php`, `privacy.php`,
  `terms.php`, `about.php`, `branches.php`, `success-stories.php`

  `404.php` is the not-found page, reached by `.htaccess` rewrite rule 4 (above). It is
  **not flagged `$is_public`** — deliberately: the flag means "render the logged-out
  chrome", and a member who mistypes a URL should keep their own navigation. So the
  header, footer and its own CTA/link list all branch on the real `is_logged_in()`.
  It is absent from `page_nav_map()` (no parent), and carries
  `$page_robots = 'noindex, nofollow'` in its own right — an error page must stay
  unindexed even after `SITE_LIVE` flips.

  `terms.php` and `privacy.php` are a **pair** — same `.privacy-*` classes, same shell, and
  the only prev/next chain among the static pages. Change one, look at the other.
  **`terms.php` is placeholder copy, not reviewed legal text** (`TODO(backend):`).

  `success-stories.php` reads `assets/includes/stories-data.php`, which `single-profile.php`'s
  `.stories` rail also reads — the rail was three byte-identical "Allen & Riya" slides before.
  Each card links to that couple's anchor on the page, so no per-couple page exists.

### The two pages that carry a `.demo-banner`

`checkout.php` and `forgot-password.php` render a loud amber banner saying the page does not
work. **Do not remove it until the real flow exists**, and do not reuse `.demo-banner` as a
general notice component — the moment it appears on a page that works, it stops being believed
on the two where it matters.

They are the two pages where a convincing-looking form is actively harmful, so:

- **`checkout.php` has NO card fields at all** — not disabled ones, not placeholders. A card
  input on an unwired page is a phishing form with good intentions, and the real integration
  hands off to the gateway's own hosted page anyway. Its "Continue to payment" button is
  genuinely `disabled`. Never take the amount from the client; `?plan=` selects a **key**.
- **`forgot-password.php` sends nothing.** The block at the top of it lists what the real flow
  needs — identical responses whether or not the address exists (enumeration), hashed
  single-use tokens, and a **hardcoded** domain for the reset URL rather than `site_url()`,
  which trusts the client's Host header.

## Stack

**Three dependencies, all CDN:** Bootstrap 5.3.2, Swiper 11, Font Awesome 4.7 —
plus Google Fonts (Manrope + Plus Jakarta Sans + Great Vibes, these three only).

**Six libraries were removed, and `assets/js/` now holds only `custom.js`.** All six
were loaded on every page; five of them did nothing at all:

| Removed | Why |
| --- | --- |
| jQuery 3.7.1 (85KB) | its last use was the `$(function(){…})` ready wrapper — now `onReady()` in custom.js. Bootstrap 5 never needed it, and no page has an inline script |
| Owl Carousel (JS + 2 CSS) | Swiper does the same job and was already loaded alongside it |
| AOS (JS + CSS) | initialised on every page; **no element has ever carried a `data-aos` attribute** |
| WOW.js | never initialised, no `.wow` element exists |
| animate.css (76KB) | no `.animate__*` class anywhere |
| Magnific Popup (JS + CSS) | never initialised, no lightbox on the site |
| local `bootstrap.min.css` / `bootstrap.bundle.min.js` | unreferenced duplicates of the CDN copies |

Before adding a library, check something uses it. To bring AOS back: re-add the two
tags and call `AOS.init()` at the top of the `onReady` block.

**Font Awesome is still 4.7 (2016, unmaintained)** — deliberately. Upgrading to v6
renames every icon class site-wide (`fa fa-heart` → `fa-solid fa-heart`), so it is
its own job, not a drive-by. `TODO(backend):` in `script.php`.

### `links.php` order is load-bearing: vendor CSS first, ours last

It used to be interleaved, with `style.css` above `swiper-bundle.min.css` and Font
Awesome — so our own rules lost to the vendor's at equal specificity and were won
back with extra qualifiers and `!important`. **Adding a class to a selector purely
to outrank a CDN file is a workaround for load order.** Vendor first, ours last,
and specificity means what it says.

### Carousels: Swiper only, and two rules

The site used to ship two carousel libraries. Everything is Swiper now
(`.basement-carousel`, `.testimonial-carousel`, `.ad-oppam`, `.stories`,
`.match-slider`). See the CAROUSELS block in `custom.js`:

1. **Init per element, never per selector string.** `.ad-oppam` appears twice on
   `search.php` and `.match-slider` twice on `dashboard.php`. Use `eachSwiper()`.
2. **Scope pagination/navigation to the container.** A bare `'.swiper-pagination'`
   resolves to the first one in the document, so two carousels would drive the same
   dots.

And one CSS rule you must not delete: **`.swiper { width: 100%; min-width: 0 }`.**
Swiper sizes slides from the container; a container that sizes itself from its
content makes the two chase each other. `.ad-oppam` on `search.php` is woven into
`.profile-grid` (`display: grid`) below 992px, and a grid item defaults to
`min-width: auto` — at 360px the container measured **33,554,432px**, Chrome's
ceiling, and the page scrolled sideways forever. For the same reason, don't lift
Swiper's `overflow: hidden` off a carousel that has no hover-lift to rescue; only
`.testimonial-carousel` gets the `overflow-x: clip` treatment.

The hero's nav arrows and dots are **built from the slides themselves** —
`renderBullet()` reads each slide's `<img>` and `<h2>`. The old markup carried a
`data-dot="<img src=…>"` attribute repeating every slide's own image, and the dot
tooltips were a second hardcoded array in the JS. A fifth slide now needs no JS or
attribute change.

- **CSS** — `assets/css/style.css` (~7.3k lines) and `assets/css/responsive.css` (~3k lines).
  `links.php` appends a `?v=<filemtime>` cache-buster to both, so an edit is never masked by a
  stale cached stylesheet.
- **JS** — all custom JS is in the single `assets/js/custom.js`: `onReady()`, Swiper inits,
  a countdown timer, sticky-nav scroll handler, nav drawer, mobile rail/weave,
  profile-dropdown toggle, counter animations, page back button
- **Images** — `.webp`/`.jpg` under `assets/images/<page>/` (banner, home, profile, search,
  matches, family, login, reg, subscription, details, about, logo).

  **Every `<img>` carries real `width`/`height` and a loading strategy.** The
  dimensions are the file's true pixel size (they reserve the box and kill layout
  shift, so a wrong ratio is worse than none); `loading="lazy"` everywhere except
  the handful of above-the-fold images, which take `fetchpriority="high"`.
  **Re-encode an image and you must re-sync its attributes.**

  Twelve served images were recompressed/downscaled: **4.7MB → 757KB**. Notably
  `banner1.webp` (the LCP image) was 1MB at the same dimensions as an 85KB sibling,
  `profile/couple.webp` was 6000×6000 (~144MB decoded) for a ~500px card, and the
  navbar logo was 3000px wide and decoded on every page. Originals are not in the
  repo — **`about/couples.jpg` (9.5MB, 3648×5472) is still on disk and still
  unreferenced**; the band uses the 56KB `couples-band-1600.webp` crop.

## Design basis — `dashboard.php`

**`dashboard.php` is the canonical design.** New pages and any restyling follow it.

Layout: `.dashboard-section` → `.container` → `.dashboard-row` (a **3 / 6 / 3** split:
sidebar | content | ads rail). `all-profiles`, `my-matches`, `interest` and `search` all follow
it. **There is no 3/9 outlier any more** — `profile.php` was the last one, and it became
`my-matches.php` at 3/6/3 with `ads.php` in the right rail. Don't reintroduce a 3/9 page.

One thing that moved with it: `my-matches.php`'s `.counter-section` funnel bar now sits in the
**col-6** centre column, so its four counters are `col-lg-6` (**2 × 2**), not `col-lg-3` across.
Four abreast in half the width crushed the labels.

- **Left rail (col-lg-3)** — a card panel. On the dashboard that's
  `.sidebar-profile` → `.membership-box` → `.sidebar-menu`. Other pages keep their **own** left
  content (all-profiles = browse/activity nav, my-matches = funnel nav + Interest Received,
  search = Success Stories, interest = filters) — only the *surface* is shared.
- **Centre column (col-lg-6)** — a stack of `.dashboard-content` cards, each:
  `.content-header` (title + subtitle, optional meta chip) → content
  (Swiper `.match-slider` of `.profile-card`, or a `.profiles` result list) → `.content-btn` ("View All").
- **Right rail (col-lg-3)** — `assets/includes/ads.php` (`.ad-unit`, the Go Premium panel).

### The card surface (one shell, one place)

All panels share a single surface built from tokens — `--radius-card`, `--pad-card`,
`--shadow-card`. The shared rule lists the panels by selector:

```css
.dashboard-sidebar, .side-bar, .success-stories { /* white card shell */ }
```

**Add a new panel to that selector list rather than inventing another shell.** The content cards
(`.home-content`), profile tiles (`.profile-card`) and result rows (`.profiles`) use the same
radius/shadow tokens.

Exception: `.all-matches` is a deliberate **brand-accent** panel (white text on `--color-primary`),
so it keeps its red fill but shares the card geometry — like `.counter-section`.

## Containers — fluid by default, with two opt-in caps

`style.css` loads **after** Bootstrap and overrides `.container` (and every `.container-*`) to be
full-bleed, with a gutter that grows with the viewport. So **no page markup needs
`.container-fluid`** — plain `.container` is already full width.

Three widths, all token-driven:

| Class | Width | Used by |
| --- | --- | --- |
| `.container` | `--container-max` — **100%**, full-bleed | Page content: dashboard, matches, interest, search (3-column layouts that earn the width) |
| `.container is-chrome` | `--container-chrome` — **1400px** | **`header.php` + `footer.php`** — the nav and footer columns read better held together than flung to the screen edges — and **every section of `index.php`**, so the landing page lines up with the nav above it. A marketing page full-bleed at 1920 just pushes its own content apart |
| `.container is-readable` | `--container-readable` — **1200px** | Content/form pages: `contact`, `register`, `login`, `faq`, `privacy` and all six wizard steps. A form input stretched to 1800px is unusable. (`package` stays full-bleed — its 3-up pricing grid earns the width.) |

Change a width by editing the token, not by reintroducing Bootstrap's max-widths.

## Design tokens

Defined in `:root` at the top of `style.css`, grouped by role. **Use the variables — don't
hardcode values.** If the value you need has no token, *add one there* rather than inventing a
one-off. The main groups:

| Group | Tokens |
| --- | --- |
| Typefaces | `--font-primary` + `--font-secondary` (**Manrope**), `--font-display` (**Plus Jakarta Sans**), `--font-accent` (**Great Vibes**, script) |
| Brand | `--color-primary` `#e02349`, `--color-primary-rgb`, `--color-primary-dark`, `--color-secondary` `#0d9ab8`, `--color-secondary-dark`, `--color-back`, `--color-bltxt`, `--color-gr-dark` |
| Backgrounds | `--bg-rose` `--bg-teal` `--bg-sand` `--bg-mist` |
| Spacing | `--space-1`…`--space-11` (4px base: 4, 8, 12, 16, 20, 24, 32, 40, 48, 64, 80) |
| Layout | `--container-max`, `--container-gutter`, `--gutter-col`, `--pad-section`, `--pad-section-sm`, `--offset-sticky` |
| Radii | `--radius-xs/sm/md/lg`, `--radius-card` (20px), `--radius-pill` (50px), `--radius-circle` |
| Borders | `--border-color`, `--border-hair`, `--border-card` |
| Shadows | `--shadow-xs/card/md/lg`, `--shadow-brand`, `--ring-focus` |
| Card surface | `--pad-card` (15px), `--pad-card-sm` (20px, narrow rails) |
| Type scale | `--text-xs` 12 → `--text-3xl` 40, plus `--text-hero` (clamp) |
| Weights | `--fw-regular/medium/semibold/bold` |
| Line heights | `--leading-tight` (headings) `--leading-snug` `--leading-normal` (body) |
| Motion | `--transition-fast` `--transition` `--transition-slow` |
| Layers | `--z-sticky` (999), `--z-dropdown` (9999) |

**Accessibility notes baked into the palette:** use `--color-secondary-dark` behind white text
(plain teal is only 3.3:1) and `--color-gr-dark` for muted *text* (`--color-gr` is only 3.75:1
on white).

### Typography base + shared text roles

`h1`–`h6`, `body` and links now have a base style from the type scale, so an unstyled heading is
never left at a browser default. Four shared roles exist — reuse them instead of restyling:

- `.eyebrow` — small uppercase kicker above a section title
- `.display-title` — the `--font-display` accent for marketing headings
- `.script-accent` — the Great Vibes script. **Decorative only, and rare**: never body copy,
  labels, buttons or counts; never below ~24px. It lives at the END of `style.css` on purpose —
  as a bare class it loses to element-qualified rules, so it wins on cascade order instead of
  `!important`. Don't move it up.
- `.text-muted-brand` — captions, meta lines, helper text
- `.chip` — pill for membership tier, counts, status

### `--offset-sticky`

Sticky rails (`.filter-card`, `.search-rail`) must clear the sticky navbar. Use
`top: var(--offset-sticky)`, not a magic number.

**The subscription band is the one section with a background photo.**
`.subscription-section::before` layers `about/couples-band-1600.webp` under a **neutral
black scrim at .55**. The band is no longer teal — the scrim is deliberately colourless so
the photo keeps its own colour, and it is not decoration: it is the contrast layer holding
the white headline and copy above 4.5:1, so **don't lower the alpha** or they start failing
AA over the photo's bright green highlights. The section's `background-color` (`#1c2529`)
is only ever seen before the image decodes — a tint there would flash a different band on
load. The scrim is on `::before`, not on the element, so a future `background` shorthand
can't silently drop it (the way it would have wiped `pattern-22`). The 9.8 MB
`about/couples.jpg` it was cropped from is NOT the file to reference.

**Otherwise: no decorative background images on page/section backgrounds.** `details/01.png` and
`subscription/sub-back.jpg` were removed in favour of the `--bg-*` tints; don't reintroduce them.
Page and section surfaces are flat tints. (The image files still exist on disk, just unreferenced.)

**The one exception — `home/pattern-22.png` on the large red panels.** It is a 1170×226 PNG that
is ~90% transparent, and its visible pixels are white at 4–14/255 alpha: a soft light *wash*, not
a graphic. It layers over `--color-primary` with no blend mode, leaving the fill intact (only
`background-image` is set). One rule at the **end** of `style.css` applies it to:

`.counter-section`, `.advertisement-section`, `.all-matches`, `.ad-promo`, `.pricing-card.featured`

Keep it to panels that size. Buttons, chips, count badges and the NEWLY JOINED flag stay flat —
the texture crops to noise at small sizes. The rule must stay last in the file:
`.pricing-card.featured` sets `background` (shorthand), which resets `background-image` and would
wipe the texture if the rule came earlier.

Two sections are solid brand-colour accent bands with white text: `.counter-section`
(`--color-primary`) and `.subscription-section` (`--color-secondary-dark`).

A few `rgba()` shadows/overlays and one orange gradient (`#f89a20` / `#ff4338`) are still
hardcoded — no tokens exist for them yet.

## Accessibility rules that are now enforced

These were all violated somewhere and have been fixed; don't reintroduce them.

- **Contrast.** `--color-secondary` (teal) is **3.32:1** on white and `--color-gr` is **3.75:1** —
  both fail WCAG AA for normal text. Use `--color-secondary-dark` (4.86:1) and `--color-gr-dark`
  (5.17:1) for *text* and for any fill that carries white text. The light tokens are fine for
  borders, backgrounds and large decorative type.
- **One `<h1>` per page.** Every page has exactly one. Card/section titles are `<h2>`+. Where a
  design has no visible page title, the page carries an `<h1 class="visually-hidden">`.
  A counter *value* is not a heading.
- **Every `<img>` has an `alt`** (`alt=""` when decorative — e.g. a photo whose subject's name is
  already the adjacent heading).
- **Every form control has a `name`, an `id` and a real `<label for=...>`** (or an `aria-label`
  for grouped controls like "Age: from / to"). Without `name`, `$_POST` arrives empty — the
  wizard had none at all.
- **Nothing is an `<a>` inside an `<a>`.** A row is made clickable with `.stretched-link` on its
  title, never by wrapping the card in an anchor — the parser re-parents the card and it ends up
  rendering on top of the footer.
- **Every page wraps its content in `<main id="main" tabindex="-1">`**, between the `header.php`
  and `footer.php` includes — and `header.php` emits a `.skip-link` to it as the first element in
  the body. Without the wrapper the skip link lands nowhere. `tabindex="-1"` is what makes the
  target actually take focus rather than just moving the caret. With a navbar, a page-nav bar and
  a five-tab bar, a keyboard user otherwise tabs ~15 controls before reaching content.
- **A control that goes nowhere is a `<button>`, not `<a href="#">`.** Both notification bells were
  disabled buttons for as long as no notifications page existed — an anchor announced a destination
  that wasn't there. `notifications.php` shipped and they are anchors again. The rule stands for
  the next one: don't link to a page you haven't built. What remains inert is inert on purpose —
  the interest/shortlist actions in `profile-row.php`, the sort control on `all-profiles.php`, the
  filter rails on `interest.php` and `notifications.php`, and `checkout.php`'s payment button.
- **A disclosure is a `<button>`, not an anchor.** The Matches nav parent (`#navMatches`) toggles a
  menu at every width — Bootstrap swallows its click on desktop, the drawer preventDefaults it on
  mobile — so an `href` on it was permanently unreachable. Note `.menus button.head-link` needs an
  explicit `width: 100%` in the drawer: a button shrink-wraps its content even as a flex container,
  so without it the row's pill and tap target stop at the end of the word.
- **The mobile drawer is modal below 992px.** `custom.js` sets `role="dialog"` + `aria-modal` while
  open (and removes them on close — left on, they'd tell desktop AT that the ordinary nav row is a
  dialog), traps Tab at both ends, and moves focus inside on open. That focus call is on a
  **320ms timer, not rAF**: `focus()` is silently dropped while the drawer's visibility transition
  is still running, even though the computed value already reads `visible`. `transitionend` is not
  used because under `prefers-reduced-motion` there is no transition to end.

## Verification harness

`assets/css` changes are easy to break silently. Screenshot the site with **CDP device emulation**,
not `chrome --window-size`: Chrome clamps its headless window to a **512px minimum width** on
Windows, so `--window-size=360` renders at 512 and merely crops the PNG — every "mobile"
screenshot is a lie. Drive `Emulation.setDeviceMetricsOverride` over the DevTools protocol and
assert `document.scrollWidth <= clientWidth` at 360/414 to catch horizontal overflow.

Two things that will waste your time:

- XAMPP **301-redirects `localhost` to HTTPS** with a self-signed cert. Without
  `--ignore-certificate-errors` every screenshot is Chrome's "Your connection is not private"
  interstitial — and it passes the overflow assertion, because the interstitial doesn't overflow.
- **Row gutters larger than the container gutter overflow the page.** `--container-gutter` is
  `clamp(16px, 2.5vw, 48px)`, so at 360px it is **16px** — and a Bootstrap `g-5` row applies a
  **-24px** negative margin, which punches 8px past the container and gives every phone a
  horizontal scrollbar. Cap rows inside `.container` at **`g-4`** (-12px).
