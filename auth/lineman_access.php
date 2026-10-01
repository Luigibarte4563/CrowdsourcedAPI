<?php

/**
 * Lineman <-> barangay assignment scope.
 *
 * One definition of "may this lineman touch this barangay?", reused by every endpoint
 * that has to answer that question. Keeping it in one place is the point: the rule is
 * enforced in SQL (see `lineman_scope_sql`), never by fetching a whole table and
 * filtering it afterwards, so an unauthorized row is never loaded in the first place.
 *
 * Roles that are NOT `lineman` are deliberately unaffected. `electric_company` and
 * `admin` keep their existing company-wide access; every helper here returns early for
 * them so the new checks cannot accidentally narrow what they already could do.
 *
 * Depends on `auth/rbac.php` for `hasRole()` and `denyAccess()`. That require is here
 * rather than left to each endpoint, because a file that only pulls in this helper would
 * otherwise fatal with "undefined function hasRole()" - and that is a 500 mid-query, not
 * a clean refusal. Endpoints that use this file still include rbac.php themselves.
 */

require_once __DIR__ . '/rbac.php';

/** Roles allowed to manage assignments. */
const LINEMAN_ASSIGNMENT_MANAGER_ROLES = ['electric_company', 'admin'];

/** Only active rows grant access - a deactivated assignment loses it immediately. */
const LINEMAN_ASSIGNMENT_ACTIVE = 'active';

/** Only this status value is accepted from a client. */
const LINEMAN_ASSIGNMENT_STATUSES = ['active', 'inactive'];

/**
 * Whether a request must be limited to the caller's assigned barangays.
 *
 * A lineman is limited; `electric_company` and `admin` are not. Every outage endpoint
 * calls this before building SQL so the "unchanged for company/admin" rule is decided
 * once rather than re-implemented per endpoint.
 */
function lineman_scope_required($user) {
    return hasRole($user, ['lineman']);
}

/**
 * A WHERE fragment restricting `barangay_id` to the caller's active assignments.
 *
 * Callers append this to a query that already aliases the outage table, e.g.
 *
 *     $sql .= lineman_scope_sql($conn, $user, 'orp', $params, 'ls');
 *
 * which yields
 *
 *     AND orp.barangay_id IN (
 *         SELECT la.barangay_id FROM lineman_assignments la
 *         WHERE la.lineman_id = :ls_lineman AND la.status = :ls_status
 *     )
 *
 * For `electric_company` / `admin` this returns an empty string and binds nothing, so
 * including it unconditionally cannot change their results.
 *
 * A subquery is used rather than a PHP-side array of ids deliberately: it keeps the
 * restriction in the database, so it composes with paging, counting and ORDER BY
 * without any of them having to know about assignments.
 */
function lineman_scope_sql(PDO $conn, $user, $barangayColumn, array &$params, $prefix = 'ls') {
    if (!lineman_scope_required($user)) {
        return '';
    }

    $params[':' . $prefix . '_lineman'] = (int)$user['id'];
    $params[':' . $prefix . '_status'] = LINEMAN_ASSIGNMENT_ACTIVE;

    return " AND {$barangayColumn} IN (
                SELECT la.barangay_id
                FROM lineman_assignments la
                WHERE la.lineman_id = :{$prefix}_lineman
                  AND la.status = :{$prefix}_status
            )";
}

/**
 * Whether this lineman may act on this barangay. Single-record guard.
 *
 * Used before a verify / update on one specific outage. A lineman with no assignment
 * for the barangay gets false and the caller answers 403; company and admin get true.
 */
function lineman_can_access_barangay(PDO $conn, $user, $barangayId) {
    if (!lineman_scope_required($user)) {
        return true;
    }

    /* A NULL barangay_id belongs to no barangay, so no assignment can cover it. */
    if ($barangayId === null || (int)$barangayId <= 0) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT 1
        FROM lineman_assignments
        WHERE lineman_id = ?
          AND barangay_id = ?
          AND status = ?
        LIMIT 1
    ");
    $stmt->execute([(int)$user['id'], (int)$barangayId, LINEMAN_ASSIGNMENT_ACTIVE]);

    return (bool)$stmt->fetchColumn();
}

/**
 * Load one outage report's id + barangay, denying a lineman outside their scope.
 *
 * Answers 404 when the report does not exist and 403 when it exists but its barangay
 * is not assigned to this lineman. Exits on both, so it is only safe to call from an
 * endpoint's main flow (which is what it is for).
 *
 * The authorization check happens BEFORE the caller performs the action, so a denied
 * request never writes a verification or an update row.
 */
function require_outage_access(PDO $conn, $user, $outageReportId) {
    $stmt = $conn->prepare("SELECT id, barangay_id FROM outage_reports WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$outageReportId]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Report not found"]);
        exit;
    }

    if (!lineman_can_access_barangay($conn, $user, $report['barangay_id'])) {
        denyAccess("This outage is not in one of your assigned barangays.");
    }

    return $report;
}

/**
 * The authenticated caller's user id.
 *
 * Every endpoint that records "who did this" (assignments, outages) reads the actor
 * from here, so a client-supplied id can never be trusted for authorship.
 */
function current_user_id($user) {
    return (int)$user['id'];
}