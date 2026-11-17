-- =========================================================
-- MIGRATION 006 - REMOVE work_type FROM lineman_assignments
-- PowerGuide Dagupan
--
-- !!! BACKUP REMINDER !!!
-- Before running this on the live database, take a full dump:
--     "C:\xampp\mysql\bin\mysqldump.exe" -u root powerguide > backup_powerguide_pre_006.sql
--
-- Removes the work_type column from lineman_assignments. Any active assignment now
-- grants the lineman full access to that barangay - outage reports, hazard reports
-- and maintenance schedules alike. There is no per-assignment work type anymore.
--
-- Apply with:
--     "C:\xampp\mysql\bin\mysql.exe" -u root powerguide < database/006_add_hazard_review_work_type.sql
-- =========================================================

USE powerguide;

ALTER TABLE lineman_assignments
    DROP COLUMN work_type;
