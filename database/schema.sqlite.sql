-- ============================================================================
-- NexWAYPONT database schema (SQLite 3.35+)
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
    timezone        TEXT NOT NULL DEFAULT 'America/Chicago',
    is_active       INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_users_manager ON users(manager_id);

CREATE TABLE user_status_overrides (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    status          TEXT NOT NULL CHECK (status IN ('home','office','remote','unavailable')),
    note            TEXT NULL,
    effective_date  TEXT NOT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (user_id, effective_date)
);
CREATE INDEX idx_status_user_date ON user_status_overrides(user_id, effective_date);

CREATE TABLE hotel_stays (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id             INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    hotel_name          TEXT NOT NULL,
    brand               TEXT NULL,
    address_line1       TEXT NULL,
    address_line2       TEXT NULL,
    city                TEXT NULL,
    state_region        TEXT NULL,
    postal_code         TEXT NULL,
    country             TEXT NULL,
    latitude            REAL NULL,
    longitude           REAL NULL,
    room_number         TEXT NULL,
    stay_start          TEXT NOT NULL,
    stay_end            TEXT NOT NULL,
    rating              INTEGER NULL CHECK (rating IS NULL OR rating BETWEEN 1 AND 5),
    has_desk            INTEGER NOT NULL DEFAULT 0,
    desk_notes          TEXT NULL,
    has_pool            INTEGER NOT NULL DEFAULT 0,
    has_hot_tub         INTEGER NOT NULL DEFAULT 0,
    has_breakfast       INTEGER NOT NULL DEFAULT 0,
    breakfast_notes     TEXT NULL,
    has_gym             INTEGER NOT NULL DEFAULT 0,
    has_free_parking    INTEGER NOT NULL DEFAULT 0,
    has_airport_shuttle INTEGER NOT NULL DEFAULT 0,
    wifi_quality        INTEGER NULL CHECK (wifi_quality IS NULL OR wifi_quality BETWEEN 1 AND 5),
    noise_level         INTEGER NULL CHECK (noise_level IS NULL OR noise_level BETWEEN 1 AND 5),
    unique_features     TEXT NULL,
    is_blacklisted      INTEGER NOT NULL DEFAULT 0,
    blacklist_reason    TEXT NULL,
    last_stay_price     REAL NULL,
    currency            TEXT NOT NULL DEFAULT 'USD',
    booking_source      TEXT NULL,
    confirmation_code   TEXT NULL,
    would_return        INTEGER NULL,
    notes               TEXT NULL,
    created_at          TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT NOT NULL DEFAULT (datetime('now')),
    CHECK (stay_end >= stay_start)
);
CREATE INDEX idx_hotel_user ON hotel_stays(user_id);
CREATE INDEX idx_hotel_city ON hotel_stays(city);
CREATE INDEX idx_hotel_blacklist ON hotel_stays(is_blacklisted);
CREATE INDEX idx_hotel_name ON hotel_stays(hotel_name);

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

CREATE TABLE trip_segments (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    trip_id             INTEGER NOT NULL REFERENCES trips(id) ON DELETE CASCADE,
    segment_type        TEXT NOT NULL CHECK (segment_type IN ('flight','hotel','train','car')),
    segment_subtype     TEXT NULL,
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
    created_at          TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (mail_uid, source)
);
CREATE INDEX idx_parse_log_status ON parse_log(parse_status);

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
