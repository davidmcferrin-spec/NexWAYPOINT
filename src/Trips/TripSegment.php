<?php

declare(strict_types=1);

namespace NexWaypoint\Trips;

final class TripSegment
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $tripId,
        public readonly string $segmentType,
        public readonly ?string $segmentSubtype,
        public readonly ?string $carrier,
        public readonly ?string $flightNumber,
        public readonly ?string $confirmationCode,
        public readonly ?string $origin,
        public readonly ?string $destination,
        public readonly ?string $departDt,
        public readonly ?string $arriveDt,
        public readonly ?int $hotelStayId,
        public readonly string $status,
        public readonly ?int $sourceParseLogId,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            tripId: (int) $row['trip_id'],
            segmentType: (string) $row['segment_type'],
            segmentSubtype: $row['segment_subtype'] ?? null,
            carrier: $row['carrier'] ?? null,
            flightNumber: $row['flight_number'] ?? null,
            confirmationCode: $row['confirmation_code'] ?? null,
            origin: $row['origin'] ?? null,
            destination: $row['destination'] ?? null,
            departDt: $row['depart_dt'] ?? null,
            arriveDt: $row['arrive_dt'] ?? null,
            hotelStayId: isset($row['hotel_stay_id']) ? (int) $row['hotel_stay_id'] : null,
            status: (string) $row['status'],
            sourceParseLogId: isset($row['source_parse_log_id']) ? (int) $row['source_parse_log_id'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'trip_id' => $this->tripId,
            'segment_type' => $this->segmentType,
            'segment_subtype' => $this->segmentSubtype,
            'carrier' => $this->carrier,
            'flight_number' => $this->flightNumber,
            'confirmation_code' => $this->confirmationCode,
            'origin' => $this->origin,
            'destination' => $this->destination,
            'depart_dt' => $this->departDt,
            'arrive_dt' => $this->arriveDt,
            'hotel_stay_id' => $this->hotelStayId,
            'status' => $this->status,
            'source_parse_log_id' => $this->sourceParseLogId,
        ];
    }
}
