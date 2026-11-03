-- =========================================================
-- MIGRATION 004 - ASSIGNMENT WORK TYPE
-- PowerGuide Dagupan
--
-- Adds work_type to lineman_assignments: what the assignment is FOR
-- ('outage' | 'maintenance' | 'both'). DEFAULT 'both' keeps every existing
-- row meaning exactly what it meant before.
--
-- This column is ORGANIZATIONAL METADATA ONLY. auth/lineman_access.php still
-- scopes outage access on (lineman_id, barangay_id, status) alone, so a
-- 'maintenance' assignment grants the same barangay outage access as any
-- other active row.
--
-- Apply with:
--     mysql -u root -p powerguide < database/004_assignment_work_type.sql
--
-- Additive and idempotent: guarded by information_schema so re-running it
-- against an already-migrated database is a no-op (works on MySQL and MariaDB).
-- =========================================================

SET @has_work_type = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'lineman_assignments'
      AND COLUMN_NAME  = 'work_type'
);

SET @ddl = IF(
    @has_work_type = 0,
    'ALTER TABLE lineman_assignments ADD COLUMN work_type ENUM(''outage'', ''maintenance'', ''both'') NOT NULL DEFAULT ''both'' AFTER barangay_id',
    'SELECT ''work_type already exists'' AS note'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
