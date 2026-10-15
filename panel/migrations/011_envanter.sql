-- Detaylı envanter: donanım kimliği + kurulu yazılımlar (ajan 12 saatte bir gönderir).
CREATE TABLE IF NOT EXISTS device_inventory (
  device_id INT PRIMARY KEY,
  serial VARCHAR(96) NULL,
  manufacturer VARCHAR(96) NULL,
  model VARCHAR(128) NULL,
  cpu VARCHAR(160) NULL,
  ram_gb INT NULL,
  os VARCHAR(160) NULL,
  software_count INT NULL,
  data MEDIUMTEXT NULL,          -- tam JSON (diskler, ağ, yazılım listesi)
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (serial),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
