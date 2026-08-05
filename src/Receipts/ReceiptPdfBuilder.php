<?php

declare(strict_types=1);

namespace NexWaypoint\Receipts;

use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStay;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Trips\AirportRepository;
use NexWaypoint\Trips\Trip;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripSegment;

/**
 * Builds a NexWAYPOINT itinerary/confirmation PDF from structured trip or stay data.
 */
final class ReceiptPdfBuilder
{
    public function __construct(
        private readonly TripRepository $trips,
        private readonly HotelStayRepository $stays,
        private readonly HotelPropertyRepository $properties,
        private readonly ?AirportRepository $airports = null,
    ) {
    }

    /**
     * @return array{
     *   bytes: string,
     *   kind: string,
     *   brand: ?string,
     *   location_label: string,
     *   travel_date: string,
     *   travel_end_date: ?string,
     *   confirmation_code: ?string,
     *   amount: ?float,
     *   currency: ?string,
     *   title: string,
     *   trip_id: ?int,
     *   hotel_stay_id: ?int
     * }
     */
    public function buildForTrip(Trip $trip): array
    {
        $segments = array_values(array_filter(
            $this->trips->segmentsForTrip((int) $trip->id),
            static fn (TripSegment $s): bool => $s->status !== 'cancelled'
        ));

        $kind = ExpenseReceipt::KIND_OTHER;
        $brand = null;
        $confirmation = null;
        foreach ($segments as $segment) {
            if (in_array($segment->segmentType, ['flight', 'train'], true)) {
                $kind = $segment->segmentType === 'train'
                    ? ExpenseReceipt::KIND_TRAIN
                    : ExpenseReceipt::KIND_FLIGHT;
                $brand = $segment->carrier ?? $brand;
                $confirmation = $segment->confirmationCode ?? $confirmation;
                break;
            }
        }

        $pdf = new SimplePdf();
        $pdf->title('NexWAYPOINT travel confirmation');
        $pdf->line('Generated for expense documentation — not a vendor folio.');
        $pdf->blank();
        $pdf->heading('Trip');
        $pdf->line('Destination: ' . $trip->destinationCity);
        $pdf->line('Dates: ' . $trip->startDate . ' to ' . $trip->endDate);
        $pdf->line('Status: ' . $trip->status);
        if ($confirmation !== null) {
            $pdf->line('Confirmation / PNR: ' . $confirmation);
        }
        if ($trip->tripPurpose !== null && trim($trip->tripPurpose) !== '') {
            $pdf->line('Purpose: ' . $trip->tripPurpose);
        }
        $pdf->blank();
        $pdf->heading('Itinerary');

        if ($segments === []) {
            $pdf->line('(No flight or train segments on file.)');
        } else {
            foreach ($segments as $i => $segment) {
                $n = $i + 1;
                $route = $this->routeLabel($segment->origin, $segment->destination);
                $carrier = trim((string) ($segment->carrier ?? ''));
                $num = trim((string) ($segment->flightNumber ?? ''));
                $label = trim($carrier . ($num !== '' ? ' ' . $num : ''));
                $pdf->line(sprintf(
                    '%d. %s %s  %s',
                    $n,
                    strtoupper($segment->segmentType),
                    $label !== '' ? $label : '—',
                    $route
                ));
                $pdf->line('    Depart: ' . ($segment->departDt ?? '—')
                    . '    Arrive: ' . ($segment->arriveDt ?? '—'));
                if ($segment->confirmationCode !== null) {
                    $pdf->line('    Conf: ' . $segment->confirmationCode);
                }
            }
        }

        $pdf->blank();
        $pdf->line('Generated: ' . (new \DateTimeImmutable('now'))->format('Y-m-d H:i'));
        $pdf->line('NexWAYPOINT');

        $title = ($kind === ExpenseReceipt::KIND_TRAIN ? 'Train' : 'Flight')
            . ' · ' . $trip->destinationCity;

        return [
            'bytes' => $pdf->render(),
            'kind' => $kind === ExpenseReceipt::KIND_OTHER ? ExpenseReceipt::KIND_FLIGHT : $kind,
            'brand' => $brand,
            'location_label' => $trip->destinationCity,
            'travel_date' => $trip->startDate,
            'travel_end_date' => $trip->endDate,
            'confirmation_code' => $confirmation,
            'amount' => null,
            'currency' => null,
            'title' => $title,
            'trip_id' => (int) $trip->id,
            'hotel_stay_id' => null,
        ];
    }

