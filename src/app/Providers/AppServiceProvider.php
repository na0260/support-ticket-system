<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Lazy-loading, silently discarded attributes and missing attributes
        // throw outside production to surface N+1 and mass-assignment bugs early.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Store stable aliases instead of FQCNs in polymorphic *_type columns.
        Relation::enforceMorphMap([
            'user' => User::class,
            'ticket' => Ticket::class,
            'ticket_message' => TicketMessage::class,
        ]);
    }
}
