INSERT INTO "#__extensions" ("name", "type", "element", "folder", "client_id", "enabled", "access", "protected", "locked", "manifest_cache", "params", "custom_data", "ordering", "state")
SELECT 'plg_customize_layout', 'plugin', 'layout', 'customize', 0, 1, 1, 0, 1, '', '', '', 6, 0
WHERE NOT EXISTS (SELECT * FROM "#__extensions" e WHERE e."type" = 'plugin' AND e."element" = 'layout' AND e."folder" = 'customize' AND e."client_id" = 0);
