<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Ticket Assignment Configuration
    |--------------------------------------------------------------------------
    |
    | Supported default strategies: 'workload', 'round_robin'
    |
    */
    'assignment' => [
        'default_strategy' => env('TICKET_ASSIGNMENT_STRATEGY', 'workload'),
        'auto_assign_on_creation' => (bool) env('TICKET_AUTO_ASSIGN_ON_CREATE', env('AUTO_ASSIGN_ON_CREATION', false)),
    ],
];
