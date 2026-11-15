-- ============================================================================
-- BIENENSTOCK — Esquema do banco de dados
-- Fonte única para a instalação headless (Docker) via install_cli.php.
-- Todos os comandos são idempotentes (IF NOT EXISTS / INSERT IGNORE).
-- ============================================================================

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    start_time TIME,
    end_time TIME,
    location VARCHAR(255),
    summary TEXT,
    photo VARCHAR(255),
    speaker_name VARCHAR(255),
    speaker_email VARCHAR(255),
    speaker_bio TEXT,
    speaker_photo VARCHAR(255),
    is_special TINYINT(1) DEFAULT 0,
    event_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_meta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    meta_key VARCHAR(255) NOT NULL,
    meta_value LONGTEXT,
    INDEX idx_event_id (event_id),
    INDEX idx_meta_key (meta_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    document VARCHAR(50),
    organization VARCHAR(255),
    phone VARCHAR(50),
    event_name VARCHAR(255),
    status ENUM('pending','confirmed','cancelled') DEFAULT 'pending',
    checkin_at DATETIME,
    cert_sent_at DATETIME,
    notes TEXT,
    qr_key VARCHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_qr_key (qr_key),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS participant_meta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NOT NULL,
    meta_key VARCHAR(255) NOT NULL,
    meta_value LONGTEXT,
    INDEX idx_participant_id (participant_id),
    INDEX idx_meta_key (meta_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS grids (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    selected_events TEXT,
    show_times TINYINT(1) DEFAULT 1,
    show_speakers TINYINT(1) DEFAULT 1,
    show_types TINYINT(1) DEFAULT 1,
    show_photos TINYINT(1) DEFAULT 1,
    visible_columns TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    parent_id INT,
    INDEX idx_parent_id (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS custom_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    field_key VARCHAR(100) NOT NULL UNIQUE,
    field_label VARCHAR(255) NOT NULL,
    field_type VARCHAR(50) NOT NULL,
    field_options TEXT,
    field_required TINYINT(1) DEFAULT 0,
    field_show_in_table TINYINT(1) DEFAULT 0,
    field_order INT DEFAULT 0,
    context ENUM('event','participant') DEFAULT 'event'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value LONGTEXT,
    autoload TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cert_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ref_type ENUM('speaker','participant') NOT NULL,
    ref_id INT NOT NULL,
    ref_name VARCHAR(255),
    file_path VARCHAR(512),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS certificates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cert_type ENUM('speaker','participant') NOT NULL,
    template_html LONGTEXT,
    template_css TEXT,
    bg_image LONGTEXT,
    positions TEXT,
    variables TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed inicial (idempotente)
INSERT IGNORE INTO activity_types (name, slug) VALUES
    ('Palestra', 'palestra'),
    ('Oficina', 'oficina'),
    ('Mesa Redonda', 'mesa-redonda'),
    ('Workshop', 'workshop'),
    ('Painel', 'painel');

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('site_name', 'Bienenstock'),
    ('version', '0.001');
