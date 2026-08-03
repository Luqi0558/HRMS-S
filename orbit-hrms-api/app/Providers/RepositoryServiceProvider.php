<?php

namespace App\Providers;

use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\InvitationRepositoryInterface;
use App\Repositories\Contracts\OnboardingRepositoryInterface;
use App\Repositories\Contracts\PermissionRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\TicketRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Eloquent\EmployeeRepository;
use App\Repositories\Eloquent\InvitationRepository;
use App\Repositories\Eloquent\OnboardingRepository;
use App\Repositories\Eloquent\PermissionRepository;
use App\Repositories\Eloquent\ProjectRepository;
use App\Repositories\Eloquent\TicketRepository;
use App\Repositories\Eloquent\UserRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Binds each repository contract to its Eloquent implementation.
 *
 * Services and controllers type-hint the interface, so swapping in a fake for
 * tests, or a different persistence layer later, is a change here and nowhere
 * else.
 */
class RepositoryServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        UserRepositoryInterface::class => UserRepository::class,
        EmployeeRepositoryInterface::class => EmployeeRepository::class,
        InvitationRepositoryInterface::class => InvitationRepository::class,
        OnboardingRepositoryInterface::class => OnboardingRepository::class,
        PermissionRepositoryInterface::class => PermissionRepository::class,
        ProjectRepositoryInterface::class => ProjectRepository::class,
        TicketRepositoryInterface::class => TicketRepository::class,
    ];

    public function register(): void
    {
        //
    }
}
