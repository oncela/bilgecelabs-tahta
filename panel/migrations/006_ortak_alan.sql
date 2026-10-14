-- Ortak alan: panelden yüklenen, tahtalara dağıtılan dosyalar.
CREATE TABLE IF NOT EXISTS shared_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  orig_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(64) NOT NULL,     -- diskteki ad (çakışma/karakter sorunu olmasın)
  size BIGINT NOT NULL,
  sha256 CHAR(64) NOT NULL,
  subdir VARCHAR(64) NOT NULL DEFAULT '',
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
