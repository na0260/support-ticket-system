<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Sla\SlaSettings;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Abilities that must never be bypassed by Gate::before, preserving
     * explicit domain/terminal restrictions (e.g. no replies to closed tickets,
     * no internal notes without role checks).
     *
     * @var list<string>
     */
    private const array STRICT_ABILITIES = [
        'create',
        'createInternalNote',
        'claim',
        'resolve',
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Resolved per use (not a singleton) so runtime config changes, e.g. in
        // tests or long-running workers after config reloads, are honoured.
        $this->app->bind(SlaSettings::class, static fn (Application $app): SlaSettings => SlaSettings::fromConfig(
            (array) $app->make('config')->get('sla', []),
            (string) $app->make('config')->get('app.timezone', 'UTC'),
        ));
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

        // Admin Super-Gate: Admins pass abilities by default, except where
        // domain rules explicitly require a specialized policy evaluation.
        Gate::before(static function (User $user, string $ability): ?bool {
            if ($user->role->isAdmin() && ! in_array($ability, self::STRICT_ABILITIES, true)) {
                return true;
            }

            return null;
        });
    }
}
