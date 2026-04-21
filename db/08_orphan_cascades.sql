-- 08_orphan_cascades.sql
-- Run against auth.
--
-- Retrofit ON DELETE CASCADE on same-DB tables that reference auth_accounts.id
-- but were created without a foreign-key constraint. Without this, deleting a
-- user via admin_delete_user() leaves orphan rows in these tables (dead reset
-- tokens, stale session rows), which is a security gap.
--
-- auth_log is intentionally excluded — audit trails must outlive the user.
-- Cross-DB tables (wlmonitor.wl_preferences, energie.en_preferences) are
-- handled via application-level cleanup hooks in the auth library, not FKs,
-- to avoid coupling cross-database REFERENCES privileges.

USE auth;

-- password_resets: retain user_id but cascade on delete.
ALTER TABLE password_resets DROP FOREIGN KEY IF EXISTS fk_pwreset_user;
ALTER TABLE password_resets
  ADD CONSTRAINT fk_pwreset_user
  FOREIGN KEY (user_id) REFERENCES auth_accounts(id)
  ON DELETE CASCADE;

-- wl_sessions: wlmonitor session tokens keyed by idUser.
ALTER TABLE wl_sessions DROP FOREIGN KEY IF EXISTS fk_wlsess_user;
ALTER TABLE wl_sessions
  ADD CONSTRAINT fk_wlsess_user
  FOREIGN KEY (idUser) REFERENCES auth_accounts(id)
  ON DELETE CASCADE;
