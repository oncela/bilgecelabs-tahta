-- Defender tespitleri (Tehditler sayfası) ve PUA koruma ayarı.
CREATE TABLE IF NOT EXISTS device_threats (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  device_id INT NOT NULL,
  detection_id VARCHAR(64) NOT NULL,      -- Defender DetectionID (her tespit tekil)
  threat_id VARCHAR(32) NULL,
  name VARCHAR(255) NULL,                 -- ör. Trojan:Win32/Wacatac.B!ml
  severity TINYINT NULL,                  -- 1 düşük, 2 orta, 4 yüksek, 5 ciddi
  category SMALLINT NULL,
  status SMALLINT NULL,                   -- ThreatStatusID (2 temizlendi, 3 karantina, ...)
  active TINYINT(1) NOT NULL DEFAULT 0,   -- tehdit hâlâ etkin mi
  source TINYINT NULL,                    -- 3 gerçek zamanlı, 4 indirme/ek, 1 kullanıcı taraması ...
  resources TEXT NULL,                    -- JSON: dosya yolları
  process VARCHAR(255) NULL,
  user_name VARCHAR(128) NULL,
  detected_at DATETIME NULL,
  changed_at DATETIME NULL,
  seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (device_id, detection_id),
  INDEX (detected_at),
  INDEX (name),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

ALTER TABLE devices ADD COLUMN pua TINYINT NULL AFTER defender;   -- 0 kapalı, 1 açık, 2 izleme modu
