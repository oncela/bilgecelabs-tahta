-- Güvenlik karnesi (ajan saatte bir bildirir).
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS fw_on TINYINT(1) NULL AFTER disk_gb,
  ADD COLUMN IF NOT EXISTS bitlocker VARCHAR(16) NULL AFTER fw_on,
  ADD COLUMN IF NOT EXISTS reboot_pending TINYINT(1) NULL AFTER bitlocker,
  ADD COLUMN IF NOT EXISTS uac TINYINT(1) NULL AFTER reboot_pending,
  ADD COLUMN IF NOT EXISTS last_update DATE NULL AFTER uac,
  ADD COLUMN IF NOT EXISTS tamper TINYINT(1) NULL AFTER last_update,
  ADD COLUMN IF NOT EXISTS security_at DATETIME NULL AFTER tamper;
