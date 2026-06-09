INSERT INTO `#__extensions` (`name`, `type`, `element`, `folder`, `client_id`, `enabled`, `access`, `protected`, `locked`, `manifest_cache`, `params`, `custom_data`, `ordering`, `state`)
SELECT 'plg_customize_content', 'plugin', 'content', 'customize', 0, 1, 1, 0, 1, '', '', '', 1, 0
WHERE NOT EXISTS (SELECT * FROM `#__extensions` e WHERE e.`type` = 'plugin' AND e.`element` = 'content' AND e.`folder` = 'customize' AND e.`client_id` = 0);

INSERT INTO `#__extensions` (`name`, `type`, `element`, `folder`, `client_id`, `enabled`, `access`, `protected`, `locked`, `manifest_cache`, `params`, `custom_data`, `ordering`, `state`)
SELECT 'plg_customize_language', 'plugin', 'language', 'customize', 0, 1, 1, 0, 1, '', '', '', 2, 0
WHERE NOT EXISTS (SELECT * FROM `#__extensions` e WHERE e.`type` = 'plugin' AND e.`element` = 'language' AND e.`folder` = 'customize' AND e.`client_id` = 0);

INSERT INTO `#__extensions` (`name`, `type`, `element`, `folder`, `client_id`, `enabled`, `access`, `protected`, `locked`, `manifest_cache`, `params`, `custom_data`, `ordering`, `state`)
SELECT 'plg_customize_module', 'plugin', 'module', 'customize', 0, 1, 1, 0, 1, '', '', '', 3, 0
WHERE NOT EXISTS (SELECT * FROM `#__extensions` e WHERE e.`type` = 'plugin' AND e.`element` = 'module' AND e.`folder` = 'customize' AND e.`client_id` = 0);

INSERT INTO `#__extensions` (`name`, `type`, `element`, `folder`, `client_id`, `enabled`, `access`, `protected`, `locked`, `manifest_cache`, `params`, `custom_data`, `ordering`, `state`)
SELECT 'plg_customize_position', 'plugin', 'position', 'customize', 0, 1, 1, 0, 1, '', '', '', 4, 0
WHERE NOT EXISTS (SELECT * FROM `#__extensions` e WHERE e.`type` = 'plugin' AND e.`element` = 'position' AND e.`folder` = 'customize' AND e.`client_id` = 0);

INSERT INTO `#__extensions` (`name`, `type`, `element`, `folder`, `client_id`, `enabled`, `access`, `protected`, `locked`, `manifest_cache`, `params`, `custom_data`, `ordering`, `state`)
SELECT 'plg_customize_view', 'plugin', 'view', 'customize', 0, 1, 1, 0, 1, '', '', '', 5, 0
WHERE NOT EXISTS (SELECT * FROM `#__extensions` e WHERE e.`type` = 'plugin' AND e.`element` = 'view' AND e.`folder` = 'customize' AND e.`client_id` = 0);
