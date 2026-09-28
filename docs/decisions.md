# Decisions Log

Record every product/technical decision the PRD didn't settle, or that changes the PRD.
Newest first. Update the PRD section too when a decision changes it.

| Date | Decision | Why | PRD section affected | Decided by |
|---|---|---|---|---|
| 2026-09-27 | **Public site and broker portal are pinned to `config('oppam.app_domain')`**; admin to `oppam.admin_domain`; routes registered admin-first; `/up` stays host-agnostic | Without a host constraint every public route also answered on the admin domain and on any Host header (found by a test in P0.1) | §5, §13 | Build (P0.1) |
| 2026-09-27 | **Package routes locked down**: `Fortify::ignoreRoutes()` + `Passkeys::ignoreRoutes()` (Fortify is only for admin 2FA, P0.5); Dusk excluded from auto-discovery and registered only in `local`/`testing` (its `/_dusk/login/{id}` logs in as anyone in every non-production env); Horizon + Pulse dashboards moved to the admin domain; generic `/storage/{path}` serving disabled (`local` disk `serve => false`) | Package auto-discovery exposed ~50 unplanned routes on a fresh install; guarded by `tests/Feature/Foundation/RouteExposureTest.php` | §8, §13, CLAUDE.md security rule 7 | Build (P0.1) |
| 2026-09-27 | **Livewire 4** (4.4.x) instead of Livewire 3 | Current major with the longest support; class-based component syntax used in the PRD/skills is unchanged. Wherever the PRD says "Livewire 3", read "Livewire 4" | §4, §5, all module component notes | Product owner |
| 2026-09-27 | **Pest Livewire plugin not installed** | `pestphp/pest-plugin-livewire` 3.x only supports Livewire 3; `Livewire::test()` works directly in Pest | Testing (§16) | Build (P0.1) |
| 2026-09-27 | **Dev environment = Windows + XAMPP** (PHP 8.2.12, MariaDB 10.4) instead of WSL2 + Sail. Production target unchanged (PHP 8.3, MySQL 8, Redis 7). CI (GitHub Actions) runs on Linux with **MySQL 8 + Redis** to catch MariaDB-only behaviour | Docker not installed; owner chose XAMPP | §4, §15, §17 | Product owner |
| 2026-09-27 | `composer.json` `config.platform`: `php 8.2.12`, fake `ext-pcntl`/`ext-posix` | Lock file must resolve to versions that run on local PHP 8.2; Horizon needs pcntl/posix, which only exist on the Linux servers | §4 | Build (P0.1) |
| 2026-09-27 | **No Redis locally**: `CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER` = `database`; production = `redis`. Horizon runs in production only; locally `php artisan queue:listen` (inside `composer dev`) works the same queue names | No Redis/pcntl on Windows | §4, §15 | Build (P0.1) |
| 2026-09-27 | **Cache invalidation uses version keys, not cache tags** (e.g. `masters:version` bumped on write, keys include the version) | The `database` cache store has no tag support; version keys behave identically on Redis, so one code path works everywhere | §11 A11 (master-data cache flush) | Build (P0.1) |
| 2026-09-27 | **Tailwind removed** from the Laravel skeleton (packages, Vite plugin, app.css) | The project keeps the template's Bootstrap 5.3 + design tokens (oppam-ui-standards) | §4 | Build (P0.1) |
| 2026-09-27 | PowerShell + `composer.bat` strips `^` from constraints — run composer with constraints from Git Bash (or use `~`) | Livewire was silently pinned to vulnerable 4.0.0 (CVE-2026-81887) until fixed | — (CLAUDE.md "Windows gotchas") | Build (P0.1) |
| 2026-09-27 | Broker bulk upload and staff accounts are in v1 | Bureaus need both on day one | §11A (reverses v4 R-M13-17) | Product owner |
