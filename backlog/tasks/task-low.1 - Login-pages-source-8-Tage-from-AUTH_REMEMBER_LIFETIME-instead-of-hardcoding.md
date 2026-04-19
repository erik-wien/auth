---
id: TASK-LOW.1
title: 'Login pages: source ''8 Tage'' from AUTH_REMEMBER_LIFETIME instead of hardcoding'
status: Done
assignee: []
created_date: '2026-04-19 06:41'
updated_date: '2026-04-19 12:15'
labels: []
dependencies: []
parent_task_id: TASK-LOW
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
auth-rules §9 (added 2026-04-19) requires the '(N Tage)' label on every login.php to be rendered from AUTH_REMEMBER_LIFETIME/86400 rather than a literal '8'. Currently all 5 apps ship `Angemeldet bleiben (8 Tage)` as a string constant; if the library constant ever changes, the UI drifts silently.

Scope — one edit per app, identical pattern:

    Angemeldet bleiben (<?= (int) (AUTH_REMEMBER_LIFETIME / 86400) ?>&nbsp;Tage)

Apps to update:
- Energie/web/login.php
- wlmonitor/web/login.php
- zeiterfassung/web/login.php
- simplechat/web/login.php
- suche/web/login.php

Verification: grep -rn 'Angemeldet bleiben' across all 5 /web/ directories; no literal '8&nbsp;Tage' or '(8 Tage)' remaining.

Filed in auth/ because the constant (and therefore the contract) lives here; the edits touch consumer apps but the change exists to keep consumers honest about the library.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 No login.php in any of the 5 apps contains a hardcoded '8 Tage' literal — all render from AUTH_REMEMBER_LIFETIME
- [x] #2 Rendered value matches AUTH_REMEMBER_LIFETIME/86400 on all 5 apps
- [x] #3 Temporary test: change AUTH_REMEMBER_LIFETIME to 9 days locally, confirm every login page shows '9 Tage', revert
<!-- AC:END -->
