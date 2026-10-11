<?php

/**
 * Notification copy for maintenance schedules.
 *
 * `maintenance/create.php` (a new schedule) and `maintenance/update.php` (a status
 * change) both notify EVERY resident with a saved location - not just the ones
 * inside the affected area. What changes from resident to resident is the wording,
 * so it is built here once:
 *
 *   - residents directly affected (their barangay is on the schedule, or their saved
 *     pin is within the notify radius) get a "this affects your area" message;
 *   - everyone else still gets the notice, but it opens by saying the work is NOT in
 *     their area, so an alert is never mistaken for a warning about their own power.
 *
 * Both title and message are returned because the title is what the notification
 * list and the topbar preview show.
 *
 * The "Your area" line never claims a barangay is included unless it really is on
 * the schedule: a resident whose pin merely falls inside the radius is told their
 * barangay is not on the schedule, but their saved location is inside the affected
 * radius.
 *
 * @param string      $event             scheduled|upcoming|ongoing|completed|cancelled
 * @param string[]    $allBarangays      every barangay on the schedule
 * @param string[]    $affectedBarangays subset that hits THIS resident ([] = not in their area)
 * @param string|null $userBarangay      the resident's saved barangay name, when known
 * @param string      $formattedDate     e.g. "October 12, 2026"
 * @param string      $timeWindow        e.g. "8:00 AM - 12:00 PM"
 * @param string|null $description       the schedule's description, when provided
 * @param string      $companyName       signature line
 * @return array{title:string, message:string}
 */
function maintenanceNotificationCopy(
    string $event,
    array $allBarangays,
    array $affectedBarangays,
    ?string $userBarangay,
    string $formattedDate,
    string $timeWindow,
    ?string $description,
    string $companyName
): array {
    $userBarangayOnList = false;
    if ($userBarangay !== null && $userBarangay !== '') {
        foreach ($allBarangays as $name) {
            if (strcasecmp((string)$name, $userBarangay) === 0) {
                $userBarangayOnList = true;
                break;
            }
        }
    }

    $inArea  = !empty($affectedBarangays) || $userBarangayOnList;
    $variant = $inArea ? 'in' : 'out';

    $titles = [
        'scheduled' => [
            'in'  => 'Power Maintenance in Your Area',
            'out' => 'Power Maintenance — Not in Your Area',
        ],
        'upcoming' => [
            'in'  => 'Maintenance Rescheduled — Your Area',
            'out' => 'Maintenance Rescheduled — Not in Your Area',
        ],
        'ongoing' => [
            'in'  => 'Power Maintenance Ongoing — Your Area',
            'out' => 'Power Maintenance Ongoing — Not in Your Area',
        ],
        'completed' => [
            'in'  => 'Power Restored in Your Area',
            'out' => 'Maintenance Completed — Not in Your Area',
        ],
        'cancelled' => [
            'in'  => 'Maintenance Cancelled — Your Area',
            'out' => 'Maintenance Cancelled — Not in Your Area',
        ],
    ];

    $headlines = [
        'scheduled' => [
            'in'  => 'Scheduled power maintenance will affect your area.',
            'out' => 'Heads up — this one is not in your area.',
        ],
        'upcoming' => [
            'in'  => 'The maintenance schedule for your area has been updated. Please check the new date and time.',
            'out' => 'A maintenance schedule in other barangays has been updated — this is still not in your area.',
        ],
        'ongoing' => [
            'in'  => 'The power interruption in your area is now ongoing.',
            'out' => 'Power maintenance is now ongoing in other barangays — not in your area.',
        ],
        'completed' => [
            'in'  => 'Power has been restored in your area. Maintenance is complete.',
            'out' => 'Maintenance is complete in other barangays — your area was never affected.',
        ],
        'cancelled' => [
            'in'  => 'The maintenance planned for your area has been cancelled — no interruption will happen.',
            'out' => 'The maintenance planned for other barangays has been cancelled — your area was not affected.',
        ],
    ];

    $closings = [
        'scheduled' => [
            'in'  => 'Please prepare for a temporary power interruption within this window.',
            'out' => "No power interruption is expected where you are. We're letting you know in case your plans take you to the affected areas.",
        ],
        'upcoming' => [
            'in'  => 'Please take note of the new date and time.',
            'out' => 'Nothing changes for your area — no interruption is expected where you are.',
        ],
        'ongoing' => [
            'in'  => 'Expect possible interruptions until the scheduled end time.',
            'out' => 'No interruption is expected where you are.',
        ],
        'completed' => [
            'in'  => 'Thank you for your patience.',
            'out' => 'No action is needed on your side.',
        ],
        'cancelled' => [
            'in'  => 'You do not need to prepare anything.',
            'out' => 'No action is needed on your side.',
        ],
    ];

    $title    = $titles[$event][$variant] ?? 'Maintenance Update';
    $headline = $headlines[$event][$variant] ?? 'There is an update to a maintenance schedule.';
    $closing  = $closings[$event][$variant] ?? '';

    if ($userBarangay !== null && $userBarangay !== '') {
        if ($userBarangayOnList) {
            $areaLine = 'Your area: ' . $userBarangay . ' — included';
        } elseif ($inArea) {
            $areaLine = 'Your area: ' . $userBarangay . ' — not on this schedule, but your saved location is inside the affected radius';
        } else {
            $areaLine = 'Your area: ' . $userBarangay . ' — not affected';
        }
    } else {
        $areaLine = $inArea
            ? 'Your saved location: inside the affected area'
            : 'Your saved location: outside the affected area';
    }

    $lines = [
        $headline,
        '',
        'Affected areas: ' . implode(', ', $allBarangays),
        $areaLine,
        "Date: {$formattedDate}",
        "Time: {$timeWindow}",
    ];

    if ($description !== null && trim($description) !== '') {
        $lines[] = 'Details: ' . trim($description);
    }

    $lines[] = '';
    if ($closing !== '') {
        $lines[] = $closing;
        $lines[] = '';
    }
    $lines[] = $companyName;

    return ['title' => $title, 'message' => implode("\n", $lines)];
}
