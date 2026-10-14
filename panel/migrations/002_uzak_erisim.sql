-- Uzaktan erişim (MeshCentral): her panel yöneticisinin MeshCentral'daki
-- kısıtlı kullanıcısı. Boşsa o yönetici ekran açamaz.
ALTER TABLE admins ADD COLUMN IF NOT EXISTS mesh_user VARCHAR(128) NULL AFTER pass_hash;
