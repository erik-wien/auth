-- 09_avatar_simplify.sql
-- Run against auth.
--
-- Shrinks auth_accounts avatar storage to a single column. Pre-conversion the
-- table carried four fields for one concept (img filename, img_type MIME,
-- img_size byte count, img_blob raw bytes); post-conversion only img_blob
-- remains and is canonically a 205x205 JPEG. MIME is no longer tracked
-- because every served blob is image/jpeg.
--
-- Existing blobs are cleared: they were arbitrary MIME types and the new
-- serve path unconditionally sends Content-Type: image/jpeg, so serving old
-- data would misreport the type. Only one account had a non-null blob at
-- conversion time — users simply re-upload.
--
-- Not idempotent (DROP COLUMN without IF EXISTS on the target MariaDB).
-- To re-run after a failure, check which columns still exist via
-- `DESCRIBE auth_accounts;` and drop only the remaining ones.

USE auth;

UPDATE auth_accounts SET img_blob = NULL;

ALTER TABLE auth_accounts
  DROP COLUMN img,
  DROP COLUMN img_type,
  DROP COLUMN img_size;
