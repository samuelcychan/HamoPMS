<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Booking\Models\Booking;
use Modules\Property\Models\Property;
use Modules\Property\Models\Room;
use Modules\Property\Models\RoomType;
use Tests\TestCase;

class OperationalReportTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Property $property;

    private RoomType $roomType;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-13 10:00:00');
        CarbonImmutable::setTestNow('2026-07-13 10:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->staff = User::factory()->create();
        $this->property = $this->createProperty('Report Hotel');
        $this->roomType = RoomType::create([
            'property_id' => $this->property->id,
            'name' => 'Standard Room',
            'code' => 'STD',
            'max_occupancy' => 2,
            'base_rate' => '100.00',
            'is_active' => true,
        ]);
        $this->createRoom('101', Room::STATUS_CLEAN);
        $occupiedRoom = $this->createRoom('102', Room::STATUS_OCCUPIED);
        $this->createRoom('103', Room::STATUS_DIRTY);
        $departureRoom = $this->createRoom('104', Room::STATUS_OUT_OF_SERVICE);
        RoleAssignment::create([
            'user_id' => $this->staff->id,
            'role_id' => Role::where('slug', 'receptionist')->value('id'),
            'property_id' => $this->property->id,
        ]);

        $this->createBooking('Arrival Guest', '2026-07-13', '2026-07-15', Booking::STATUS_CONFIRMED);
        $this->createBooking(
            'In House Guest',
            '2026-07-12',
            '2026-07-15',
            Booking::STATUS_CHECKED_IN,
            $occupiedRoom,
            checkedInAt: '2026-07-12 15:00:00',
        );
        $this->createBooking(
            'Departure Guest',
            '2026-07-10',
            '2026-07-13',
            Booking::STATUS_COMPLETED,
            $departureRoom,
            checkedInAt: '2026-07-10 15:00:00',
            checkedOutAt: '2026-07-13 09:00:00',
        );
        $this->createBooking('Cancelled Guest', '2026-07-13', '2026-07-14', Booking::STATUS_CANCELLED);
        $this->createBooking('No Show Guest', '2026-07-12', '2026-07-13', Booking::STATUS_CONFIRMED);

        $otherProperty = $this->createProperty('Other Hotel');
        $otherType = RoomType::create([
            'property_id' => $otherProperty->id,
            'name' => 'Other Room',
            'code' => 'OTH',
            'max_occupancy' => 2,
            'base_rate' => '80.00',
            'is_active' => true,
        ]);
        $guest = User::factory()->create(['name' => 'Other Arrival']);
        Booking::create([
            'user_id' => $guest->id,
            'property_id' => $otherProperty->id,
            'room_type_id' => $otherType->id,
            'check_in' => '2026-07-13',
            'check_out' => '2026-07-14',
            'guests' => 1,
            'status' => Booking::STATUS_CONFIRMED,
        ]);
        Booking::create([
            'user_id' => User::factory()->create(['name' => 'Other No Show'])->id,
            'property_id' => $otherProperty->id,
            'room_type_id' => $otherType->id,
            'check_in' => '2026-07-12',
            'check_out' => '2026-07-13',
            'guests' => 1,
            'status' => Booking::STATUS_CONFIRMED,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_arrivals_and_departures_are_filtered_by_property_and_date(): void
    {
        $this->report('arrivals', ['date' => '2026-07-13'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.guest_name', 'Arrival Guest')
            ->assertJsonPath('meta.report.property_id', $this->property->id)
            ->assertJsonPath('meta.report.date', '2026-07-13')
            ->assertJsonPath('meta.report.row_count', 1)
            ->assertJsonPath('meta.performance.target_ms', 250);

        $this->report('departures', ['date' => '2026-07-13'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.guest_name', 'Departure Guest')
            ->assertJsonPath('data.0.checked_out_at', '2026-07-13T09:00:00+00:00');

        $this->report('arrivals', ['date' => '2026-07-14'])
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.report.row_count', 0);
    }

    public function test_in_house_report_reflects_the_current_checked_in_state(): void
    {
        $this->report('in-house')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.guest_name', 'In House Guest')
            ->assertJsonPath('data.0.room_number', '102')
            ->assertJsonPath('meta.report.as_of', '2026-07-13T10:00:00+00:00');
    }

    public function test_occupancy_uses_sellable_rooms_and_half_open_stay_dates(): void
    {
        $this->report('occupancy', ['date' => '2026-07-13'])
            ->assertOk()
            ->assertJsonPath('data.total_rooms', 4)
            ->assertJsonPath('data.out_of_service_rooms', 1)
            ->assertJsonPath('data.sellable_rooms', 3)
            ->assertJsonPath('data.occupied_rooms', 2)
            ->assertJsonPath('data.available_rooms', 1)
            ->assertJsonPath('data.occupancy_percentage', 66.67);
    }

    public function test_no_show_and_room_status_reports_cover_daily_operations(): void
    {
        $this->report('no-shows', ['date' => '2026-07-12'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.guest_name', 'No Show Guest')
            ->assertJsonPath('meta.report.name', 'no-shows')
            ->assertJsonPath('meta.report.date', '2026-07-12');
        $this->report('no-shows', ['date' => '2026-07-13'])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->report('room-status')
            ->assertOk()
            ->assertJsonPath('data.total_rooms', 4)
            ->assertJsonPath('data.clean_rooms', 1)
            ->assertJsonPath('data.dirty_rooms', 1)
            ->assertJsonPath('data.cleaning_rooms', 0)
            ->assertJsonPath('data.occupied_rooms', 1)
            ->assertJsonPath('data.out_of_service_rooms', 1)
            ->assertJsonPath('meta.report.name', 'room-status')
            ->assertJsonPath('meta.report.as_of', '2026-07-13T10:00:00+00:00');
    }

    public function test_reports_can_be_exported_as_csv(): void
    {
        $response = $this->report('arrivals', [
            'date' => '2026-07-13',
            'format' => 'csv',
        ])->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('x-report-row-count', '1');

        $this->assertStringContainsString('booking_id,guest_id,guest_name', $response->getContent());
        $this->assertStringContainsString('Arrival Guest', $response->getContent());
    }

    public function test_report_inputs_and_permissions_are_enforced(): void
    {
        $this->report('arrivals', ['date' => '13-07-2026'])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['date']]]]);

        $housekeeper = User::factory()->create();
        RoleAssignment::create([
            'user_id' => $housekeeper->id,
            'role_id' => Role::where('slug', 'housekeeping')->value('id'),
            'property_id' => $this->property->id,
        ]);
        $this->actingAs($housekeeper, 'sanctum')
            ->getJson("/api/v1/reports/occupancy?property_id={$this->property->id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    private function report(string $report, array $query = [])
    {
        $query = http_build_query(array_merge(['property_id' => $this->property->id], $query));

        return $this->actingAs($this->staff, 'sanctum')->get("/api/v1/reports/{$report}?{$query}");
    }

    private function createProperty(string $name): Property
    {
        return Property::create([
            'name' => $name,
            'address' => '1 Main Street',
            'type' => 'hotel',
        ]);
    }

    private function createRoom(string $number, string $status): Room
    {
        return Room::create([
            'property_id' => $this->property->id,
            'room_type_id' => $this->roomType->id,
            'number' => $number,
            'status' => $status,
        ]);
    }

    private function createBooking(
        string $guestName,
        string $checkIn,
        string $checkOut,
        string $status,
        ?Room $room = null,
        ?string $checkedInAt = null,
        ?string $checkedOutAt = null,
    ): Booking {
        $guest = User::factory()->create(['name' => $guestName]);

        return Booking::create([
            'user_id' => $guest->id,
            'property_id' => $this->property->id,
            'room_type_id' => $this->roomType->id,
            'room_id' => $room?->id,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'guests' => 1,
            'status' => $status,
            'checked_in_at' => $checkedInAt,
            'checked_in_by' => $checkedInAt === null ? null : $this->staff->id,
            'checked_out_at' => $checkedOutAt,
            'checked_out_by' => $checkedOutAt === null ? null : $this->staff->id,
        ]);
    }
}
