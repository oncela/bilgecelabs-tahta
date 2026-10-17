-- Defender koruma ayarları (ASR, ağ, klasör, USB, günlük tarama) ve koruma olayları.
ALTER TABLE devices ADD COLUMN harden TEXT NULL AFTER pua;   -- JSON: ajanın bildirdiği koruma ayarları

CREATE TABLE IF NOT EXISTS protection_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  device_id INT NOT NULL,
  event_id SMALLINT NOT NULL,         -- 1121/1122 ASR, 1123/1124 klasör, 1125/1126 ağ, 1160 PUA
  rule VARCHAR(64) NULL,              -- ASR kural kimliği
  target VARCHAR(500) NULL,           -- dosya yolu / alan adı
  process VARCHAR(500) NULL,
  user_name VARCHAR(128) NULL,
  occurred_at DATETIME NOT NULL,
  UNIQUE KEY uq (device_id, event_id, occurred_at, rule),
  INDEX (occurred_at),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
