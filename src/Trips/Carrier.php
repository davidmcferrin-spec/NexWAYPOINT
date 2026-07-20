<?php

declare(strict_types=1);

namespace NexWaypoint\Trips;

final class Carrier
{
    public const TYPE_AIRLINE = 'airline';
    public const TYPE_RAIL = 'rail';

    public function __construct(
        public readonly ?int $id,
        public readonly int $userId,
        public readonly string $name,
        public readonly ?string $iataCode,
        public readonly string $carrierType = self::TYPE_AIRLINE,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $type = (string) ($row['carrier_type'] ?? self::TYPE_AIRLINE);
        if (!in_array($type, [self::TYPE_AIRLINE, self::TYPE_RAIL], true)) {
            $type = self::TYPE_AIRLINE;
        }

        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            userId: (int) $row['user_id'],
            name: (string) $row['name'],
            iataCode: isset($row['iata_code']) && $row['iata_code'] !== ''
                ? strtoupper((string) $row['iata_code'])
                : null,
            carrierType: $type,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'name' => $this->name,
            'iata_code' => $this->iataCode,
            'carrier_type' => $this->carrierType,
        ];
    }

    public function label(): string
    {
        if ($this->iataCode !== null && $this->iataCode !== '') {
            return "{$this->name} ({$this->iataCode})";
        }
        return $this->name;
    }

    public function isRail(): bool
    {
        return $this->carrierType === self::TYPE_RAIL;
    }

    /** FlightAware-style ident: DL1234 */
    public function flightIdent(string $flightNumber): ?string
    {
        $number = strtoupper(preg_replace('/[^A-Z0-9]/', '', $flightNumber) ?? '');
        if ($number === '' || $this->iataCode === null || $this->iataCode === '') {
            return null;
        }
        if (str_starts_with($number, $this->iataCode) && strlen($number) > strlen($this->iataCode)) {
            $number = substr($number, strlen($this->iataCode));
        }
        return $this->iataCode . $number;
    }
}
