-- Tahta kullanım kaydı: tahta o saat içinde en az bir kez bağlandıysa bir satır.
-- Grafikler (son 7 gün kullanım) bundan hesaplanır. 180 günden eski kayıtlar silinir.
CREATE TABLE IF NOT EXISTS device_activity (
  device_id INT NOT NULL,
  hour DATETIME NOT NULL,
  PRIMARY KEY (device_id, hour),
  INDEX (hour),
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
