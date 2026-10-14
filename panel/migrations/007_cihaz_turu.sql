-- Cihaz türü: board (akıllı tahta), classroom (sınıf bilgisayarı),
-- office (idare), desk (danışma), other (diğer). Kurulumda belirlenir.
ALTER TABLE devices ADD COLUMN IF NOT EXISTS dev_type VARCHAR(16) NOT NULL DEFAULT 'board' AFTER name;
ALTER TABLE devices ADD INDEX IF NOT EXISTS idx_dev_type (dev_type);
