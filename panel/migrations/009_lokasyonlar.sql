-- Çok şube/departman yapısı: Lokasyon (şube) > cihazlar.
-- "room" alanı departman/bölüm olarak kullanılır (ör. 7-B, Muhasebe, Danışma).
CREATE TABLE IF NOT EXISTS locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(96) NOT NULL,
  sort INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

ALTER TABLE devices ADD COLUMN IF NOT EXISTS location_id INT NULL AFTER room;
ALTER TABLE devices ADD INDEX IF NOT EXISTS idx_location (location_id);
