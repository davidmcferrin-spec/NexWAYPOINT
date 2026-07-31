<?php

declare(strict_types=1);

/**
 * US (plus nearby hubs) airport seed: IATA, display city/state, name, IANA timezone.
 * Used by migrate.php and as AirportRepository fallback when DB is empty.
 *
 * @return list<array{iata: string, name: string, city: ?string, state: ?string, timezone: string}>
 */
return [
    // AK
    ['iata' => 'ANC', 'name' => 'Anchorage International Airport', 'city' => 'Anchorage', 'state' => 'AK', 'timezone' => 'America/Anchorage'],
    ['iata' => 'FAI', 'name' => 'Fairbanks International Airport', 'city' => 'Fairbanks', 'state' => 'AK', 'timezone' => 'America/Anchorage'],
    ['iata' => 'JNU', 'name' => 'Juneau International Airport', 'city' => 'Juneau', 'state' => 'AK', 'timezone' => 'America/Juneau'],

    // AL
    ['iata' => 'BHM', 'name' => 'Birmingham International Airport', 'city' => 'Birmingham', 'state' => 'AL', 'timezone' => 'America/Chicago'],
    ['iata' => 'DHN', 'name' => 'Dothan Regional Airport', 'city' => 'Dothan', 'state' => 'AL', 'timezone' => 'America/Chicago'],
    ['iata' => 'HSV', 'name' => 'Huntsville International Airport', 'city' => 'Huntsville', 'state' => 'AL', 'timezone' => 'America/Chicago'],
    ['iata' => 'MGM', 'name' => 'Montgomery', 'city' => 'Montgomery', 'state' => 'AL', 'timezone' => 'America/Chicago'],
    ['iata' => 'MOB', 'name' => 'Mobile', 'city' => 'Mobile', 'state' => 'AL', 'timezone' => 'America/Chicago'],

    // AR
    ['iata' => 'FYV', 'name' => 'Fayetteville', 'city' => 'Fayetteville', 'state' => 'AR', 'timezone' => 'America/Chicago'],
    ['iata' => 'LIT', 'name' => 'Little Rock National Airport', 'city' => 'Little Rock', 'state' => 'AR', 'timezone' => 'America/Chicago'],
    ['iata' => 'XNA', 'name' => 'Northwest Arkansas Regional Airport', 'city' => 'Northwest Arkansas', 'state' => 'AR', 'timezone' => 'America/Chicago'],

    // AZ
    ['iata' => 'FLG', 'name' => 'Flagstaff', 'city' => 'Flagstaff', 'state' => 'AZ', 'timezone' => 'America/Phoenix'],
    ['iata' => 'PHX', 'name' => 'Phoenix Sky Harbor International Airport', 'city' => 'Phoenix', 'state' => 'AZ', 'timezone' => 'America/Phoenix'],
    ['iata' => 'TUS', 'name' => 'Tucson International Airport', 'city' => 'Tucson', 'state' => 'AZ', 'timezone' => 'America/Phoenix'],
    ['iata' => 'YUM', 'name' => 'Yuma International Airport', 'city' => 'Yuma', 'state' => 'AZ', 'timezone' => 'America/Phoenix'],

    // CA
    ['iata' => 'BUR', 'name' => 'Burbank', 'city' => 'Burbank', 'state' => 'CA', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'FAT', 'name' => 'Fresno', 'city' => 'Fresno', 'state' => 'CA', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'LAX', 'name' => 'Los Angeles International Airport', 'city' => 'Los Angeles', 'state' => 'CA', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'LGB', 'name' => 'Long Beach', 'city' => 'Long Beach', 'state' => 'CA', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'OAK', 'name' => 'Oakland', 'city' => 'Oakland', 'state' => 'CA', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'ONT', 'name' => 'Ontario', 'city' => 'Ontario', 'state' => 'CA', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'PSP', 'name' => 'Palm Springs', 'city' => 'Palm Springs', 'state' => 'CA', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'SAN', 'name' => 'San Diego', 'city' => 'San Diego', 'state' => 'CA', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'SFO', 'name' => 'San Francisco International Airport', 'city' => 'San Francisco', 'state' => 'CA', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'SJC', 'name' => 'San Jose', 'city' => 'San Jose', 'state' => 'CA', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'SMF', 'name' => 'Sacramento', 'city' => 'Sacramento', 'state' => 'CA', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'SNA', 'name' => 'Santa Ana', 'city' => 'Santa Ana', 'state' => 'CA', 'timezone' => 'America/Los_Angeles'],

    // CO
    ['iata' => 'ASE', 'name' => 'Aspen', 'city' => 'Aspen', 'state' => 'CO', 'timezone' => 'America/Denver'],
    ['iata' => 'COS', 'name' => 'Colorado Springs', 'city' => 'Colorado Springs', 'state' => 'CO', 'timezone' => 'America/Denver'],
    ['iata' => 'DEN', 'name' => 'Denver International Airport', 'city' => 'Denver', 'state' => 'CO', 'timezone' => 'America/Denver'],
    ['iata' => 'GJT', 'name' => 'Grand Junction', 'city' => 'Grand Junction', 'state' => 'CO', 'timezone' => 'America/Denver'],
    ['iata' => 'PUB', 'name' => 'Pueblo', 'city' => 'Pueblo', 'state' => 'CO', 'timezone' => 'America/Denver'],

    // CT
    ['iata' => 'BDL', 'name' => 'Hartford', 'city' => 'Hartford', 'state' => 'CT', 'timezone' => 'America/New_York'],
    ['iata' => 'HVN', 'name' => 'Tweed New Haven', 'city' => 'New Haven', 'state' => 'CT', 'timezone' => 'America/New_York'],

    // DC
    ['iata' => 'DCA', 'name' => 'Ronald Reagan Washington National Airport', 'city' => 'Washington', 'state' => 'DC', 'timezone' => 'America/New_York'],

    // FL
    ['iata' => 'DAB', 'name' => 'Daytona Beach', 'city' => 'Daytona Beach', 'state' => 'FL', 'timezone' => 'America/New_York'],
    ['iata' => 'EYW', 'name' => 'Key West International Airport', 'city' => 'Key West', 'state' => 'FL', 'timezone' => 'America/New_York'],
    ['iata' => 'FLL', 'name' => 'Fort Lauderdale-Hollywood International Airport', 'city' => 'Fort Lauderdale', 'state' => 'FL', 'timezone' => 'America/New_York'],
    ['iata' => 'JAX', 'name' => 'Jacksonville', 'city' => 'Jacksonville', 'state' => 'FL', 'timezone' => 'America/New_York'],
    ['iata' => 'MCO', 'name' => 'Orlando', 'city' => 'Orlando', 'state' => 'FL', 'timezone' => 'America/New_York'],
    ['iata' => 'MIA', 'name' => 'Miami International Airport', 'city' => 'Miami', 'state' => 'FL', 'timezone' => 'America/New_York'],
    ['iata' => 'PBI', 'name' => 'West Palm Beach', 'city' => 'West Palm Beach', 'state' => 'FL', 'timezone' => 'America/New_York'],
    ['iata' => 'PFN', 'name' => 'Panama City-Bay County International Airport', 'city' => 'Panama City', 'state' => 'FL', 'timezone' => 'America/Chicago'],
    ['iata' => 'PIE', 'name' => 'St. Petersburg', 'city' => 'St. Petersburg', 'state' => 'FL', 'timezone' => 'America/New_York'],
    ['iata' => 'PNS', 'name' => 'Pensacola', 'city' => 'Pensacola', 'state' => 'FL', 'timezone' => 'America/Chicago'],
    ['iata' => 'RSW', 'name' => 'Fort Myers', 'city' => 'Fort Myers', 'state' => 'FL', 'timezone' => 'America/New_York'],
    ['iata' => 'SRQ', 'name' => 'Sarasota', 'city' => 'Sarasota', 'state' => 'FL', 'timezone' => 'America/New_York'],
    ['iata' => 'TPA', 'name' => 'Tampa', 'city' => 'Tampa', 'state' => 'FL', 'timezone' => 'America/New_York'],

    // GA
    ['iata' => 'AGS', 'name' => 'Augusta', 'city' => 'Augusta', 'state' => 'GA', 'timezone' => 'America/New_York'],
    ['iata' => 'ATL', 'name' => 'Atlanta Hartsfield International Airport', 'city' => 'Atlanta', 'state' => 'GA', 'timezone' => 'America/New_York'],
    ['iata' => 'SAV', 'name' => 'Savannah', 'city' => 'Savannah', 'state' => 'GA', 'timezone' => 'America/New_York'],

    // HI
    ['iata' => 'HNL', 'name' => 'Honolulu International Airport', 'city' => 'Honolulu', 'state' => 'HI', 'timezone' => 'Pacific/Honolulu'],
    ['iata' => 'ITO', 'name' => 'Hilo', 'city' => 'Hilo', 'state' => 'HI', 'timezone' => 'Pacific/Honolulu'],
    ['iata' => 'KOA', 'name' => 'Kailua', 'city' => 'Kailua-Kona', 'state' => 'HI', 'timezone' => 'Pacific/Honolulu'],
    ['iata' => 'LIH', 'name' => 'Lihue', 'city' => 'Lihue', 'state' => 'HI', 'timezone' => 'Pacific/Honolulu'],
    ['iata' => 'OGG', 'name' => 'Kahului', 'city' => 'Kahului', 'state' => 'HI', 'timezone' => 'Pacific/Honolulu'],

    // IA
    ['iata' => 'CID', 'name' => 'Cedar Rapids', 'city' => 'Cedar Rapids', 'state' => 'IA', 'timezone' => 'America/Chicago'],
    ['iata' => 'DSM', 'name' => 'Des Moines', 'city' => 'Des Moines', 'state' => 'IA', 'timezone' => 'America/Chicago'],

    // ID
    ['iata' => 'BOI', 'name' => 'Boise', 'city' => 'Boise', 'state' => 'ID', 'timezone' => 'America/Boise'],

    // IL
    ['iata' => 'MDW', 'name' => 'Chicago Midway Airport', 'city' => 'Chicago', 'state' => 'IL', 'timezone' => 'America/Chicago'],
    ['iata' => 'MLI', 'name' => 'Moline', 'city' => 'Moline', 'state' => 'IL', 'timezone' => 'America/Chicago'],
    ['iata' => 'ORD', 'name' => 'Chicago O\'Hare International Airport', 'city' => 'Chicago', 'state' => 'IL', 'timezone' => 'America/Chicago'],
    ['iata' => 'PIA', 'name' => 'Peoria', 'city' => 'Peoria', 'state' => 'IL', 'timezone' => 'America/Chicago'],

    // IN
    ['iata' => 'EVV', 'name' => 'Evansville', 'city' => 'Evansville', 'state' => 'IN', 'timezone' => 'America/Chicago'],
    ['iata' => 'FWA', 'name' => 'Fort Wayne', 'city' => 'Fort Wayne', 'state' => 'IN', 'timezone' => 'America/Indiana/Indianapolis'],
    ['iata' => 'IND', 'name' => 'Indianapolis International Airport', 'city' => 'Indianapolis', 'state' => 'IN', 'timezone' => 'America/Indiana/Indianapolis'],
    ['iata' => 'SBN', 'name' => 'South Bend', 'city' => 'South Bend', 'state' => 'IN', 'timezone' => 'America/Indiana/Indianapolis'],

    // KS
    ['iata' => 'ICT', 'name' => 'Wichita', 'city' => 'Wichita', 'state' => 'KS', 'timezone' => 'America/Chicago'],

    // KY
    ['iata' => 'LEX', 'name' => 'Lexington', 'city' => 'Lexington', 'state' => 'KY', 'timezone' => 'America/New_York'],
    ['iata' => 'SDF', 'name' => 'Louisville', 'city' => 'Louisville', 'state' => 'KY', 'timezone' => 'America/Kentucky/Louisville'],

    // LA
    ['iata' => 'BTR', 'name' => 'Baton Rouge', 'city' => 'Baton Rouge', 'state' => 'LA', 'timezone' => 'America/Chicago'],
    ['iata' => 'MSY', 'name' => 'New Orleans International Airport', 'city' => 'New Orleans', 'state' => 'LA', 'timezone' => 'America/Chicago'],
    ['iata' => 'SHV', 'name' => 'Shreveport', 'city' => 'Shreveport', 'state' => 'LA', 'timezone' => 'America/Chicago'],

    // MA
    ['iata' => 'ACK', 'name' => 'Nantucket', 'city' => 'Nantucket', 'state' => 'MA', 'timezone' => 'America/New_York'],
    ['iata' => 'BOS', 'name' => 'Boston, Logan International Airport', 'city' => 'Boston', 'state' => 'MA', 'timezone' => 'America/New_York'],
    ['iata' => 'HYA', 'name' => 'Hyannis', 'city' => 'Hyannis', 'state' => 'MA', 'timezone' => 'America/New_York'],
    ['iata' => 'ORH', 'name' => 'Worcester', 'city' => 'Worcester', 'state' => 'MA', 'timezone' => 'America/New_York'],

    // MD
    ['iata' => 'BWI', 'name' => 'Baltimore', 'city' => 'Baltimore', 'state' => 'MD', 'timezone' => 'America/New_York'],

    // ME
    ['iata' => 'AUG', 'name' => 'Augusta', 'city' => 'Augusta', 'state' => 'ME', 'timezone' => 'America/New_York'],
    ['iata' => 'BGR', 'name' => 'Bangor', 'city' => 'Bangor', 'state' => 'ME', 'timezone' => 'America/New_York'],
    ['iata' => 'PWM', 'name' => 'Portland', 'city' => 'Portland', 'state' => 'ME', 'timezone' => 'America/New_York'],

    // MI
    ['iata' => 'AZO', 'name' => 'Kalamazoo-Battle Creek International Airport', 'city' => 'Kalamazoo', 'state' => 'MI', 'timezone' => 'America/Detroit'],
    ['iata' => 'BTL', 'name' => 'Battle Creek', 'city' => 'Battle Creek', 'state' => 'MI', 'timezone' => 'America/New_York'],
    ['iata' => 'DET', 'name' => 'Detroit', 'city' => 'Detroit', 'state' => 'MI', 'timezone' => 'America/Detroit'],
    ['iata' => 'DTW', 'name' => 'Detroit Metropolitan Airport', 'city' => 'Detroit', 'state' => 'MI', 'timezone' => 'America/Detroit'],
    ['iata' => 'FNT', 'name' => 'Flint', 'city' => 'Flint', 'state' => 'MI', 'timezone' => 'America/Detroit'],
    ['iata' => 'GRR', 'name' => 'Grand Rapids', 'city' => 'Grand Rapids', 'state' => 'MI', 'timezone' => 'America/Detroit'],
    ['iata' => 'LAN', 'name' => 'Lansing', 'city' => 'Lansing', 'state' => 'MI', 'timezone' => 'America/Detroit'],
    ['iata' => 'MBS', 'name' => 'Saginaw', 'city' => 'Saginaw', 'state' => 'MI', 'timezone' => 'America/Detroit'],

    // MN
    ['iata' => 'DLH', 'name' => 'Duluth', 'city' => 'Duluth', 'state' => 'MN', 'timezone' => 'America/Chicago'],
    ['iata' => 'MSP', 'name' => 'Minneapolis/St. Paul International Airport', 'city' => 'Minneapolis', 'state' => 'MN', 'timezone' => 'America/Chicago'],
    ['iata' => 'RST', 'name' => 'Rochester', 'city' => 'Rochester', 'state' => 'MN', 'timezone' => 'America/Chicago'],

    // MO
    ['iata' => 'MCI', 'name' => 'Kansas City', 'city' => 'Kansas City', 'state' => 'MO', 'timezone' => 'America/Chicago'],
    ['iata' => 'SGF', 'name' => 'Springfield', 'city' => 'Springfield', 'state' => 'MO', 'timezone' => 'America/Chicago'],
    ['iata' => 'STL', 'name' => 'St Louis, Lambert International Airport', 'city' => 'St. Louis', 'state' => 'MO', 'timezone' => 'America/Chicago'],

    // MS
    ['iata' => 'GPT', 'name' => 'Gulfport', 'city' => 'Gulfport', 'state' => 'MS', 'timezone' => 'America/Chicago'],
    ['iata' => 'JAN', 'name' => 'Jackson', 'city' => 'Jackson', 'state' => 'MS', 'timezone' => 'America/Chicago'],

    // MT
    ['iata' => 'BIL', 'name' => 'Billings', 'city' => 'Billings', 'state' => 'MT', 'timezone' => 'America/Denver'],

    // NC
    ['iata' => 'AVL', 'name' => 'Asheville', 'city' => 'Asheville', 'state' => 'NC', 'timezone' => 'America/New_York'],
    ['iata' => 'CLT', 'name' => 'Charlotte/Douglas International Airport', 'city' => 'Charlotte', 'state' => 'NC', 'timezone' => 'America/New_York'],
    ['iata' => 'FAY', 'name' => 'Fayetteville', 'city' => 'Fayetteville', 'state' => 'NC', 'timezone' => 'America/New_York'],
    ['iata' => 'GSO', 'name' => 'Greensboro', 'city' => 'Greensboro', 'state' => 'NC', 'timezone' => 'America/New_York'],
    ['iata' => 'INT', 'name' => 'Winston-Salem', 'city' => 'Winston-Salem', 'state' => 'NC', 'timezone' => 'America/New_York'],
    ['iata' => 'RDU', 'name' => 'Raleigh', 'city' => 'Raleigh', 'state' => 'NC', 'timezone' => 'America/New_York'],

    // ND
    ['iata' => 'BIS', 'name' => 'Bismarck', 'city' => 'Bismarck', 'state' => 'ND', 'timezone' => 'America/Chicago'],
    ['iata' => 'FAR', 'name' => 'Fargo', 'city' => 'Fargo', 'state' => 'ND', 'timezone' => 'America/Chicago'],

    // NE
    ['iata' => 'LNK', 'name' => 'Lincoln', 'city' => 'Lincoln', 'state' => 'NE', 'timezone' => 'America/Chicago'],
    ['iata' => 'OMA', 'name' => 'Omaha', 'city' => 'Omaha', 'state' => 'NE', 'timezone' => 'America/Chicago'],

    // NH
    ['iata' => 'MHT', 'name' => 'Manchester', 'city' => 'Manchester', 'state' => 'NH', 'timezone' => 'America/New_York'],

    // NJ
    ['iata' => 'ACY', 'name' => 'Atlantic City International Airport', 'city' => 'Atlantic City', 'state' => 'NJ', 'timezone' => 'America/New_York'],
    ['iata' => 'EWR', 'name' => 'Newark International Airport', 'city' => 'Newark', 'state' => 'NJ', 'timezone' => 'America/New_York'],
    ['iata' => 'TTN', 'name' => 'Trenton', 'city' => 'Trenton', 'state' => 'NJ', 'timezone' => 'America/New_York'],

    // NM
    ['iata' => 'ABQ', 'name' => 'Albuquerque International Airport', 'city' => 'Albuquerque', 'state' => 'NM', 'timezone' => 'America/Denver'],
    ['iata' => 'ALM', 'name' => 'Alamogordo', 'city' => 'Alamogordo', 'state' => 'NM', 'timezone' => 'America/Denver'],

    // NV
    ['iata' => 'LAS', 'name' => 'Harry Reid Las Vegas International Airport', 'city' => 'Las Vegas', 'state' => 'NV', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'RNO', 'name' => 'Reno-Tahoe International Airport', 'city' => 'Reno', 'state' => 'NV', 'timezone' => 'America/Los_Angeles'],

    // NY
    ['iata' => 'ALB', 'name' => 'Albany International Airport', 'city' => 'Albany', 'state' => 'NY', 'timezone' => 'America/New_York'],
    ['iata' => 'BUF', 'name' => 'Buffalo', 'city' => 'Buffalo', 'state' => 'NY', 'timezone' => 'America/New_York'],
    ['iata' => 'HPN', 'name' => 'Westchester', 'city' => 'White Plains', 'state' => 'NY', 'timezone' => 'America/New_York'],
    ['iata' => 'ISP', 'name' => 'Islip', 'city' => 'Islip', 'state' => 'NY', 'timezone' => 'America/New_York'],
    ['iata' => 'JFK', 'name' => 'New York, John F Kennedy International Airport', 'city' => 'New York', 'state' => 'NY', 'timezone' => 'America/New_York'],
    ['iata' => 'LGA', 'name' => 'New York, La Guardia Airport', 'city' => 'New York', 'state' => 'NY', 'timezone' => 'America/New_York'],
    ['iata' => 'ROC', 'name' => 'Rochester', 'city' => 'Rochester', 'state' => 'NY', 'timezone' => 'America/New_York'],
    ['iata' => 'SWF', 'name' => 'Newburgh', 'city' => 'Newburgh', 'state' => 'NY', 'timezone' => 'America/New_York'],
    ['iata' => 'SYR', 'name' => 'Syracuse', 'city' => 'Syracuse', 'state' => 'NY', 'timezone' => 'America/New_York'],

    // OH
    ['iata' => 'CAK', 'name' => 'Akron', 'city' => 'Akron', 'state' => 'OH', 'timezone' => 'America/New_York'],
    ['iata' => 'CLE', 'name' => 'Cleveland', 'city' => 'Cleveland', 'state' => 'OH', 'timezone' => 'America/New_York'],
    ['iata' => 'CMH', 'name' => 'Columbus', 'city' => 'Columbus', 'state' => 'OH', 'timezone' => 'America/New_York'],
    ['iata' => 'CVG', 'name' => 'Cincinnati', 'city' => 'Cincinnati', 'state' => 'OH', 'timezone' => 'America/New_York'],
    ['iata' => 'DAY', 'name' => 'Dayton', 'city' => 'Dayton', 'state' => 'OH', 'timezone' => 'America/New_York'],
    ['iata' => 'TOL', 'name' => 'Toledo', 'city' => 'Toledo', 'state' => 'OH', 'timezone' => 'America/New_York'],

    // OK
    ['iata' => 'OKC', 'name' => 'Oklahoma City', 'city' => 'Oklahoma City', 'state' => 'OK', 'timezone' => 'America/Chicago'],
    ['iata' => 'TUL', 'name' => 'Tulsa', 'city' => 'Tulsa', 'state' => 'OK', 'timezone' => 'America/Chicago'],

    // OR
    ['iata' => 'EUG', 'name' => 'Eugene', 'city' => 'Eugene', 'state' => 'OR', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'HIO', 'name' => 'Portland, Hillsboro Airport', 'city' => 'Hillsboro', 'state' => 'OR', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'PDX', 'name' => 'Portland International Airport', 'city' => 'Portland', 'state' => 'OR', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'SLE', 'name' => 'Salem', 'city' => 'Salem', 'state' => 'OR', 'timezone' => 'America/Los_Angeles'],

    // PA
    ['iata' => 'ABE', 'name' => 'Allentown', 'city' => 'Allentown', 'state' => 'PA', 'timezone' => 'America/New_York'],
    ['iata' => 'AVP', 'name' => 'Scranton', 'city' => 'Scranton', 'state' => 'PA', 'timezone' => 'America/New_York'],
    ['iata' => 'ERI', 'name' => 'Erie', 'city' => 'Erie', 'state' => 'PA', 'timezone' => 'America/New_York'],
    ['iata' => 'MDT', 'name' => 'Harrisburg', 'city' => 'Harrisburg', 'state' => 'PA', 'timezone' => 'America/New_York'],
    ['iata' => 'PHL', 'name' => 'Philadelphia', 'city' => 'Philadelphia', 'state' => 'PA', 'timezone' => 'America/New_York'],
    ['iata' => 'PIT', 'name' => 'Pittsburgh', 'city' => 'Pittsburgh', 'state' => 'PA', 'timezone' => 'America/New_York'],

    // RI
    ['iata' => 'PVD', 'name' => 'Providence - T.F. Green Airport', 'city' => 'Providence', 'state' => 'RI', 'timezone' => 'America/New_York'],

    // SC
    ['iata' => 'CAE', 'name' => 'Columbia', 'city' => 'Columbia', 'state' => 'SC', 'timezone' => 'America/New_York'],
    ['iata' => 'CHS', 'name' => 'Charleston', 'city' => 'Charleston', 'state' => 'SC', 'timezone' => 'America/New_York'],
    ['iata' => 'GSP', 'name' => 'Greenville', 'city' => 'Greenville', 'state' => 'SC', 'timezone' => 'America/New_York'],
    ['iata' => 'MYR', 'name' => 'Myrtle Beach', 'city' => 'Myrtle Beach', 'state' => 'SC', 'timezone' => 'America/New_York'],

    // SD
    ['iata' => 'FSD', 'name' => 'Sioux Falls', 'city' => 'Sioux Falls', 'state' => 'SD', 'timezone' => 'America/Chicago'],
    ['iata' => 'PIR', 'name' => 'Pierre', 'city' => 'Pierre', 'state' => 'SD', 'timezone' => 'America/Chicago'],
    ['iata' => 'RAP', 'name' => 'Rapid City', 'city' => 'Rapid City', 'state' => 'SD', 'timezone' => 'America/Denver'],

    // TN
    ['iata' => 'BNA', 'name' => 'Nashville', 'city' => 'Nashville', 'state' => 'TN', 'timezone' => 'America/Chicago'],
    ['iata' => 'CHA', 'name' => 'Chattanooga', 'city' => 'Chattanooga', 'state' => 'TN', 'timezone' => 'America/New_York'],
    ['iata' => 'MEM', 'name' => 'Memphis', 'city' => 'Memphis', 'state' => 'TN', 'timezone' => 'America/Chicago'],
    ['iata' => 'TRI', 'name' => 'Bristol', 'city' => 'Bristol', 'state' => 'TN', 'timezone' => 'America/New_York'],
    ['iata' => 'TYS', 'name' => 'Knoxville', 'city' => 'Knoxville', 'state' => 'TN', 'timezone' => 'America/New_York'],

    // TX
    ['iata' => 'AMA', 'name' => 'Amarillo', 'city' => 'Amarillo', 'state' => 'TX', 'timezone' => 'America/Chicago'],
    ['iata' => 'AUS', 'name' => 'Austin Bergstrom International Airport', 'city' => 'Austin', 'state' => 'TX', 'timezone' => 'America/Chicago'],
    ['iata' => 'CRP', 'name' => 'Corpus Christi', 'city' => 'Corpus Christi', 'state' => 'TX', 'timezone' => 'America/Chicago'],
    ['iata' => 'DAL', 'name' => 'Dallas Love Field Airport', 'city' => 'Dallas', 'state' => 'TX', 'timezone' => 'America/Chicago'],
    ['iata' => 'DFW', 'name' => 'Dallas/Fort Worth International Airport', 'city' => 'Dallas/Fort Worth', 'state' => 'TX', 'timezone' => 'America/Chicago'],
    ['iata' => 'ELP', 'name' => 'El Paso', 'city' => 'El Paso', 'state' => 'TX', 'timezone' => 'America/Denver'],
    ['iata' => 'HOU', 'name' => 'Houston, William B Hobby Airport', 'city' => 'Houston', 'state' => 'TX', 'timezone' => 'America/Chicago'],
    ['iata' => 'IAH', 'name' => 'Houston, George Bush Intercontinental Airport', 'city' => 'Houston', 'state' => 'TX', 'timezone' => 'America/Chicago'],
    ['iata' => 'LBB', 'name' => 'Lubbock', 'city' => 'Lubbock', 'state' => 'TX', 'timezone' => 'America/Chicago'],
    ['iata' => 'MAF', 'name' => 'Midland', 'city' => 'Midland', 'state' => 'TX', 'timezone' => 'America/Chicago'],
    ['iata' => 'SAT', 'name' => 'San Antonio International Airport', 'city' => 'San Antonio', 'state' => 'TX', 'timezone' => 'America/Chicago'],

    // UT
    ['iata' => 'SLC', 'name' => 'Salt Lake City', 'city' => 'Salt Lake City', 'state' => 'UT', 'timezone' => 'America/Denver'],

    // VA
    ['iata' => 'IAD', 'name' => 'Washington Dulles International Airport', 'city' => 'Dulles', 'state' => 'VA', 'timezone' => 'America/New_York'],
    ['iata' => 'ORF', 'name' => 'Norfolk', 'city' => 'Norfolk', 'state' => 'VA', 'timezone' => 'America/New_York'],
    ['iata' => 'PHF', 'name' => 'Newport News', 'city' => 'Newport News', 'state' => 'VA', 'timezone' => 'America/New_York'],
    ['iata' => 'RIC', 'name' => 'Richmond', 'city' => 'Richmond', 'state' => 'VA', 'timezone' => 'America/New_York'],
    ['iata' => 'ROA', 'name' => 'Roanoke', 'city' => 'Roanoke', 'state' => 'VA', 'timezone' => 'America/New_York'],

    // VT
    ['iata' => 'BTV', 'name' => 'Burlington', 'city' => 'Burlington', 'state' => 'VT', 'timezone' => 'America/New_York'],
    ['iata' => 'MPV', 'name' => 'Montpelier', 'city' => 'Montpelier', 'state' => 'VT', 'timezone' => 'America/New_York'],
    ['iata' => 'RUT', 'name' => 'Rutland', 'city' => 'Rutland', 'state' => 'VT', 'timezone' => 'America/New_York'],

    // WA
    ['iata' => 'GEG', 'name' => 'Spokane International Airport', 'city' => 'Spokane', 'state' => 'WA', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'PSC', 'name' => 'Pasco, Pasco/Tri-Cities Airport', 'city' => 'Pasco', 'state' => 'WA', 'timezone' => 'America/Los_Angeles'],
    ['iata' => 'SEA', 'name' => 'Seattle, Tacoma International Airport', 'city' => 'Seattle', 'state' => 'WA', 'timezone' => 'America/Los_Angeles'],

    // WI
    ['iata' => 'GRB', 'name' => 'Green Bay', 'city' => 'Green Bay', 'state' => 'WI', 'timezone' => 'America/Chicago'],
    ['iata' => 'MKE', 'name' => 'Milwaukee', 'city' => 'Milwaukee', 'state' => 'WI', 'timezone' => 'America/Chicago'],
    ['iata' => 'MSN', 'name' => 'Madison', 'city' => 'Madison', 'state' => 'WI', 'timezone' => 'America/Chicago'],

    // WV
    ['iata' => 'CKB', 'name' => 'Clarksburg', 'city' => 'Clarksburg', 'state' => 'WV', 'timezone' => 'America/New_York'],
    ['iata' => 'CRW', 'name' => 'Charleston', 'city' => 'Charleston', 'state' => 'WV', 'timezone' => 'America/New_York'],
    ['iata' => 'HTS', 'name' => 'Huntington Tri-State Airport', 'city' => 'Huntington', 'state' => 'WV', 'timezone' => 'America/New_York'],

    // WY
    ['iata' => 'CPR', 'name' => 'Casper', 'city' => 'Casper', 'state' => 'WY', 'timezone' => 'America/Denver'],
    ['iata' => 'CYS', 'name' => 'Cheyenne', 'city' => 'Cheyenne', 'state' => 'WY', 'timezone' => 'America/Denver'],
    ['iata' => 'JAC', 'name' => 'Jackson Hole', 'city' => 'Jackson', 'state' => 'WY', 'timezone' => 'America/Denver'],
    ['iata' => 'RKS', 'name' => 'Rock Springs', 'city' => 'Rock Springs', 'state' => 'WY', 'timezone' => 'America/Denver'],

    // Nearby hubs often on US itineraries
    ['iata' => 'CUN', 'name' => 'Cancún International', 'city' => 'Cancún', 'state' => null, 'timezone' => 'America/Cancun'],
    ['iata' => 'MEX', 'name' => 'Mexico City International', 'city' => 'Mexico City', 'state' => null, 'timezone' => 'America/Mexico_City'],
    ['iata' => 'YUL', 'name' => 'Montréal-Trudeau', 'city' => 'Montréal', 'state' => null, 'timezone' => 'America/Toronto'],
    ['iata' => 'YVR', 'name' => 'Vancouver International', 'city' => 'Vancouver', 'state' => null, 'timezone' => 'America/Vancouver'],
    ['iata' => 'YYZ', 'name' => 'Toronto Pearson', 'city' => 'Toronto', 'state' => null, 'timezone' => 'America/Toronto'],

];
