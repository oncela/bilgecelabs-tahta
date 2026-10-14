-- MeshCentral cihaz kimliği (ajan "meshagent -nodeid" ile bildirir, 96 hane hex).
-- Panel uzaktan ekranı bu kimlikle açar; boşsa bilgisayar adıyla dener.
ALTER TABLE devices ADD COLUMN IF NOT EXISTS mesh_node_id CHAR(96) NULL AFTER mesh_state;
