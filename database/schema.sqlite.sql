-- ============================================================================
-- NexWAYPOINT database schema (SQLite 3.35+)
-- Used for local/offline development and the automated test suite.
-- Mirrors database/schema.sql (MySQL). SQLite has no native ENUM/CHECK-on-
-- range-only sugar the same way, so enums are TEXT + CHECK constraints and
-- auto-increment ids are INTEGER PRIMARY KEY (SQLite rowid aliasing).
-- Keep both files in sync when the schema changes.
-- ============================================================================

PRAGMA foreign_keys = ON;

CREATE TABLE users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    username        TEXT NOT NULL UNIQUE,
    email           TEXT NOT NULL UNIQUE,
    password_hash   TEXT NOT NULL,
    display_name    TEXT NOT NULL,
    role            TEXT NOT NULL DEFAULT 'subordinate' CHECK (role IN ('manager','peer','subordinate')),
    manager_id      INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    is_admin        INTEGER NOT NULL DEFAULT 0,
    is_system       INTEGER NOT NULL DEFAULT 0,
    timezone        TEXT NOT NULL DEFAULT 'America/Chicago',
    is_active       INTEGER NOT NULL DEFAULT 1,
    photo_path      TEXT NULL,
    photo_focus_x   REAL NOT NULL DEFAULT 50,
    photo_focus_y   REAL NOT NULL DEFAULT 50,
    home_city       TEXT NULL,
    home_state      TEXT NULL,
    home_lat        REAL NULL,
    home_lon        REAL NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_users_manager ON users(manager_id);

CREATE TABLE user_dotted_managers (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    manager_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (user_id, manager_id)
);
CREATE INDEX idx_dotted_user ON user_dotted_managers(user_id);
CREATE INDEX idx_dotted_manager ON user_dotted_managers(manager_id);

