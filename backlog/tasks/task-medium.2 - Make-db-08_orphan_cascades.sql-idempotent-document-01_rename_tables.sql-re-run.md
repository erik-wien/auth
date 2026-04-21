---
id: TASK-MEDIUM.2
title: >-
  Make db/08_orphan_cascades.sql idempotent; document 01_rename_tables.sql
  re-run
status: Done
assignee: []
created_date: '2026-04-21 05:15'
updated_date: '2026-04-21 08:09'
labels: []
dependencies: []
parent_task_id: TASK-MEDIUM
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Audit 2026-04-20: auth/db/08_orphan_cascades.sql uses ADD CONSTRAINT without guards — re-running fails with 'Duplicate foreign key constraint name'. auth-rules §6 requires idempotence where feasible, or a header comment explaining safe re-run. Fix either by adding DROP FOREIGN KEY IF EXISTS before ADD CONSTRAINT, or prepending a header comment documenting the re-run procedure. Same check for 01_rename_tables.sql which uses non-guarded RENAME TABLE.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 08_orphan_cascades.sql either guards or documents re-run
- [x] #2 01_rename_tables.sql documents re-run
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Small hardening task on two migration files.

1. `db/08_orphan_cascades.sql`:
   - For each `ALTER TABLE … ADD CONSTRAINT fk_name …` statement, prepend `ALTER TABLE … DROP FOREIGN KEY IF EXISTS fk_name;` (MariaDB supports `IF EXISTS` on `DROP FOREIGN KEY` since 10.4).
   - Wrap the whole file so it's re-run-safe. Test locally: `mariadb -uroot auth < db/08_orphan_cascades.sql` twice — second run must succeed without error.

2. `db/01_rename_tables.sql`:
   - `RENAME TABLE` isn't idempotent. Add a header comment block:
     ```sql
     -- NOT idempotent. Re-run procedure:
     -- 1. Check whether rename already applied: SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='auth' AND TABLE_NAME IN ('<new>', '<old>');
     -- 2. If only <new> exists, this migration already ran — skip.
     -- 3. If only <old> exists, run file as-is.
     -- 4. If both exist, the rename was partial — investigate before re-running.
     ```
   - Alternative: rewrite with conditional logic via prepared statements reading `information_schema`, but the comment is simpler and matches auth-rules §6 ("document re-run when idempotence isn't feasible").

3. Smoke-test: apply both migrations fresh to a scratch DB, then re-apply — must succeed.

No code changes elsewhere. Update `auth/CLAUDE.md`'s migrations note if it claims all migrations are idempotent.
<!-- SECTION:PLAN:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
08_orphan_cascades.sql: prepended DROP FOREIGN KEY IF EXISTS before each ADD CONSTRAINT — idempotent, verified by applying twice locally. 01_rename_tables.sql: added header comment with 4-step re-run procedure per auth-rules §6.
<!-- SECTION:FINAL_SUMMARY:END -->
