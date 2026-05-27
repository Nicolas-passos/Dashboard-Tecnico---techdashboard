<?php
define('PLUGIN_TECHDASHBOARD_VERSION', '2.0.0');
define('PLUGIN_TECHDASHBOARD_MIN_GLPI', '10.0.0');
define('PLUGIN_TECHDASHBOARD_DIR', __DIR__);
spl_autoload_register(function ($class) {
    $prefix = 'GlpiPlugin\\Techdashboard\\';
    $base   = __DIR__ . '/src/';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = $base . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) require $file;
});
function plugin_version_techdashboard(): array {
    return ['name' => 'Dashboard', 'version' => PLUGIN_TECHDASHBOARD_VERSION, 'author' => 'TechDashboard Contributors', 'license' => 'GPL v2+', 'homepage' => 'https://github.com/your-org/techdashboard', 'requirements' => ['glpi' => ['min' => PLUGIN_TECHDASHBOARD_MIN_GLPI], 'php' => ['min' => '8.1']]];
}
function plugin_techdashboard_check_prerequisites(): bool {
    if (version_compare(GLPI_VERSION, PLUGIN_TECHDASHBOARD_MIN_GLPI, 'lt')) { echo 'Requer GLPI ' . PLUGIN_TECHDASHBOARD_MIN_GLPI; return false; }
    return true;
}
function plugin_techdashboard_check_config(bool $verbose = false): bool { return true; }
function plugin_init_techdashboard(): void {
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS['csrf_compliant']['techdashboard'] = true;
    $PLUGIN_HOOKS['menu_toadd']['techdashboard'] = ['tools' => 'GlpiPlugin\\Techdashboard\\Dashboard'];
    $PLUGIN_HOOKS['config_page']['techdashboard'] = 'front/config.form.php';
    if (isset($_SESSION['glpiactiveprofile'])) {
        $plugin = new Plugin();
        if ($plugin->isActivated('techdashboard')) {
            $PLUGIN_HOOKS['add_css']['techdashboard'][] = 'css/dashboard.css';
            $PLUGIN_HOOKS['add_javascript']['techdashboard'][] = 'js/dashboard.js';
        }
    }
}
function plugin_techdashboard_get_menu(): array {
    return [
        'title' => 'Dashboard',
        'page'  => Plugin::getWebDir('techdashboard', true) . '/front/index.php',
        'icon'  => 'ti ti-layout-dashboard',
    ];
}
