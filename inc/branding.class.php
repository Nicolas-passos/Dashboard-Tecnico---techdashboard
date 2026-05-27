<?php
if (!defined('GLPI_ROOT')) {
    die('Acesso direto não permitido.');
}

class PluginTechdashboardBranding {
    public const TABLE = 'glpi_plugin_techdashboard_branding';
    public const UPLOAD_DIR = GLPI_ROOT . '/plugins/techdashboard/uploads';
    public const DEFAULT_PRIMARY = '#3b82f6';
    public const DEFAULT_SECONDARY = '#0f172a';
    public const DEFAULT_TITLE = 'Dashboard';

    public static function defaults(): array {
        return [
            'company_name'    => '',
            'dashboard_title' => self::DEFAULT_TITLE,
            'show_logo'      => 0,
            'logo_path'      => '',
            'primary_color'  => self::DEFAULT_PRIMARY,
            'secondary_color'=> self::DEFAULT_SECONDARY,
        ];
    }

    public static function load(): array {
        global $DB;
        $data = self::defaults();

        if (!$DB->tableExists(self::TABLE)) {
            return $data;
        }

        $res = $DB->query('SELECT * FROM `' . self::TABLE . '` ORDER BY id ASC LIMIT 1');
        if ($res && ($row = $DB->fetchAssoc($res))) {
            foreach ($data as $key => $value) {
                if (array_key_exists($key, $row) && $row[$key] !== null) {
                    $data[$key] = $row[$key];
                }
            }
        }

        $data['dashboard_title'] = trim((string)$data['dashboard_title']) ?: self::DEFAULT_TITLE;
        $data['primary_color'] = self::sanitizeHexColor($data['primary_color'], self::DEFAULT_PRIMARY);
        $data['secondary_color'] = self::sanitizeHexColor($data['secondary_color'], self::DEFAULT_SECONDARY);
        $data['show_logo'] = (int)$data['show_logo'] === 1 ? 1 : 0;
        $data['logo_url'] = self::getLogoUrl((string)$data['logo_path']);

        return $data;
    }

    public static function save(array $input, ?array $file = null): void {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return;
        }

        $current = self::load();
        $logoPath = (string)($current['logo_path'] ?? '');

        if (!empty($input['remove_logo'])) {
            self::deleteLogo($logoPath);
            $logoPath = '';
        }

        if ($file && !empty($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
            $logoPath = self::storeLogo($file, $logoPath);
        }

        $data = [
            'company_name'     => trim((string)($input['company_name'] ?? '')),
            'dashboard_title'  => trim((string)($input['dashboard_title'] ?? self::DEFAULT_TITLE)) ?: self::DEFAULT_TITLE,
            'show_logo'       => !empty($input['show_logo']) ? 1 : 0,
            'logo_path'       => $logoPath,
            'primary_color'   => self::sanitizeHexColor($input['primary_color'] ?? self::DEFAULT_PRIMARY, self::DEFAULT_PRIMARY),
            'secondary_color' => self::sanitizeHexColor($input['secondary_color'] ?? self::DEFAULT_SECONDARY, self::DEFAULT_SECONDARY),
        ];

        $escaped = [];
        foreach ($data as $key => $value) {
            $escaped[$key] = method_exists($DB, 'escape') ? $DB->escape((string)$value) : addslashes((string)$value);
        }

        $exists = false;
        $res = $DB->query('SELECT id FROM `' . self::TABLE . '` LIMIT 1');
        if ($res && $DB->numrows($res) > 0) {
            $exists = true;
        }

        if ($exists) {
            $DB->queryOrDie("UPDATE `" . self::TABLE . "`
                SET company_name='{$escaped['company_name']}',
                    dashboard_title='{$escaped['dashboard_title']}',
                    show_logo=" . (int)$data['show_logo'] . ",
                    logo_path='{$escaped['logo_path']}',
                    primary_color='{$escaped['primary_color']}',
                    secondary_color='{$escaped['secondary_color']}'
                LIMIT 1", 'Atualizando branding do TechDashboard');
        } else {
            $DB->queryOrDie("INSERT INTO `" . self::TABLE . "`
                (company_name, dashboard_title, show_logo, logo_path, primary_color, secondary_color)
                VALUES ('{$escaped['company_name']}', '{$escaped['dashboard_title']}', " . (int)$data['show_logo'] . ", '{$escaped['logo_path']}', '{$escaped['primary_color']}', '{$escaped['secondary_color']}')", 'Criando branding do TechDashboard');
        }
    }

    public static function sanitizeHexColor($value, string $fallback): string {
        $value = trim((string)$value);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : $fallback;
    }

    private static function storeLogo(array $file, string $oldLogoPath = ''): string {
        $allowed = [
            'image/png'     => 'png',
            'image/jpeg'    => 'jpg',
            'image/svg+xml' => 'svg',
            'image/webp'    => 'webp',
        ];

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!isset($allowed[$mime])) {
            throw new RuntimeException('Formato de logo inválido. Use PNG, JPG, SVG ou WEBP.');
        }

        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0755, true);
        }

        self::deleteLogo($oldLogoPath);

        $ext = $allowed[$mime];
        $filename = 'logo_' . date('Ymd_His') . '.' . $ext;
        $relative = 'uploads/' . $filename;
        $target = GLPI_ROOT . '/plugins/techdashboard/' . $relative;

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new RuntimeException('Não foi possível salvar a logo enviada.');
        }

        return $relative;
    }

    private static function deleteLogo(string $logoPath): void {
        if ($logoPath === '') {
            return;
        }
        $path = GLPI_ROOT . '/plugins/techdashboard/' . ltrim($logoPath, '/');
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function getLogoUrl(string $logoPath): string {
        if ($logoPath === '') {
            return '';
        }
        $path = GLPI_ROOT . '/plugins/techdashboard/' . ltrim($logoPath, '/');
        if (!is_file($path)) {
            return '';
        }
        return Plugin::getWebDir('techdashboard', true) . '/' . ltrim($logoPath, '/');
    }
}
