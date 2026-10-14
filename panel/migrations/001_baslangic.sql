-- Acı Okulları tahta yönetim paneli veritabanı şeması (MariaDB 10.6+)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) PRIMARY KEY,
  v TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL UNIQUE,
  pass_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS devices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  token_hash CHAR(64) NOT NULL UNIQUE,
  hostname VARCHAR(128) NOT NULL DEFAULT '',
  name VARCHAR(128) NULL,            -- panelde verilen ad (ör. "7-B Tahta")
  room VARCHAR(64) NULL,             -- sınıf / bölüm
  mac VARCHAR(32) NOT NULL DEFAULT '',
  os VARCHAR(160) NOT NULL DEFAULT '',
  agent_version VARCHAR(32) NOT NULL DEFAULT '',
  ip VARCHAR(64) NOT NULL DEFAULT '',
  logged_user VARCHAR(128) NOT NULL DEFAULT '',
  uptime_s INT NOT NULL DEFAULT 0,
  defender TEXT NULL,                -- JSON
  defender_at DATETIME NULL,
  enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen DATETIME NULL,
  INDEX (mac),
  INDEX (room)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS commands (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  device_id INT NOT NULL,
  batch_id CHAR(16) NULL,            -- toplu gönderimde ortak kimlik
  type VARCHAR(32) NOT NULL,
  args TEXT NOT NULL,                -- JSON
  status ENUM('pending','sent','done','failed','expired') NOT NULL DEFAULT 'pending',
  output MEDIUMTEXT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  done_at DATETIME NULL,
  INDEX (device_id, status),
  INDEX (batch_id),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- Ağ taramasında bulunan cihazlar (tahtalar dahil, telefon, yazıcı, modem...)
CREATE TABLE IF NOT EXISTS net_devices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mac VARCHAR(32) NOT NULL UNIQUE,
  ip VARCHAR(64) NOT NULL DEFAULT '',
  hostname VARCHAR(255) NOT NULL DEFAULT '',
  label VARCHAR(128) NULL,           -- panelde verilen ad (ör. "Müdür odası yazıcı")
  known TINYINT(1) NOT NULL DEFAULT 0, -- yönetici "tanıdık" olarak işaretledi
  first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  seen_by INT NULL,                  -- taramayı yapan tahta
  INDEX (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NULL,
  action VARCHAR(64) NOT NULL,
  detail TEXT NULL,
  ip VARCHAR(64) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
