-- Alarm/uyarı motoru: eşik kuralları değerlendirilir, açık/çözüldü uyarılar tutulur.
CREATE TABLE IF NOT EXISTS alerts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  device_id INT NULL,
  type VARCHAR(32) NOT NULL,          -- disk / mem / offline / defender / mesh / net_new / cmd_fail
  severity ENUM('warning','critical') NOT NULL DEFAULT 'warning',
  message VARCHAR(255) NOT NULL,
  status ENUM('open','resolved') NOT NULL DEFAULT 'open',
  notified TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  -- açık uyarılarda tekillik open_key ile (çözülünce NULL olur, NULL'lar çakışmaz)
  open_key VARCHAR(48) AS (IF(status = 'open', CONCAT(device_id, '-', type), NULL)) STORED,
  UNIQUE KEY uq_open (open_key),
  INDEX (status, created_at),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
