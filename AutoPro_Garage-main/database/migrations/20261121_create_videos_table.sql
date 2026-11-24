-- ALPHA GARAGE: Phase 1.14 Video Management & Gallery Schema Migration
-- Creates videos table, registers permissions, and seeds initial showroom video items.

CREATE TABLE IF NOT EXISTS videos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    description TEXT NULL,
    category VARCHAR(60) NOT NULL DEFAULT 'Diagnostics',
    source_type ENUM('youtube', 'upload') NOT NULL DEFAULT 'youtube',
    youtube_id VARCHAR(32) NULL,
    youtube_url VARCHAR(255) NULL,
    video_file VARCHAR(255) NULL,
    thumbnail_file VARCHAR(255) NULL,
    duration VARCHAR(32) NULL DEFAULT '',
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    views_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_videos_pub (is_published, sort_order, created_at),
    KEY idx_videos_cat (category),
    KEY idx_videos_slug (slug),
    CONSTRAINT fk_videos_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register video permissions
INSERT IGNORE INTO permissions (code, module, label) VALUES
('videos.view', 'Media', 'View video management'),
('videos.manage', 'Media', 'Create, edit, delete, and publish videos');

-- Assign video permissions to Administrator (role_id = 1)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, p.id FROM permissions p WHERE p.code IN ('videos.view', 'videos.manage');

-- Seed initial showcase videos
INSERT INTO videos (title, slug, description, category, source_type, youtube_id, youtube_url, duration, is_published, sort_order, views_count)
VALUES
(
    'Precision ECU Diagnostic Scan & Calibration Workflow',
    'precision-ecu-diagnostic-scan-calibration-workflow',
    'Experience our certified technicians performing full-spectrum OBD-II and ECU diagnostic scans using industry-leading Autel and Bosch diagnostic computers to detect subtle powertrain anomalies.',
    'Diagnostics',
    'youtube',
    'dQw4w9WgXcQ',
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    '08:45',
    1,
    1,
    142
),
(
    'V8 Twin-Turbo Engine Rebuild & Cylinder Honing',
    'v8-twin-turbo-engine-rebuild-cylinder-honing',
    'Step inside our cleanroom engine rebuild bay as master mechanics tear down, measure micrometers, hone cylinders, and torque rod bearings on a twin-turbocharged high-performance engine.',
    'Engine & Transmission',
    'youtube',
    'kJQP7kiw5Fk',
    'https://www.youtube.com/watch?v=kJQP7kiw5Fk',
    '14:20',
    1,
    2,
    289
),
(
    'Hybrid & Electric Vehicle Battery Health Inspection',
    'hybrid-electric-vehicle-battery-health-inspection',
    'Deep-dive into high-voltage pack safety isolation, cell balancing diagnostics, and thermal management fluid service for modern hybrid and fully electric European vehicles.',
    'Electrical & Battery',
    'youtube',
    'fJ9rUzIMcZQ',
    'https://www.youtube.com/watch?v=fJ9rUzIMcZQ',
    '06:15',
    1,
    3,
    98
),
(
    'Ultra-Gloss Multi-Stage Paint Correction & 9H Ceramic Shield',
    'ultra-gloss-multi-stage-paint-correction-ceramic-shield',
    'Watch our detailing artisans eliminate swirl marks through dual-action rotary polishers before infusing an aerospace-grade 9H hydrophobic ceramic coating.',
    'Detailing & Ceramic',
    'youtube',
    '3JZ_D3ELwOQ',
    'https://www.youtube.com/watch?v=3JZ_D3ELwOQ',
    '11:30',
    1,
    4,
    365
)
ON DUPLICATE KEY UPDATE title = VALUES(title);
