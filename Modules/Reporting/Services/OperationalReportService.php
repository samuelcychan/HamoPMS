<?php

namespace Modules\Reporting\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Booking\Models\Booking;
use Modules\Property\Models\Room;

class OperationalReportService
{
    public const STAY_COLUMNS = [
        'booking_id',
        'guest_id',
        'guest_name',
        'guest_email',
        'room_number',
        'room_type_code',
        'room_type_name',
        'check_in',
        'check_out',
        'guests',
        'status',
        'checked_in_at',
        'checked_out_at',
    ];

    public const OCCUPANCY_COLUMNS = [
        'date',
        'total_rooms',
        'out_of_service_rooms',
        'sellable_rooms',
        'occupied_rooms',
        'available_rooms',
        'occupancy_percentage',
    ];

    public function arrivals(int $propertyId, CarbonImmutable $date): array
    {
        $start = $date->startOfDay();
        $end = $start->addDay();

        return $this->stayRows(
            $this->stayQuery($propertyId)
                ->where('check_in', '>=', $start)
                ->where('check_in', '<', $end)
                ->whereIn('status', [
                    Booking::STATUS_CONFIRMED,
                    Booking::STATUS_CHECKED_IN,
                    Booking::STATUS_COMPLETED,
                ])
                ->orderBy('check_in')
                ->orderBy('id')
                ->get(),
        );
    }

    public function departures(int $propertyId, CarbonImmutable $date): array
    {
        $start = $date->startOfDay();
        $end = $start->addDay();

        return $this->stayRows(
            $this->stayQuery($propertyId)
                ->where('check_out', '>=', $start)
                ->where('check_out', '<', $end)
                ->whereIn('status', [
                    Booking::STATUS_CONFIRMED,
                    Booking::STATUS_CHECKED_IN,
                    Booking::STATUS_COMPLETED,
                ])
                ->orderBy('check_out')
                ->orderBy('id')
                ->get(),
        );
    }

    public function inHouse(int $propertyId, CarbonImmutable $asOf): array
    {
        return $this->stayRows(
            $this->stayQuery($propertyId)
                ->where('status', Booking::STATUS_CHECKED_IN)
                ->where('checked_in_at', '<=', $asOf)
                ->where(function (Builder $query) use ($asOf): void {
                    $query->whereNull('checked_out_at')->orWhere('checked_out_at', '>', $asOf);
                })
                ->where('check_in', '<=', $asOf)
                ->where('check_out', '>', $asOf)
                ->orderBy('room_id')
                ->orderBy('id')
                ->get(),
        );
    }

    public function occupancy(int $propertyId, CarbonImmutable $date): array
    {
        $rooms = Room::query()->where('property_id', $propertyId);
        $totalRooms = (clone $rooms)->count();
        $outOfServiceRooms = (clone $rooms)->where('status', Room::STATUS_OUT_OF_SERVICE)->count();
        $sellableRooms = $totalRooms - $outOfServiceRooms;
        $occupiedRooms = Booking::query()
            ->where('property_id', $propertyId)
            ->where('check_in', '<=', $date->startOfDay())
            ->where('check_out', '>', $date->startOfDay())
            ->whereIn('status', [
                Booking::STATUS_CONFIRMED,
                Booking::STATUS_CHECKED_IN,
                Booking::STATUS_COMPLETED,
            ])
            ->count();

        return [[
            'date' => $date->toDateString(),
            'total_rooms' => $totalRooms,
            'out_of_service_rooms' => $outOfServiceRooms,
            'sellable_rooms' => $sellableRooms,
            'occupied_rooms' => $occupiedRooms,
            'available_rooms' => max(0, $sellableRooms - $occupiedRooms),
            'occupancy_percentage' => $sellableRooms === 0
                ? 0.0
                : round(($occupiedRooms / $sellableRooms) * 100, 2),
        ]];
    }

    private function stayQuery(int $propertyId): Builder
    {
        return Booking::query()
            ->with([
                'guest:id,name,email',
                'room:id,number',
                'roomType:id,code,name',
            ])
            ->where('property_id', $propertyId);
    }

    private function stayRows(Collection $bookings): array
    {
        return $bookings->map(fn (Booking $booking): array => [
            'booking_id' => $booking->id,
            'guest_id' => $booking->user_id,
            'guest_name' => $booking->guest?->name,
            'guest_email' => $booking->guest?->email,
            'room_number' => $booking->room?->number,
            'room_type_code' => $booking->roomType?->code,
            'room_type_name' => $booking->roomType?->name,
            'check_in' => $booking->check_in->toDateString(),
            'check_out' => $booking->check_out->toDateString(),
            'guests' => $booking->guests,
            'status' => $booking->status,
            'checked_in_at' => $booking->checked_in_at?->toIso8601String(),
            'checked_out_at' => $booking->checked_out_at?->toIso8601String(),
        ])->all();
    }
}
