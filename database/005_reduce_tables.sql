-- =========================================================
-- MIGRATION 005 - REDUCE TABLES
-- PowerGuide Dagupan
--
-- !!! BACKUP REMINDER !!!
-- Before running this on the live database, take a full dump:
--     "C:\xampp\mysql\bin\mysqldump.exe" -u root powerguide > backup_powerguide_pre_005.sql
--
-- This migration:
--   1. Drops outage_cluster_reports (derived data, recomputable)
--   2. Replaces outage_categories with category ENUM on outage_reports
--   3. Replaces hazard_types with hazard_type ENUM on outage_reports and electrical_hazards
--   4. Unifies severity scale to low/moderate/high/critical across all three tables
--   5. Renames flood_reports.flood_level → severity
--   6. Adds updated_by INT NULL FK to flood_reports
--   7. Adds INDEX idx_notifications_user_read (user_id, is_read) to notifications
--
-- Apply with:
--     "C:\xampp\mysql\bin\mysql.exe" -u root powerguide < database/005_reduce_tables.sql
-- =========================================================

USE powerguide;

-- ---------------------------------------------------------------------------
-- PHASE 1 - Add new ENUM columns first (no data removed yet).
-- ---------------------------------------------------------------------------

-- outage_reports: add category ENUM column after barangay_id
ALTER TABLE outage_reports
    ADD COLUMN category ENUM('power_outage','low_voltage','power_fluctuation',
        'transformer_explosion','fallen_power_line','electrical_fire',
        'scheduled_maintenance','unknown_issue') NOT NULL
    AFTER barangay_id;

-- outage_reports: add hazard_type ENUM column after severity
ALTER TABLE outage_reports
    ADD COLUMN hazard_type ENUM('none','smoke','sparks','fire','fallen_wire',
        'explosion_sound','submerged_electrical_equipment','low_hanging_cable',
        'exposed_wire','electrical_shock_risk') NOT NULL DEFAULT 'none'
    AFTER severity;

-- outage_reports: add severity_new column for remapping (ENUM positional values differ)
ALTER TABLE outage_reports
    ADD COLUMN severity_new ENUM('low','moderate','high','critical') NOT NULL DEFAULT 'low'
    AFTER severity;

-- electrical_hazards: add hazard_type ENUM column after hazard_type_id
ALTER TABLE electrical_hazards
    ADD COLUMN hazard_type ENUM('none','smoke','sparks','fire','fallen_wire',
        'explosion_sound','submerged_electrical_equipment','low_hanging_cable',
        'exposed_wire','electrical_shock_risk') NOT NULL
    AFTER hazard_type_id;

-- flood_reports: add severity ENUM column after flood_level
ALTER TABLE flood_reports
    ADD COLUMN severity ENUM('low','moderate','high','critical') NOT NULL DEFAULT 'low'
    AFTER flood_level;

-- flood_reports: add updated_by column after reported_at
ALTER TABLE flood_reports
    ADD COLUMN updated_by INT NULL
    AFTER reported_at;

ALTER TABLE flood_reports
    ADD CONSTRAINT fk_flood_reports_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id)
        ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- PHASE 2 - Copy data from old lookup columns into new ENUM columns.
-- Wrapped in a transaction so the copies apply atomically.
-- ---------------------------------------------------------------------------

START TRANSACTION;

-- Map category_id → category via outage_categories.category_name
UPDATE outage_reports o
JOIN outage_categories oc ON oc.id = o.category_id
SET o.category = oc.category_name;

-- Map hazard_type_id → hazard_type via hazard_types.hazard_name
UPDATE outage_reports o
JOIN hazard_types ht ON ht.id = o.hazard_type_id
SET o.hazard_type = ht.hazard_name;

-- Map severity (minor→low, moderate→moderate, critical→critical) to severity_new
UPDATE outage_reports
SET severity_new = CASE severity
    WHEN 'minor' THEN 'low'
    WHEN 'moderate' THEN 'moderate'
    WHEN 'critical' THEN 'critical'
END;

COMMIT;

-- ---------------------------------------------------------------------------
-- PHASE 3 - electrical_hazards: copy hazard_type_id → hazard_type
-- ---------------------------------------------------------------------------

START TRANSACTION;

UPDATE electrical_hazards eh
JOIN hazard_types ht ON ht.id = eh.hazard_type_id
SET eh.hazard_type = ht.hazard_name;

COMMIT;

-- ---------------------------------------------------------------------------
-- PHASE 4 - flood_reports: copy flood_level → severity (severe→critical)
-- ---------------------------------------------------------------------------

START TRANSACTION;

UPDATE flood_reports
SET severity = CASE flood_level
    WHEN 'low' THEN 'low'
    WHEN 'moderate' THEN 'moderate'
    WHEN 'high' THEN 'high'
    WHEN 'severe' THEN 'critical'
END;

COMMIT;

-- ---------------------------------------------------------------------------
-- PHASE 5 - Verify no NULLs remain in any newly populated column.
-- ---------------------------------------------------------------------------

SELECT 'outage_reports.category NULLs'   AS check_name, COUNT(*) AS cnt FROM outage_reports WHERE category IS NULL
UNION ALL
SELECT 'outage_reports.hazard_type NULLs', COUNT(*) FROM outage_reports WHERE hazard_type IS NULL
UNION ALL
SELECT 'outage_reports.severity_new NULLs', COUNT(*) FROM outage_reports WHERE severity_new IS NULL
UNION ALL
SELECT 'electrical_hazards.hazard_type NULLs', COUNT(*) FROM electrical_hazards WHERE hazard_type IS NULL
UNION ALL
SELECT 'flood_reports.severity NULLs', COUNT(*) FROM flood_reports WHERE severity IS NULL;

-- ---------------------------------------------------------------------------
-- PHASE 6 - Drop old FKs, columns, indexes, and tables.
-- ---------------------------------------------------------------------------

-- outage_reports: drop category_id FK and column
ALTER TABLE outage_reports
    DROP FOREIGN KEY outage_reports_ibfk_3,
    DROP COLUMN category_id;

-- outage_reports: drop hazard_type_id FK and column
ALTER TABLE outage_reports
    DROP FOREIGN KEY outage_reports_ibfk_5,
    DROP COLUMN hazard_type_id;

-- outage_reports: drop old severity column, promote severity_new → severity
ALTER TABLE outage_reports
    DROP COLUMN severity,
    CHANGE COLUMN severity_new severity ENUM('low','moderate','high','critical') NOT NULL;

-- electrical_hazards: drop hazard_type_id FK and column
ALTER TABLE electrical_hazards
    DROP FOREIGN KEY electrical_hazards_ibfk_3,
    DROP COLUMN hazard_type_id;

-- flood_reports: drop flood_level column (replaced by severity)
ALTER TABLE flood_reports
    DROP COLUMN flood_level;

-- notifications: add composite index for user+read queries
CREATE INDEX idx_notifications_user_read
ON notifications(user_id, is_read);

-- Drop removed tables
DROP TABLE IF EXISTS outage_cluster_reports;
DROP TABLE IF EXISTS outage_categories;
DROP TABLE IF EXISTS hazard_types;

-- ---------------------------------------------------------------------------
-- PHASE 7 - Done.
-- =========================================================
