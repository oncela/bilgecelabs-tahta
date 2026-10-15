-- İzin verilen yazılım kataloğu: panelden/self-servisten kurulabilen programlar.
CREATE TABLE IF NOT EXISTS software_catalog (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(96) NOT NULL,
  icon VARCHAR(48) NULL,              -- tabler simge adı (ör. brand-office)
  method ENUM('winget','url') NOT NULL DEFAULT 'winget',
  winget_id VARCHAR(128) NULL,
  url VARCHAR(512) NULL,
  sha256 CHAR(64) NULL,
  silent VARCHAR(96) NULL,            -- .exe için sessiz bayraklar
  self_service TINYINT(1) NOT NULL DEFAULT 1,  -- kullanıcı kendi kurabilir mi
  sort INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
