-- Created on first `docker compose up` only. Subsequent starts reuse the volume.
CREATE DATABASE IF NOT EXISTS appoint_manager
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE DATABASE IF NOT EXISTS appoint_manager_test
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE DATABASE IF NOT EXISTS appoint_manager_e2e
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
