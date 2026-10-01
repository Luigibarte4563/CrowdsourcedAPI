-- =========================================================
-- MIGRATION 002 - LINEMAN ASSIGNMENTS
-- PowerGuide Dagupan
--
-- Assigns `lineman` users to specific barangays. The backend reads this table to
-- scope what a lineman may see and do on outage endpoints (see
-- auth/lineman_access.php); the React UI only mirrors it.
--
-- Apply with:
--     mysql -u root -p powerguide < database/002_lineman_assignments.sql
--
-- Additive and idempotent: creates the table only if it is missing, never drops
-- or rewrites existing tables, users, barangays or outage reports. Re-running it
-- against an already-migrated database is a no-op.
-- =========================================================

CREATE TABLE IF NOT EXISTS lineman_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,

    /* The field lineman being posted. */
    lineman_id INT NOT NULL,

    /* The barangay they cover. */
    barangay_id INT NOT NULL,

    /* The electric_company / admin account that created or last changed this row.
       Always written by the backend from the JWT identity (never from the request). */
    assigned_by INT NOT NULL,

    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    /*
      Column types match users.id and barangays.id exactly (both INT AUTO_INCREMENT),
      so these FKs are legal.

      ON DELETE RESTRICT on all three: deleting a user or a barangay that appears in
      an assignment would silently widen or break somebody's scope, so it has to be an
      explicit, deliberate act by a database administrator.

      The UNIQUE key is what makes `create.php` reactivation-safe: a pair that was
      deactivated must come back as the SAME row (flipping status back to 'active')
      rather than as a duplicate, because a second row would be indistinguishable from
      the first in every query.
    */
    FOREIGN KEY (lineman_id)
        REFERENCES users(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (barangay_id)
        REFERENCES barangays(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (assigned_by)
        REFERENCES users(id)
        ON DELETE RESTRICT,

    UNIQUE KEY uq_lineman_assignments_pair (lineman_id, barangay_id)
);

-- Supports the hot path in auth/lineman_access.php: "which barangays is THIS lineman
-- active in", and the `EXISTS` check every scoped outage query runs per row.
CREATE INDEX idx_lineman_assignments_lineman_status
ON lineman_assignments(lineman_id, status);

-- Supports the inverse lookup ("who covers this barangay") and the staff-side
-- ?barangay_id= filter on get.php.
CREATE INDEX idx_lineman_assignments_barangay
ON lineman_assignments(barangay_id);