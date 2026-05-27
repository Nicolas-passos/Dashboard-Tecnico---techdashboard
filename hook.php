<?php
function plugin_techdashboard_install(): bool {
    global $DB;
    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
    if (!$DB->tableExists('glpi_plugin_techdashboard_configs')) {
        $DB->queryOrDie("CREATE TABLE `glpi_plugin_techdashboard_configs` (`id` INT {$default_key_sign} NOT NULL AUTO_INCREMENT, `users_id` INT {$default_key_sign} NOT NULL DEFAULT 0, `entities_id` INT {$default_key_sign} NOT NULL DEFAULT 0, `period_days` SMALLINT NOT NULL DEFAULT 30, `refresh_interval` SMALLINT NOT NULL DEFAULT 300, `widgets_order` TEXT, `date_mod` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (`id`), UNIQUE KEY `users_id` (`users_id`), KEY `entities_id` (`entities_id`)) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}", 'Criando configs');
    }
    if (!$DB->tableExists('glpi_plugin_techdashboard_cache')) {
        $DB->queryOrDie("CREATE TABLE `glpi_plugin_techdashboard_cache` (`id` INT {$default_key_sign} NOT NULL AUTO_INCREMENT, `cache_key` VARCHAR(128) NOT NULL, `users_id` INT {$default_key_sign} NOT NULL DEFAULT 0, `entities_id` INT {$default_key_sign} NOT NULL DEFAULT 0, `payload` LONGTEXT, `expires_at` DATETIME NOT NULL, `date_mod` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (`id`), UNIQUE KEY `cache_key_user` (`cache_key`, `users_id`, `entities_id`), KEY `expires_at` (`expires_at`)) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}", 'Criando cache');
    }

    if (!$DB->tableExists('glpi_plugin_techdashboard_branding')) {
        $DB->queryOrDie("CREATE TABLE `glpi_plugin_techdashboard_branding` (
            `id` INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `company_name` VARCHAR(255) NOT NULL DEFAULT '',
            `dashboard_title` VARCHAR(255) NOT NULL DEFAULT 'Dashboard',
            `show_logo` TINYINT(1) NOT NULL DEFAULT 0,
            `logo_path` VARCHAR(255) NOT NULL DEFAULT '',
            `primary_color` VARCHAR(7) NOT NULL DEFAULT '#3b82f6',
            `secondary_color` VARCHAR(7) NOT NULL DEFAULT '#0f172a',
            `date_creation` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}", 'Criando branding');
    }
    return true;
}
function plugin_techdashboard_uninstall(): bool {
    global $DB;
    foreach (['glpi_plugin_techdashboard_configs','glpi_plugin_techdashboard_cache','glpi_plugin_techdashboard_branding'] as $table) {
        if ($DB->tableExists($table)) {
            $DB->queryOrDie("DROP TABLE `{$table}`", "Removendo {$table}");
        }
    }
    return true;
}
