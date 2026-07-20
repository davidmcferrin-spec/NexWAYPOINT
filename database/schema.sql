-- ============================================================================
-- NexWAYPOINT database schema (MySQL 8.0+ / MariaDB 10.5+)
-- Charset utf8mb4, InnoDB for FK + transaction support.
-- SQLite-compatible variant: database/schema.sqlite.sql (local dev / offline testing)
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- users: org hierarchy via manager_id (solid line). is_admin gates site-admin
-- screens. is_system marks the seeded bootstrap account — isolated from the
-- org chart / reporting lines. Legacy `role` is unused by the UI; visibility
-- uses manager_id + dotted lines (user_dotted_managers).
-- ----------------------------------------------------------------------------
CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(100) NOT NULL UNIQUE,
    email           VARCHAR(255) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    display_name    VARCHAR(150) NOT NULL,
    role            ENUM('manager','peer','subordinate') NOT NULL DEFAULT 'subordinate',
    manager_id      INT UNSIGNED NULL,
    is_admin        TINYINT(1) NOT NULL DEFAULT 0,
    is_system       TINYINT(1) NOT NULL DEFAULT 0,
    timezone        VARCHAR(64) NOT NULL DEFAULT 'America/Chicago',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_users_manager (manager_id)
) ENGINE=InnoDB;

