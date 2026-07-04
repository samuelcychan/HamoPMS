<?php

namespace Tests\Feature\Tenancy;

use App\Models\Property;
use App\Models\WebhookSubscription;
use App\Support\Tenancy\PropertyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BelongsToPropertyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PropertyContext::clear();

        parent::tearDown();
    }

    public function test_queries_are_scoped_to_the_current_property_context(): void
    {
        $propertyA = Property::factory()->create();
        $propertyB = Property::factory()->create();

        WebhookSubscription::create([
            'property_id' => $propertyA->id,
            'url' => 'https://example.com/hook-a',
            'secret' => 'secret-a',
            'event_types' => ['reservation.created'],
        ]);

        WebhookSubscription::create([
            'property_id' => $propertyB->id,
            'url' => 'https://example.com/hook-b',
            'secret' => 'secret-b',
            'event_types' => ['reservation.created'],
        ]);

        PropertyContext::set($propertyA->id);
        $this->assertSame(1, WebhookSubscription::count());
        $this->assertSame('https://example.com/hook-a', WebhookSubscription::first()->url);

        PropertyContext::set($propertyB->id);
        $this->assertSame(1, WebhookSubscription::count());
        $this->assertSame('https://example.com/hook-b', WebhookSubscription::first()->url);

        PropertyContext::clear();
        $this->assertSame(2, WebhookSubscription::count());
    }

    public function test_new_models_are_auto_assigned_the_current_property_id(): void
    {
        $property = Property::factory()->create();

        PropertyContext::set($property->id);

        $subscription = WebhookSubscription::create([
            'url' => 'https://example.com/hook',
            'secret' => 'secret',
            'event_types' => ['reservation.created'],
        ]);

        $this->assertSame($property->id, $subscription->property_id);
    }
}
