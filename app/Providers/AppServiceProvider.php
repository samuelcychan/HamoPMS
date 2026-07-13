<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Modules\Payment\Contracts\PaymentGateway;
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
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}
