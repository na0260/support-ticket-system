<?php

declare(strict_types=1);

use App\Enums\TicketPriority;
use Carbon\CarbonInterface;

/*
|--------------------------------------------------------------------------
| Helpers for list-style environment variables
|--------------------------------------------------------------------------
|
| Comma-separated env values ("0,1,2,3,4" / "2026-12-16,2026-12-25") are
| split into arrays. An unset or empty variable yields the given default.
|
*/

$csv = static function (string $key, array $default): array {
    $value = env($key);

    if (! is_string($value) || trim($value) === '') {
        return $default;
    }

    return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
};

return [

    /*
    |--------------------------------------------------------------------------
    | SLA Timezone
    |--------------------------------------------------------------------------
    |
    | The timezone in which business days, business hours and holidays are
    | interpreted. All SLA arithmetic is performed in this timezone, so results
    | are deterministic regardless of the server's local time. When null, the
    | application timezone (config('app.timezone'), default 'UTC') is used.
    |
    | Computed timestamps are converted back to the application timezone
    | before they are persisted on a ticket.
    |
    */

    'timezone' => env('SLA_TIMEZONE'),

    /*
    |--------------------------------------------------------------------------
    | Business Days
    |--------------------------------------------------------------------------
    |
    | Carbon day-of-week constants (0 = Sunday … 6 = Saturday) that make up the
    | working week. Default: Sunday–Thursday.
    |
    | Env override: SLA_BUSINESS_DAYS="0,1,2,3,4"
    |
    */

    'business_days' => array_map('intval', $csv('SLA_BUSINESS_DAYS', [
        CarbonInterface::SUNDAY,
        CarbonInterface::MONDAY,
        CarbonInterface::TUESDAY,
        CarbonInterface::WEDNESDAY,
        CarbonInterface::THURSDAY,
    ])),

    /*
    |--------------------------------------------------------------------------
    | Business Hours
    |--------------------------------------------------------------------------
    |
    | Daily working window in 24h "HH:MM" format. The start is inclusive and
    | the end is exclusive: a ticket raised at exactly 18:00 starts its SLA
    | clock at 09:00 on the next business day. Default 09:00–18:00 gives
    | 9 working hours (540 business minutes) per business day.
    |
    */

    'business_hours' => [
        'start' => env('SLA_BUSINESS_HOURS_START', '09:00'),
        'end' => env('SLA_BUSINESS_HOURS_END', '18:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Resolution Targets per Priority
    |--------------------------------------------------------------------------
    |
    | Resolution SLA expressed in *business* hours. Defaults come from
    | TicketPriority::slaHours() and may be overridden per environment.
    |
    */

    'priority_durations' => [
        TicketPriority::CRITICAL->value => (int) env('SLA_HOURS_CRITICAL', TicketPriority::CRITICAL->slaHours()),
        TicketPriority::HIGH->value => (int) env('SLA_HOURS_HIGH', TicketPriority::HIGH->slaHours()),
        TicketPriority::MEDIUM->value => (int) env('SLA_HOURS_MEDIUM', TicketPriority::MEDIUM->slaHours()),
        TicketPriority::LOW->value => (int) env('SLA_HOURS_LOW', TicketPriority::LOW->slaHours()),
    ],

    /*
    |--------------------------------------------------------------------------
    | Warning Threshold
    |--------------------------------------------------------------------------
    |
    | Fraction (0 < x <= 1) of the SLA's business duration after which a
    | ticket is flagged as APPROACHING_BREACH. 0.80 = 80% elapsed.
    |
    */

    'warning_threshold_percentage' => (float) env('SLA_WARNING_THRESHOLD', 0.80),

    /*
    |--------------------------------------------------------------------------
    | Holidays
    |--------------------------------------------------------------------------
    |
    | Calendar dates ("YYYY-MM-DD", in the SLA timezone) on which the SLA clock
    | does not run, even if they fall on a configured business day.
    |
    | Env override: SLA_HOLIDAYS="2026-12-16,2026-12-25"
    |
    */

    'holidays' => $csv('SLA_HOLIDAYS', []),

];
