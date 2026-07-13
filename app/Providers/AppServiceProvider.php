<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Modules\Booking\Events\ReservationCancelled;
use Modules\Booking\Events\ReservationConfirmed;
use Modules\Booking\Events\ReservationModified;
use Modules\Notification\Listeners\QueueGuestLifecycleNotification;
use Modules\Payment\Contracts\PaymentGateway;
use Modules\Payment\Events\PaymentReceiptIssued;
use Modules\Payment\Gateways\StripeGateway;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, function (Application $app): PaymentGateway {
            $driver = config('payment.default');

            return match ($driver) {
                'stripe' => new StripeGateway(
                    $app->make(Factory::class),
                    (string) config('payment.gateways.stripe.secret'),
                    (string) config('payment.gateways.stripe.base_url'),
                ),
                default => throw new InvalidArgumentException("Unsupported payment gateway [{$driver}]."),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(ReservationConfirmed::class, QueueGuestLifecycleNotification::class);
        Event::listen(ReservationModified::class, QueueGuestLifecycleNotification::class);
        Event::listen(ReservationCancelled::class, QueueGuestLifecycleNotification::class);
        Event::listen(PaymentReceiptIssued::class, QueueGuestLifecycleNotification::class);

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
        RateLimiter::for('integration', function (Request $request) {
            return Limit::perMinute(max(1, (int) config('integrations.rate_limit_per_minute', 120)))
                ->by($request->route('provider').'|'.$request->ip());
        });
    }
}