-- Dotted-line (matrix) managers.
CREATE TABLE user_dotted_managers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    manager_id      INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dotted_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_dotted_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_dotted (user_id, manager_id),
    INDEX idx_dotted_user (user_id),
    INDEX idx_dotted_manager (manager_id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- user_emails: addresses that claim inbound confirmation mail for a user.
-- users.email remains the primary/login contact; every address used to
-- forward airline/hotel mail into the dump mailbox must appear here.
-- Emails are globally unique so one address cannot map to two accounts.
-- ----------------------------------------------------------------------------
CREATE TABLE user_emails (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    email           VARCHAR(255) NOT NULL,
    label           VARCHAR(100) NULL,
    is_primary      TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_emails_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_user_emails_email (email),
    INDEX idx_user_emails_user (user_id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- user_status_overrides: manual status (home/office/remote/unavailable) used
-- by TripStatusEngine when there is no active travel segment covering "now".
-- One row per user per effective_date; latest wins if multiple.
-- ----------------------------------------------------------------------------
CREATE TABLE user_status_overrides (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    status          ENUM('home','office','remote','unavailable') NOT NULL,
    note            VARCHAR(255) NULL,
    effective_date  DATE NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_status_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_user_status_date (user_id, effective_date),
    INDEX idx_status_user_date (user_id, effective_date)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- hotel_properties: reusable property identity + amenities per user.
-- Overall rating is AVG(stay_rating) from linked hotel_stays.
-- ----------------------------------------------------------------------------
CREATE TABLE hotel_properties (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                 INT UNSIGNED NOT NULL,
    hotel_name              VARCHAR(200) NOT NULL,
    brand                   VARCHAR(100) NULL,
    address_line1           VARCHAR(200) NULL,
    address_line2           VARCHAR(200) NULL,
    city                    VARCHAR(120) NULL,
    state_region            VARCHAR(120) NULL,
    postal_code             VARCHAR(20) NULL,
    country                 VARCHAR(80) NULL,
    phone                   VARCHAR(40) NULL,
    latitude                DECIMAL(10,7) NULL,
    longitude               DECIMAL(10,7) NULL,
    has_desk                TINYINT(1) NOT NULL DEFAULT 0,
    desk_notes              VARCHAR(255) NULL,
    has_pool                TINYINT(1) NOT NULL DEFAULT 0,
    has_hot_tub             TINYINT(1) NOT NULL DEFAULT 0,
    has_breakfast           TINYINT(1) NOT NULL DEFAULT 0,
    breakfast_notes         VARCHAR(255) NULL,
    has_gym                 TINYINT(1) NOT NULL DEFAULT 0,
    has_free_parking        TINYINT(1) NOT NULL DEFAULT 0,
    has_airport_shuttle     TINYINT(1) NOT NULL DEFAULT 0,
    has_ev_charging         TINYINT(1) NOT NULL DEFAULT 0,
    has_onsite_restaurant   TINYINT(1) NOT NULL DEFAULT 0,
    has_offsite_gym         TINYINT(1) NOT NULL DEFAULT 0,
    walk_to_office          TINYINT(1) NOT NULL DEFAULT 0,
    walk_to_office_notes    VARCHAR(255) NULL,
    has_destination_fee     TINYINT(1) NOT NULL DEFAULT 0,
    destination_fee_notes   VARCHAR(255) NULL,
    wifi_quality            TINYINT UNSIGNED NULL,
    noise_level             TINYINT UNSIGNED NULL,
    unique_features         TEXT NULL,
    is_blacklisted          TINYINT(1) NOT NULL DEFAULT 0,
    blacklist_reason        TEXT NULL,
    overall_rating          DECIMAL(3,2) NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_hotel_properties_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_prop_wifi CHECK (wifi_quality IS NULL OR wifi_quality BETWEEN 1 AND 5),
    CONSTRAINT chk_prop_noise CHECK (noise_level IS NULL OR noise_level BETWEEN 1 AND 5),
    CONSTRAINT chk_prop_overall CHECK (overall_rating IS NULL OR (overall_rating >= 1 AND overall_rating <= 5)),
    INDEX idx_prop_user (user_id),
    INDEX idx_prop_city (city),
    INDEX idx_prop_blacklist (is_blacklisted),
    INDEX idx_prop_name (hotel_name)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- hotel_brands: site-wide catalog for the property Brand dropdown.
-- hotel_properties.brand stays free-text so mail import / legacy values work;
-- the UI only offers active rows from this table (plus the current value).
-- ----------------------------------------------------------------------------
CREATE TABLE hotel_brands (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    sort_order      INT NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hotel_brands_name (name)
) ENGINE=InnoDB;

INSERT INTO hotel_brands (name, sort_order, is_active) VALUES
    ('Marriott', 10, 1),
    ('Hilton', 20, 1),
    ('IHG', 30, 1),
    ('Hyatt', 40, 1),
    ('Choice Hotels', 50, 1);

-- ----------------------------------------------------------------------------
-- office_venues: site-wide offices / work sites for the walk-to-venue
-- combobox and hotel map pins. Managed under Site settings.
-- ----------------------------------------------------------------------------
CREATE TABLE office_venues (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    address_line1   VARCHAR(255) NULL,
    city            VARCHAR(100) NULL,
    state_region    VARCHAR(100) NULL,
    postal_code     VARCHAR(20) NULL,
    country         VARCHAR(100) NOT NULL DEFAULT 'USA',
    latitude        DECIMAL(10, 7) NULL,
    longitude       DECIMAL(10, 7) NULL,
    notes           VARCHAR(255) NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_office_venues_name (name)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- hotel_stays: individual visits. Room # / bed / bath / stay_rating are
-- stay-specific; amenities live on hotel_properties.
-- ----------------------------------------------------------------------------
CREATE TABLE hotel_stays (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,
    hotel_property_id   INT UNSIGNED NOT NULL,
    room_number         VARCHAR(20) NULL,
    bed_type            ENUM('king','queen','dual_queen') NULL,
    bathroom_type       ENUM('tub','walk_in_shower') NULL,
    stay_start          DATE NOT NULL,
    stay_end            DATE NOT NULL,
    stay_rating         TINYINT UNSIGNED NULL,
    last_stay_price     DECIMAL(10,2) NULL,
    currency            CHAR(3) NOT NULL DEFAULT 'USD',
    booking_source      VARCHAR(100) NULL,
    confirmation_code   VARCHAR(100) NULL,
    would_return        TINYINT(1) NULL,
    notes               TEXT NULL,
    is_private          TINYINT(1) NOT NULL DEFAULT 0,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_hotel_stays_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_hotel_stays_property FOREIGN KEY (hotel_property_id) REFERENCES hotel_properties(id) ON DELETE CASCADE,
    CONSTRAINT chk_stay_rating CHECK (stay_rating IS NULL OR stay_rating BETWEEN 1 AND 5),
    CONSTRAINT chk_hotel_dates CHECK (stay_end >= stay_start),
    INDEX idx_hotel_user (user_id),
    INDEX idx_hotel_property (hotel_property_id),
    INDEX idx_hotel_private (is_private),
    INDEX idx_hotel_dates (stay_start)
) ENGINE=InnoDB;

CREATE TABLE hotel_photos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hotel_stay_id   INT UNSIGNED NOT NULL,
    file_path       VARCHAR(500) NOT NULL,
    caption         VARCHAR(255) NULL,
    uploaded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_hotel_photos_stay FOREIGN KEY (hotel_stay_id) REFERENCES hotel_stays(id) ON DELETE CASCADE,
    INDEX idx_hotel_photos_stay (hotel_stay_id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- trips / trip_segments: the travel-dashboard side.
-- ----------------------------------------------------------------------------
CREATE TABLE trips (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_id        INT UNSIGNED NOT NULL,
    destination_city VARCHAR(150) NOT NULL,
    start_date      DATE NOT NULL,
    end_date        DATE NOT NULL,
    status          ENUM('planned','active','completed','cancelled') NOT NULL DEFAULT 'planned',
    trip_purpose    VARCHAR(255) NULL,
    notes           TEXT NULL,
    is_private      TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_trips_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_trip_dates CHECK (end_date >= start_date),
    INDEX idx_trips_owner (owner_id),
    INDEX idx_trips_dates (start_date, end_date)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- carriers: site-wide airlines / rail operators (user_id = who created the row).
-- Airlines require IATA for FlightAware; rail operators may omit it (Amtrak often uses 2V).
-- ----------------------------------------------------------------------------
CREATE TABLE carriers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    iata_code       VARCHAR(3) NULL,
    carrier_type    ENUM('airline','rail') NOT NULL DEFAULT 'airline',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_carriers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_carrier_type_iata (carrier_type, iata_code),
    INDEX idx_carriers_user (user_id),
    INDEX idx_carriers_name (name),
    INDEX idx_carriers_type (carrier_type)
) ENGINE=InnoDB;

CREATE TABLE trip_segments (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id             INT UNSIGNED NOT NULL,
    segment_type        ENUM('flight','hotel','train','car') NOT NULL,
    segment_subtype     VARCHAR(50) NULL,
    carrier_id          INT UNSIGNED NULL,
    carrier             VARCHAR(100) NULL,
    flight_number       VARCHAR(20) NULL,
    confirmation_code   VARCHAR(100) NULL,
    origin              VARCHAR(150) NULL,
    destination         VARCHAR(150) NULL,
    depart_dt           DATETIME NULL,
    arrive_dt           DATETIME NULL,
    hotel_stay_id       INT UNSIGNED NULL,
    status              ENUM('scheduled','en_route','landed','delayed','cancelled','completed') NOT NULL DEFAULT 'scheduled',
    source_parse_log_id INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_segments_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
    CONSTRAINT fk_segments_hotel_stay FOREIGN KEY (hotel_stay_id) REFERENCES hotel_stays(id) ON DELETE SET NULL,
    CONSTRAINT fk_segments_carrier FOREIGN KEY (carrier_id) REFERENCES carriers(id) ON DELETE SET NULL,
    INDEX idx_segments_trip (trip_id),
    INDEX idx_segments_depart (depart_dt),
    INDEX idx_segments_type (segment_type),
    INDEX idx_segments_carrier (carrier_id)
) ENGINE=InnoDB;

CREATE TABLE flight_status (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    segment_id          INT UNSIGNED NOT NULL UNIQUE,
    fa_flight_id        VARCHAR(100) NULL,
    gate                VARCHAR(20) NULL,
    terminal             VARCHAR(20) NULL,
    scheduled_out       DATETIME NULL,
    estimated_out       DATETIME NULL,
    actual_out          DATETIME NULL,
    scheduled_in        DATETIME NULL,
    estimated_in        DATETIME NULL,
    actual_in           DATETIME NULL,
    status              VARCHAR(50) NULL,
    progress_percent    TINYINT UNSIGNED NULL,
    delay_minutes       INT NOT NULL DEFAULT 0,
    airport_delay_info  JSON NULL,
    last_checked_at     DATETIME NULL,
    CONSTRAINT fk_flight_status_segment FOREIGN KEY (segment_id) REFERENCES trip_segments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- parse_log: audit trail for inbound mail. Raw email body is NEVER stored here
-- or anywhere else in the schema -- only metadata + extracted structured fields
-- that end up on trip_segments. This is a non-negotiable privacy constraint.
-- ----------------------------------------------------------------------------
CREATE TABLE parse_log (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    received_at         DATETIME NOT NULL,
    from_address        VARCHAR(255) NOT NULL,
    subject             VARCHAR(500) NULL,
    mail_uid            VARCHAR(100) NOT NULL,
    source               VARCHAR(20) NOT NULL,
    detected_type       VARCHAR(20) NULL,
    parse_status        ENUM('success','failed','pending_review','ignored') NOT NULL DEFAULT 'pending_review',
    failure_reason      TEXT NULL,
    confidence_score    DECIMAL(3,2) NULL,
    matched_user_id     INT UNSIGNED NULL,
    trip_segment_id     INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_parse_log_user FOREIGN KEY (matched_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_parse_log_segment FOREIGN KEY (trip_segment_id) REFERENCES trip_segments(id) ON DELETE SET NULL,
    UNIQUE KEY uq_parse_log_uid_source (mail_uid, source),
    INDEX idx_parse_log_status (parse_status)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- visibility_rules: field-level overrides. A row with target_user_id NULL
-- applies as the default for an entire direction (subject's own default
-- override, e.g. "make all my LATERAL sharing private"). A row with
-- target_user_id set is a USER_USER-style override for one specific viewer,
-- and always wins over the direction default. See VisibilityEngine.
-- ----------------------------------------------------------------------------
CREATE TABLE visibility_rules (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_user_id INT UNSIGNED NOT NULL,
    target_user_id  INT UNSIGNED NULL,
    direction       ENUM('top_down','bottom_up','lateral','user_user') NOT NULL,
    field_name      VARCHAR(50) NOT NULL,
    visible         TINYINT(1) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_vis_subject FOREIGN KEY (subject_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_vis_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_vis_rule (subject_user_id, target_user_id, direction, field_name),
    INDEX idx_vis_subject (subject_user_id),
    INDEX idx_vis_target (target_user_id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- visibility_blocks: hide a specific hotel stay or trip from selected users
-- while leaving org-default sharing intact for everyone else. is_private on
-- the resource itself hides it from everyone.
-- ----------------------------------------------------------------------------
CREATE TABLE visibility_blocks (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_user_id   INT UNSIGNED NOT NULL,
    resource_type   ENUM('hotel_stay','trip') NOT NULL,
    resource_id     INT UNSIGNED NOT NULL,
    blocked_user_id INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_vis_block_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_vis_block_target FOREIGN KEY (blocked_user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_vis_block (resource_type, resource_id, blocked_user_id),
    INDEX idx_vis_block_resource (resource_type, resource_id),
    INDEX idx_vis_block_owner (owner_user_id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- aeroapi_usage_log: FlightAware AeroAPI call/budget tracking.
-- ----------------------------------------------------------------------------
CREATE TABLE aeroapi_usage_log (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usage_date          DATE NOT NULL,
    endpoint            VARCHAR(100) NOT NULL,
    calls               INT UNSIGNED NOT NULL DEFAULT 0,
    estimated_cost_usd  DECIMAL(8,4) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_aeroapi_usage (usage_date, endpoint)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- audit_log: every DB write funnels through Core\Database::audited*() helpers,
-- which insert here. The VM/system administrator has DB access but this table
-- (plus parse_log's no-raw-body rule) is how "no admin access to personal
-- inbox content" is enforced by architecture rather than policy.
-- ----------------------------------------------------------------------------
CREATE TABLE audit_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    occurred_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actor_user_id   INT UNSIGNED NULL,
    action          VARCHAR(100) NOT NULL,
    table_name      VARCHAR(60) NOT NULL,
    record_id       INT UNSIGNED NULL,
    details         JSON NULL,
    INDEX idx_audit_table (table_name, record_id),
    INDEX idx_audit_actor (actor_user_id)
) ENGINE=InnoDB;


-- ----------------------------------------------------------------------------
-- notifications: alerts generated by AlertEvaluator after each FlightAware
-- enrichment pass (delay > 30 min, gate change, cancellation, landed, etc).
-- ----------------------------------------------------------------------------
CREATE TABLE notifications (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    segment_id      INT UNSIGNED NULL,
    alert_type      VARCHAR(30) NOT NULL,
    message         VARCHAR(500) NOT NULL,
    is_read         TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_segment FOREIGN KEY (segment_id) REFERENCES trip_segments(id) ON DELETE CASCADE,
    INDEX idx_notifications_user (user_id, is_read),
    INDEX idx_notifications_segment (segment_id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- cron_job_runs: last/history of scheduled jobs. Summaries are aggregates only
-- (counts/status) — never store flight numbers, hotels, emails, or user travel.
-- error_message may hold a short operational failure reason (IMAP/API), sanitized.
-- ----------------------------------------------------------------------------
CREATE TABLE cron_job_runs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_name        VARCHAR(60) NOT NULL,
    started_at      DATETIME NOT NULL,
    finished_at     DATETIME NULL,
    status          ENUM('running','ok','warning','failed') NOT NULL DEFAULT 'running',
    summary_json    JSON NULL,
    error_class     VARCHAR(120) NULL,
    error_message   VARCHAR(500) NULL,
    INDEX idx_cron_runs_job_started (job_name, started_at),
    INDEX idx_cron_runs_started (started_at)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
