<?php

declare(strict_types=1);

/**
 * US (plus nearby hubs) airport IATA → IANA timezone seed.
 * Used by migrate.php and as AirportRepository fallback when DB is empty.
 *
 * @return list<array{iata: string, name: string, timezone: string}>
 */
return [
    // Eastern
    ['iata' => 'ATL', 'name' => 'Hartsfield-Jackson Atlanta', 'timezone' => 'America/New_York'],
    ['iata' => 'BOS', 'name' => 'Boston Logan', 'timezone' => 'America/New_York'],
    ['iata' => 'BWI', 'name' => 'Baltimore/Washington', 'timezone' => 'America/New_York'],
    ['iata' => 'CLT', 'name' => 'Charlotte Douglas', 'timezone' => 'America/New_York'],
    ['iata' => 'DCA', 'name' => 'Ronald Reagan Washington National', 'timezone' => 'America/New_York'],
    ['iata' => 'DTW', 'name' => 'Detroit Metro', 'timezone' => 'America/New_York'],
    ['iata' => 'EWR', 'name' => 'Newark Liberty', 'timezone' => 'America/New_York'],
    ['iata' => 'FLL', 'name' => 'Fort Lauderdale-Hollywood', 'timezone' => 'America/New_York'],
    ['iata' => 'IAD', 'name' => 'Washington Dulles', 'timezone' => 'America/New_York'],
    ['iata' => 'JFK', 'name' => 'New York JFK', 'timezone' => 'America/New_York'],
    ['iata' => 'LGA', 'name' => 'New York LaGuardia', 'timezone' => 'America/New_York'],
    ['iata' => 'MCO', 'name' => 'Orlando International', 'timezone' => 'America/New_York'],
    ['iata' => 'MIA', 'name' => 'Miami International', 'timezone' => 'America/New_York'],
    ['iata' => 'PHL', 'name' => 'Philadelphia International', 'timezone' => 'America/New_York'],
    ['iata' => 'PIT', 'name' => 'Pittsburgh International', 'timezone' => 'America/New_York'],
    ['iata' => 'RDU', 'name' => 'Raleigh-Durham', 'timezone' => 'America/New_York'],
    ['iata' => 'RIC', 'name' => 'Richmond International', 'timezone' => 'America/New_York'],
    ['iata' => 'ROC', 'name' => 'Greater Rochester', 'timezone' => 'America/New_York'],
    ['iata' => 'SYR', 'name' => 'Syracuse Hancock', 'timezone' => 'America/New_York'],
    ['iata' => 'TPA', 'name' => 'Tampa International', 'timezone' => 'America/New_York'],
    ['iata' => 'BUF', 'name' => 'Buffalo Niagara', 'timezone' => 'America/New_York'],
    ['iata' => 'ORF', 'name' => 'Norfolk International', 'timezone' => 'America/New_York'],
    ['iata' => 'CHS', 'name' => 'Charleston AFB/International', 'timezone' => 'America/New_York'],
    ['iata' => 'SAV', 'name' => 'Savannah/Hilton Head', 'timezone' => 'America/New_York'],
    ['iata' => 'JAX', 'name' => 'Jacksonville International', 'timezone' => 'America/New_York'],
    ['iata' => 'PBI', 'name' => 'Palm Beach International', 'timezone' => 'America/New_York'],
    ['iata' => 'RSW', 'name' => 'Southwest Florida', 'timezone' => 'America/New_York'],
    ['iata' => 'BNA', 'name' => 'Nashville International', 'timezone' => 'America/Chicago'],
    ['iata' => 'IND', 'name' => 'Indianapolis International', 'timezone' => 'America/Indiana/Indianapolis'],
    ['iata' => 'CMH', 'name' => 'John Glenn Columbus', 'timezone' => 'America/New_York'],
    ['iata' => 'CLE', 'name' => 'Cleveland Hopkins', 'timezone' => 'America/New_York'],
    ['iata' => 'CVG', 'name' => 'Cincinnati/Northern Kentucky', 'timezone' => 'America/New_York'],

    // Central
    ['iata' => 'ORD', 'name' => 'Chicago O\'Hare', 'timezone' => 'America/Chicago'],
    ['iata' => 'MDW', 'name' => 'Chicago Midway', 'timezone' => 'America/Chicago'],
    ['iata' => 'DFW', 'name' => 'Dallas/Fort Worth', 'timezone' => 'America/Chicago'],
    ['iata' => 'DAL', 'name' => 'Dallas Love Field', 'timezone' => 'America/Chicago'],
    ['iata' => 'IAH', 'name' => 'Houston Bush Intercontinental', 'timezone' => 'America/Chicago'],
    ['iata' => 'HOU', 'name' => 'Houston Hobby', 'timezone' => 'America/Chicago'],
    ['iata' => 'AUS', 'name' => 'Austin-Bergstrom', 'timezone' => 'America/Chicago'],
    ['iata' => 'SAT', 'name' => 'San Antonio International', 'timezone' => 'America/Chicago'],
    ['iata' => 'MSY', 'name' => 'Louis Armstrong New Orleans', 'timezone' => 'America/Chicago'],
    ['iata' => 'MCI', 'name' => 'Kansas City International', 'timezone' => 'America/Chicago'],
    ['iata' => 'STL', 'name' => 'St. Louis Lambert', 'timezone' => 'America/Chicago'],
    ['iata' => 'MSP', 'name' => 'Minneapolis–Saint Paul', 'timezone' => 'America/Chicago'],
    ['iata' => 'MKE', 'name' => 'Milwaukee Mitchell', 'timezone' => 'America/Chicago'],
    ['iata' => 'MEM', 'name' => 'Memphis International', 'timezone' => 'America/Chicago'],
    ['iata' => 'HSV', 'name' => 'Huntsville International', 'timezone' => 'America/Chicago'],
    ['iata' => 'BHM', 'name' => 'Birmingham-Shuttlesworth', 'timezone' => 'America/Chicago'],
    ['iata' => 'LIT', 'name' => 'Clinton National Little Rock', 'timezone' => 'America/Chicago'],
    ['iata' => 'OKC', 'name' => 'Will Rogers World', 'timezone' => 'America/Chicago'],
    ['iata' => 'TUL', 'name' => 'Tulsa International', 'timezone' => 'America/Chicago'],
    ['iata' => 'DSM', 'name' => 'Des Moines International', 'timezone' => 'America/Chicago'],
    ['iata' => 'OMA', 'name' => 'Eppley Airfield', 'timezone' => 'America/Chicago'],
    ['iata' => 'GRR', 'name' => 'Gerald R. Ford', 'timezone' => 'America/Detroit'],
    ['iata' => 'SDF', 'name' => 'Louisville Muhammad Ali', 'timezone' => 'America/Kentucky/Louisville'],

    // Mountain
    ['iata' => 'DEN', 'name' => 'Denver International', 'timezone' => 'America/Denver'],
    ['iata' => 'COS', 'name' => 'Colorado Springs', 'timezone' => 'America/Denver'],
    ['iata' => 'SLC', 'name' => 'Salt Lake City International', 'timezone' => 'America/Denver'],
    ['iata' => 'ABQ', 'name' => 'Albuquerque International', 'timezone' => 'America/Denver'],
    ['iata' => 'ELP', 'name' => 'El Paso International', 'timezone' => 'America/Denver'],
    ['iata' => 'PHX', 'name' => 'Phoenix Sky Harbor', 'timezone' => 'America/Phoenix'],
    ['iata' => 'TUS', 'name' => 'Tucson International', 'timezone' => 'America/Phoenix'],
    ['iata' => 'BIL', 'name' => 'Billings Logan', 'timezone' => 'America/Denver'],
    ['iata' => 'BOI', 'name' => 'Boise Airport', 'timezone' => 'America/Boise'],

    // Pacific
    ['iata' => 'LAX', 'name' => 'Los Angeles International', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'SNA', 'name' => 'John Wayne Orange County', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'ONT', 'name' => 'Ontario International', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'BUR', 'name' => 'Hollywood Burbank', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'SAN', 'name' => 'San Diego International', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'SFO', 'name' => 'San Francisco International', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'SJC', 'name' => 'San Jose International', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'OAK', 'name' => 'Oakland International', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'SMF', 'name' => 'Sacramento International', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'PDX', 'name' => 'Portland International', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'SEA', 'name' => 'Seattle-Tacoma', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'GEG', 'name' => 'Spokane International', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'LAS', 'name' => 'Harry Reid Las Vegas', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'RNO', 'name' => 'Reno-Tahoe', 'timezone' => 'America/Los_Angeles'],

    // Alaska / Hawaii
    ['iata' => 'ANC', 'name' => 'Ted Stevens Anchorage', 'timezone' => 'America/Anchorage'],
    ['iata' => 'FAI', 'name' => 'Fairbanks International', 'timezone' => 'America/Anchorage'],
    ['iata' => 'HNL', 'name' => 'Daniel K. Inouye Honolulu', 'timezone' => 'Pacific/Honolulu'],
    ['iata' => 'OGG', 'name' => 'Kahului', 'timezone' => 'Pacific/Honolulu'],
    ['iata' => 'KOA', 'name' => 'Ellison Onizuka Kona', 'timezone' => 'Pacific/Honolulu'],
    ['iata' => 'LIH', 'name' => 'Lihue', 'timezone' => 'Pacific/Honolulu'],

    // Nearby hubs often on US itineraries
    ['iata' => 'YYZ', 'name' => 'Toronto Pearson', 'timezone' => 'America/Toronto'],
    ['iata' => 'YVR', 'name' => 'Vancouver International', 'timezone' => 'America/Vancouver'],
    ['iata' => 'YUL', 'name' => 'Montréal-Trudeau', 'timezone' => 'America/Toronto'],
    ['iata' => 'CUN', 'name' => 'Cancún International', 'timezone' => 'America/Cancun'],
    ['iata' => 'MEX', 'name' => 'Mexico City International', 'timezone' => 'America/Mexico_City'],
];