    /**
     * @return array{
     *   bytes: string,
     *   kind: string,
     *   brand: ?string,
     *   location_label: string,
     *   travel_date: string,
     *   travel_end_date: ?string,
     *   confirmation_code: ?string,
     *   amount: ?float,
     *   currency: ?string,
     *   title: string,
     *   trip_id: ?int,
     *   hotel_stay_id: ?int
     * }
     */
    public function buildForStay(HotelStay $stay): array
    {
        $property = $this->properties->find($stay->hotelPropertyId);
        $name = $property?->hotelName ?? 'Hotel stay';
        $cityBits = array_filter([
            $property?->city,
            $property?->stateRegion,
        ], static fn ($v) => is_string($v) && trim($v) !== '');
        $location = $cityBits !== [] ? implode(', ', $cityBits) : $name;
        $brand = $property?->brand;

        $pdf = new SimplePdf();
        $pdf->title('NexWAYPOINT hotel confirmation');
        $pdf->line('Generated for expense documentation — not a hotel folio.');
        $pdf->blank();
        $pdf->heading('Stay');
        $pdf->line('Property: ' . $name);
        if ($brand !== null && trim($brand) !== '') {
            $pdf->line('Brand: ' . $brand);
        }
        $pdf->line('Location: ' . $location);
        if ($property?->addressLine1 !== null && trim($property->addressLine1) !== '') {
            $addr = $property->addressLine1;
            if ($property->addressLine2 !== null && trim($property->addressLine2) !== '') {
                $addr .= ', ' . $property->addressLine2;
            }
            $pdf->line('Address: ' . $addr);
        }
        $pdf->line('Check-in: ' . $stay->stayStart);
        $pdf->line('Check-out: ' . $stay->stayEnd);
        if ($stay->confirmationCode !== null) {
            $pdf->line('Confirmation: ' . $stay->confirmationCode);
        }
        if ($stay->lastStayPrice !== null) {
            $pdf->line('Amount on file: '
                . number_format($stay->lastStayPrice, 2)
                . ' ' . ($stay->currency ?? 'USD'));
        }
        if ($stay->bookingSource !== null) {
            $pdf->line('Booking source: ' . $stay->bookingSource);
        }
        $pdf->blank();
        $pdf->line('Generated: ' . (new \DateTimeImmutable('now'))->format('Y-m-d H:i'));
        $pdf->line('NexWAYPOINT');

        return [
            'bytes' => $pdf->render(),
            'kind' => ExpenseReceipt::KIND_HOTEL,
            'brand' => $brand,
            'location_label' => $location,
            'travel_date' => $stay->stayStart,
            'travel_end_date' => $stay->stayEnd,
            'confirmation_code' => $stay->confirmationCode,
            'amount' => $stay->lastStayPrice,
            'currency' => $stay->currency,
            'title' => 'Hotel · ' . $name,
            'trip_id' => null,
            'hotel_stay_id' => (int) $stay->id,
        ];
    }

    private function routeLabel(?string $origin, ?string $destination): string
    {
        if ($this->airports !== null) {
            return $this->airports->routeLabel($origin, $destination);
        }
        $o = $origin !== null && $origin !== '' ? strtoupper($origin) : '?';
        $d = $destination !== null && $destination !== '' ? strtoupper($destination) : '?';
        return $o . ' → ' . $d;
    }
}
