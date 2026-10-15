-- Self servis destek talepleri: kullanıcı cihazdaki BilgeceLabs Destek penceresinden açar.
CREATE TABLE IF NOT EXISTS tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  device_id INT NULL,
  user_name VARCHAR(128) NULL,
  subject VARCHAR(160) NOT NULL,
  message TEXT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  reply TEXT NULL,                    -- yöneticinin yanıtı (kullanıcı pencerede görür)
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX (status),
  INDEX (device_id),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