-- Addresses that claim inbound confirmation mail for a user.
CREATE TABLE user_emails (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    email           TEXT NOT NULL UNIQUE,
    label           TEXT NULL,
    is_primary      INTEGER NOT NULL DEFAULT 0,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_user_emails_user ON user_emails(user_id);

CREATE TABLE user_status_overrides (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    status          TEXT NOT NULL CHECK (status IN ('home','office','remote','unavailable')),
    note            TEXT NULL,
    location_city   TEXT NULL,
    location_state  TEXT NULL,
    effective_date  TEXT NOT NULL,
    expires_on      TEXT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (user_id, effective_date)
);
CREATE INDEX idx_status_user_date ON user_status_overrides(user_id, effective_date);

CREATE TABLE hotel_properties (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    created_by_user_id      INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    hotel_name              TEXT NOT NULL,
    brand                   TEXT NULL,
    address_line1           TEXT NULL,
    address_line2           TEXT NULL,
    city                    TEXT NOT NULL DEFAULT '',
    state_region            TEXT NOT NULL DEFAULT '',
    postal_code             TEXT NULL,
    country                 TEXT NULL,
    phone                   TEXT NULL,
    website                 TEXT NULL,
    latitude                REAL NULL,
    longitude               REAL NULL,
    has_desk                INTEGER NOT NULL DEFAULT 0,
    desk_notes              TEXT NULL,
    has_pool                INTEGER NOT NULL DEFAULT 0,
    has_hot_tub             INTEGER NOT NULL DEFAULT 0,
    has_breakfast           INTEGER NOT NULL DEFAULT 0,
    breakfast_notes         TEXT NULL,
    has_gym                 INTEGER NOT NULL DEFAULT 0,
    has_free_parking        INTEGER NOT NULL DEFAULT 0,
    has_airport_shuttle     INTEGER NOT NULL DEFAULT 0,
    has_ev_charging         INTEGER NOT NULL DEFAULT 0,
    has_onsite_restaurant   INTEGER NOT NULL DEFAULT 0,
    has_offsite_gym         INTEGER NOT NULL DEFAULT 0,
    walk_to_office          INTEGER NOT NULL DEFAULT 0,
    walk_to_office_notes    TEXT NULL,
    has_destination_fee     INTEGER NOT NULL DEFAULT 0,
    destination_fee_notes   TEXT NULL,
    wifi_quality            INTEGER NULL CHECK (wifi_quality IS NULL OR wifi_quality BETWEEN 1 AND 5),
    noise_level             INTEGER NULL CHECK (noise_level IS NULL OR noise_level BETWEEN 1 AND 5),
    unique_features         TEXT NULL,
    overall_rating          REAL NULL CHECK (overall_rating IS NULL OR (overall_rating >= 0 AND overall_rating <= 5)),
    created_at              TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at              TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (hotel_name, city, state_region)
);
CREATE INDEX idx_prop_creator ON hotel_properties(created_by_user_id);
CREATE INDEX idx_prop_city ON hotel_properties(city);
CREATE INDEX idx_prop_name ON hotel_properties(hotel_name);

CREATE TABLE user_hotel_blacklist (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id             INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    hotel_property_id   INTEGER NOT NULL REFERENCES hotel_properties(id) ON DELETE CASCADE,
    reason              TEXT NULL,
    created_at          TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (user_id, hotel_property_id)
);
CREATE INDEX idx_uhb_property ON user_hotel_blacklist(hotel_property_id);

CREATE TABLE hotel_brands (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT NOT NULL UNIQUE,
    sort_order      INTEGER NOT NULL DEFAULT 0,
    is_active       INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
INSERT INTO hotel_brands (name, sort_order, is_active) VALUES
    ('Marriott', 10, 1),
    ('Hilton', 20, 1),
    ('IHG', 30, 1),
    ('Hyatt', 40, 1),
    ('Choice Hotels', 50, 1);

CREATE TABLE office_venues (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT NOT NULL UNIQUE,
    address_line1   TEXT NULL,
    city            TEXT NULL,
    state_region    TEXT NULL,
    postal_code     TEXT NULL,
    country         TEXT NOT NULL DEFAULT 'USA',
    latitude        REAL NULL,
    longitude       REAL NULL,
    notes           TEXT NULL,
    is_active       INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE hotel_stays (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id             INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    hotel_property_id   INTEGER NOT NULL REFERENCES hotel_properties(id) ON DELETE CASCADE,
    room_number         TEXT NULL,
    bed_type            TEXT NULL CHECK (bed_type IS NULL OR bed_type IN ('king','queen','dual_queen')),
    bathroom_type       TEXT NULL CHECK (bathroom_type IS NULL OR bathroom_type IN ('tub','walk_in_shower')),
    stay_start          TEXT NOT NULL,
    stay_end            TEXT NOT NULL,
    stay_rating         INTEGER NULL CHECK (stay_rating IS NULL OR stay_rating BETWEEN 0 AND 5),
    last_stay_price     REAL NULL,
    currency            TEXT NOT NULL DEFAULT 'USD',
    booking_source      TEXT NULL,
    confirmation_code   TEXT NULL,
    would_return        INTEGER NULL,
    notes               TEXT NULL,
    is_private          INTEGER NOT NULL DEFAULT 0,
    created_at          TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT NOT NULL DEFAULT (datetime('now')),
    CHECK (stay_end >= stay_start)
);
CREATE INDEX idx_hotel_user ON hotel_stays(user_id);
CREATE INDEX idx_hotel_property ON hotel_stays(hotel_property_id);
CREATE INDEX idx_hotel_private ON hotel_stays(is_private);
CREATE INDEX idx_hotel_dates ON hotel_stays(stay_start);

CREATE TABLE hotel_photos (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    hotel_stay_id   INTEGER NOT NULL REFERENCES hotel_stays(id) ON DELETE CASCADE,
    file_path       TEXT NOT NULL,
    caption         TEXT NULL,
    uploaded_at     TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_hotel_photos_stay ON hotel_photos(hotel_stay_id);

CREATE TABLE trips (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id            INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    destination_city    TEXT NOT NULL,
    start_date          TEXT NOT NULL,
    end_date            TEXT NOT NULL,
    status              TEXT NOT NULL DEFAULT 'planned' CHECK (status IN ('planned','active','completed','cancelled')),
    trip_purpose        TEXT NULL,
    notes               TEXT NULL,
    is_private          INTEGER NOT NULL DEFAULT 0,
    created_at          TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT NOT NULL DEFAULT (datetime('now')),
    CHECK (end_date >= start_date)
);
CREATE INDEX idx_trips_owner ON trips(owner_id);
CREATE INDEX idx_trips_dates ON trips(start_date, end_date);

CREATE TABLE carriers (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name            TEXT NOT NULL,
    iata_code       TEXT NULL,
    carrier_type    TEXT NOT NULL DEFAULT 'airline' CHECK (carrier_type IN ('airline','rail')),
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (carrier_type, iata_code)
);
CREATE INDEX idx_carriers_user ON carriers(user_id);
CREATE INDEX idx_carriers_name ON carriers(name);
CREATE INDEX idx_carriers_type ON carriers(carrier_type);

-- airports: IATA → IANA timezone for interpreting naive segment wall-clock times.
CREATE TABLE airports (
    iata        TEXT NOT NULL PRIMARY KEY,
    name        TEXT NULL,
    timezone    TEXT NOT NULL,
    latitude    REAL NULL,
    longitude   REAL NULL
);

CREATE TABLE trip_segments (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    trip_id             INTEGER NOT NULL REFERENCES trips(id) ON DELETE CASCADE,
    segment_type        TEXT NOT NULL CHECK (segment_type IN ('flight','hotel','train','car')),
    segment_subtype     TEXT NULL,
    carrier_id          INTEGER NULL REFERENCES carriers(id) ON DELETE SET NULL,
    carrier             TEXT NULL,
    flight_number       TEXT NULL,
    confirmation_code   TEXT NULL,
    origin              TEXT NULL,
    destination         TEXT NULL,
    depart_dt           TEXT NULL,
    arrive_dt           TEXT NULL,
    hotel_stay_id       INTEGER NULL REFERENCES hotel_stays(id) ON DELETE SET NULL,
    status              TEXT NOT NULL DEFAULT 'scheduled' CHECK (status IN ('scheduled','en_route','landed','delayed','cancelled','completed')),
    source_parse_log_id INTEGER NULL,
    created_at          TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_segments_trip ON trip_segments(trip_id);
CREATE INDEX idx_segments_carrier ON trip_segments(carrier_id);
CREATE INDEX idx_segments_depart ON trip_segments(depart_dt);
CREATE INDEX idx_segments_type ON trip_segments(segment_type);

CREATE TABLE flight_status (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    segment_id          INTEGER NOT NULL UNIQUE REFERENCES trip_segments(id) ON DELETE CASCADE,
    fa_flight_id        TEXT NULL,
    gate                TEXT NULL,
    terminal            TEXT NULL,
    scheduled_out       TEXT NULL,
    estimated_out       TEXT NULL,
    actual_out          TEXT NULL,
    scheduled_in        TEXT NULL,
    estimated_in        TEXT NULL,
    actual_in           TEXT NULL,
    status              TEXT NULL,
    progress_percent    INTEGER NULL,
    delay_minutes       INTEGER NOT NULL DEFAULT 0,
    airport_delay_info  TEXT NULL,
    last_checked_at     TEXT NULL
);

CREATE TABLE parse_log (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    received_at         TEXT NOT NULL,
    from_address        TEXT NOT NULL,
    subject             TEXT NULL,
    mail_uid            TEXT NOT NULL,
    source              TEXT NOT NULL,
    detected_type       TEXT NULL,
    parse_status        TEXT NOT NULL DEFAULT 'pending_review' CHECK (parse_status IN ('success','failed','pending_review','ignored')),
    failure_reason      TEXT NULL,
    confidence_score    REAL NULL,
    matched_user_id     INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    trip_segment_id     INTEGER NULL REFERENCES trip_segments(id) ON DELETE SET NULL,
    trip_id             INTEGER NULL REFERENCES trips(id) ON DELETE SET NULL,
    hotel_stay_id       INTEGER NULL REFERENCES hotel_stays(id) ON DELETE SET NULL,
    raw_path            TEXT NULL,
    raw_expires_at      TEXT NULL,
    created_at          TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (mail_uid, source)
);
CREATE INDEX idx_parse_log_status ON parse_log(parse_status);
CREATE INDEX idx_parse_log_received ON parse_log(received_at);

CREATE TABLE visibility_rules (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    subject_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    target_user_id  INTEGER NULL REFERENCES users(id) ON DELETE CASCADE,
    direction       TEXT NOT NULL CHECK (direction IN ('top_down','bottom_up','lateral','user_user')),
    field_name      TEXT NOT NULL,
    visible         INTEGER NOT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (subject_user_id, target_user_id, direction, field_name)
);
CREATE INDEX idx_vis_subject ON visibility_rules(subject_user_id);
CREATE INDEX idx_vis_target ON visibility_rules(target_user_id);

CREATE TABLE visibility_blocks (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_user_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    resource_type   TEXT NOT NULL CHECK (resource_type IN ('hotel_stay','trip')),
    resource_id     INTEGER NOT NULL,
    blocked_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (resource_type, resource_id, blocked_user_id)
);
CREATE INDEX idx_vis_block_resource ON visibility_blocks(resource_type, resource_id);
CREATE INDEX idx_vis_block_owner ON visibility_blocks(owner_user_id);

CREATE TABLE aeroapi_usage_log (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    usage_date          TEXT NOT NULL,
    endpoint            TEXT NOT NULL,
    calls               INTEGER NOT NULL DEFAULT 0,
    estimated_cost_usd  REAL NOT NULL DEFAULT 0,
    UNIQUE (usage_date, endpoint)
);

CREATE TABLE audit_log (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    occurred_at     TEXT NOT NULL DEFAULT (datetime('now')),
    actor_user_id   INTEGER NULL,
    action          TEXT NOT NULL,
    table_name      TEXT NOT NULL,
    record_id       INTEGER NULL,
    details         TEXT NULL
);
CREATE INDEX idx_audit_table ON audit_log(table_name, record_id);
CREATE INDEX idx_audit_actor ON audit_log(actor_user_id);

CREATE TABLE notifications (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    segment_id      INTEGER NULL REFERENCES trip_segments(id) ON DELETE CASCADE,
    alert_type      TEXT NOT NULL,
    message         TEXT NOT NULL,
    is_read         INTEGER NOT NULL DEFAULT 0,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_notifications_user ON notifications(user_id, is_read);
CREATE INDEX idx_notifications_segment ON notifications(segment_id);

CREATE TABLE cron_job_runs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    job_name        TEXT NOT NULL,
    started_at      TEXT NOT NULL,
    finished_at     TEXT NULL,
    status          TEXT NOT NULL DEFAULT 'running' CHECK (status IN ('running','ok','warning','failed')),
    summary_json    TEXT NULL,
    error_class     TEXT NULL,
    error_message   TEXT NULL
);
CREATE INDEX idx_cron_runs_job_started ON cron_job_runs(job_name, started_at);
CREATE INDEX idx_cron_runs_started ON cron_job_runs(started_at);

-- Site-wide UI / map appearance (admin Settings → Appearance).
CREATE TABLE site_settings (
    setting_key     TEXT PRIMARY KEY,
    setting_value   TEXT NOT NULL DEFAULT '',
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
