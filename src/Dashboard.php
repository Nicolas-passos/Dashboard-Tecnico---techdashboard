<?php
namespace GlpiPlugin\Techdashboard;
use CommonGLPI;
use Session;
use Plugin;
class Dashboard extends CommonGLPI {
    public static $rightname = 'config';
    public static function getTypeName($nb = 0): string {
        return 'Dashboard';
    }
    public static function getMenuName(): string {
        return 'Dashboard';
    }
    public static function getMenuContent(): array {
        return [
            'title' => 'Dashboard',
            'page'  => Plugin::getWebDir('techdashboard', true) . '/front/index.php',
            'icon'  => 'ti ti-layout-dashboard',
        ];
    }
    public static function canView(): bool {
        return Session::haveRight(static::$rightname, READ);
    }
}
