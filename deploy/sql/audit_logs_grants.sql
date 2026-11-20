-- Oppam Matrimony: audit_logs is append-only at the database grant level (PRD A12, P9.2a).
--
-- The application user may only SELECT and INSERT on audit_logs. It never gets UPDATE or DELETE
-- there, and it never gets a database-wide grant (`oppam`.*), because MySQL can't take a single
-- table out of a database-wide grant. The per-table script is GENERATED from the live table list:
--
--   php artisan audit:grants --user=oppam_app --host='10.0.%' > /tmp/grants.sql
--   mysql -u <db admin> -p oppam < /tmp/grants.sql
--
-- Re-run both lines after every `migrate --force` (new tables need their grant). Migrations run as
-- a separate migration user with DDL rights (DB_USERNAME for `migrate` only, P9.5 deploy script).
-- Then check, connected as the application user:
--
--   php artisan audit:grants --check      # exit 1 if UPDATE / DELETE is possible or a trigger is missing
--
-- The shape of the generated script (database `oppam`, user 'oppam_app'@'10.0.%'):

REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'oppam_app'@'10.0.%';
GRANT SELECT, INSERT ON `oppam`.`audit_logs` TO 'oppam_app'@'10.0.%';
-- … one line per other table:
-- GRANT SELECT, INSERT, UPDATE, DELETE ON `oppam`.`<table>` TO 'oppam_app'@'10.0.%';
--
-- 7-year retention (owner, 2026-11-20): the app never deletes audit rows. The yearly archive of rows
-- older than 7 years (P9.5) runs as a database admin, which keeps DELETE and has to drop the
-- triggers for the purge window. Every run is recorded in the runbook.
