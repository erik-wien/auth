---
id: TASK-MEDIUM.1
title: >-
  Document consumer integration rules in CLAUDE.md (companion pages, DB grants,
  return shapes, APP_BASE_URL)
status: To Do
assignee: []
created_date: '2026-04-19 11:57'
labels: []
dependencies: []
parent_task_id: TASK-MEDIUM
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Four integration pitfalls surfaced during zeiterfassung TASK-1 / TASK-HIGH.1 that should be documented in auth/CLAUDE.md so every consumer app knows what to check before first use of the invite/admin flows.

1. Read return shapes — auth_login() returns ok=true with totp_required=true (not ok=false). Document each non-obvious return shape in the function's docblock or a dedicated CLAUDE.md section.
2. Companion pages — admin_create_user() / admin_reset_password() send emails pointing at APP_BASE_URL/setpassword.php. Consumer apps must ship this page. Document it as a prerequisite.
3. DB grants — Users::listExtended() queries auth_invite_tokens; Dispatch::handle() for delete needs DELETE on auth_accounts. Document every table each library function touches so consumers can set grants correctly.
4. APP_BASE_URL — any email-sending flow silently sends broken links if base_url in config is stale. Document the verification step.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 CLAUDE.md lists the return shapes of auth_login, auth_totp_complete, invite_complete, admin_create_user, admin_delete_user
- [ ] #2 CLAUDE.md states that setpassword.php must exist in the consumer app before admin_create_user / admin_reset_password can be used
- [ ] #3 CLAUDE.md lists every auth.* table each library function (Users::listExtended, Dispatch::handle, invite_complete) queries, so consumers can set DB grants correctly
- [ ] #4 CLAUDE.md documents that APP_BASE_URL must be verified before first use of any email-sending flow
<!-- AC:END -->
