<?php
if (!defined('GLPI_ROOT')) { die("Acesso direto não permitido."); }

class PluginTechdashboardDashboard extends CommonGLPI {

    static $rightname = 'plugin_techdashboard';

    static function getTypeName($nb = 0) {
        return 'Dashboard';
    }

    static function getMenuName() {
        return 'Dashboard';
    }

    static function getMenuContent() {
        return [
            'title' => self::getMenuName(),
            'page'  => Plugin::getWebDir('techdashboard') . '/front/index.php',
            'icon'  => 'ti ti-layout-dashboard',
        ];
    }

    // -------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------

    static function buildDateWhere(string $period, string $from, string $to, string $field = 't.date_creation'): string {
        if ($period === 'custom' && $from && $to) {
            $f = addslashes($from);
            $t = addslashes($to);
            return "AND $field BETWEEN '$f 00:00:00' AND '$t 23:59:59'";
        }
        $days = match($period) {
            '7d'   => 7,
            '90d'  => 90,
            '365d' => 365,
            default => 30,
        };
        return "AND $field >= DATE_SUB(NOW(), INTERVAL $days DAY)";
    }

    static function buildEntityWhere(int $eid): string {
        if ($eid <= 0) return '';
        $sons = implode(',', getSonsOf('glpi_entities', $eid));
        return "AND t.entities_id IN ($sons)";
    }

    // -------------------------------------------------------
    // KPIs RESUMO (cards do topo)
    // -------------------------------------------------------

    static function getSummaryKpis(string $period, int $eid, string $from, string $to): array {
        global $DB;
        $dw = self::buildDateWhere($period, $from, $to);
        $ew = self::buildEntityWhere($eid);

        $res = $DB->query("
            SELECT
                COUNT(*) AS total,
                SUM(status IN (1,2,3,4)) AS abertos,
                SUM(status IN (5,6))     AS fechados,
                SUM(status = 4)          AS pendentes,
                ROUND(AVG(CASE WHEN solvedate IS NOT NULL
                    THEN TIMESTAMPDIFF(HOUR, date_creation, solvedate) END), 1) AS avg_h
            FROM glpi_tickets t
            WHERE is_deleted = 0 $dw $ew
        ");
        return $DB->fetchAssoc($res) ?: [];
    }

    // -------------------------------------------------------
    // MÉTRICA 1 — Status
    // -------------------------------------------------------

    static function getByStatus(string $period, int $eid, string $from, string $to): array {
        global $DB;
        $dw = self::buildDateWhere($period, $from, $to);
        $ew = self::buildEntityWhere($eid);

        $labels = [1=>'Novo',2=>'Em atend. (atribuído)',3=>'Em atend. (planejado)',4=>'Pendente',5=>'Solucionado',6=>'Fechado'];
        $res = $DB->query("
            SELECT status, COUNT(*) AS total
            FROM glpi_tickets t
            WHERE is_deleted = 0 $dw $ew
            GROUP BY status ORDER BY status
        ");
        $out = [];
        while ($r = $DB->fetchAssoc($res)) {
            $out[] = ['label' => $labels[$r['status']] ?? 'Status '.$r['status'], 'total' => (int)$r['total']];
        }
        return $out;
    }

    // -------------------------------------------------------
    // MÉTRICA 2 — SLA
    // -------------------------------------------------------

    static function getSla(string $period, int $eid, string $from, string $to): array {
        global $DB;
        $dw = self::buildDateWhere($period, $from, $to);
        $ew = self::buildEntityWhere($eid);

        $res = $DB->query("
            SELECT
                SUM(time_to_resolve IS NOT NULL AND (solvedate <= time_to_resolve OR solvedate IS NULL)) AS dentro,
                SUM(time_to_resolve IS NOT NULL AND solvedate > time_to_resolve) AS fora,
                SUM(time_to_resolve IS NULL) AS sem_sla
            FROM glpi_tickets t
            WHERE is_deleted = 0 $dw $ew
        ");
        $r = $DB->fetchAssoc($res);
        return [
            ['label'=>'Dentro do SLA', 'total'=>(int)$r['dentro']],
            ['label'=>'Fora do SLA',   'total'=>(int)$r['fora']],
            ['label'=>'Sem SLA',       'total'=>(int)$r['sem_sla']],
        ];
    }

    // -------------------------------------------------------
    // MÉTRICA 3 — Por técnico (top 10)
    // -------------------------------------------------------

    static function getByTechnician(string $period, int $eid, string $from, string $to): array {
        global $DB;
        $dw = self::buildDateWhere($period, $from, $to);
        $ew = self::buildEntityWhere($eid);

        $res = $DB->query("
            SELECT CONCAT(u.firstname,' ',u.realname) AS label, COUNT(tu.tickets_id) AS total
            FROM glpi_tickets_users tu
            JOIN glpi_users u   ON u.id = tu.users_id
            JOIN glpi_tickets t ON t.id = tu.tickets_id
            WHERE tu.type = 2 AND t.is_deleted = 0 $dw $ew
            GROUP BY u.id ORDER BY total DESC LIMIT 10
        ");
        $out = [];
        while ($r = $DB->fetchAssoc($res)) {
            $out[] = ['label' => trim($r['label']) ?: 'Sem nome', 'total' => (int)$r['total']];
        }
        return $out;
    }

    // -------------------------------------------------------
    // MÉTRICA 4 — Por grupo (top 10)
    // -------------------------------------------------------

    static function getByGroup(string $period, int $eid, string $from, string $to): array {
        global $DB;
        $dw = self::buildDateWhere($period, $from, $to);
        $ew = self::buildEntityWhere($eid);

        $res = $DB->query("
            SELECT g.name AS label, COUNT(tg.tickets_id) AS total
            FROM glpi_groups_tickets tg
            JOIN glpi_groups g  ON g.id = tg.groups_id
            JOIN glpi_tickets t ON t.id = tg.tickets_id
            WHERE tg.type = 2 AND t.is_deleted = 0 $dw $ew
            GROUP BY g.id ORDER BY total DESC LIMIT 10
        ");
        $out = [];
        while ($r = $DB->fetchAssoc($res)) {
            $out[] = ['label' => $r['label'], 'total' => (int)$r['total']];
        }
        return $out;
    }

    // -------------------------------------------------------
    // MÉTRICA 5 — Por categoria (top 10)
    // -------------------------------------------------------

    static function getByCategory(string $period, int $eid, string $from, string $to): array {
        global $DB;
        $dw = self::buildDateWhere($period, $from, $to);
        $ew = self::buildEntityWhere($eid);

        $res = $DB->query("
            SELECT COALESCE(ic.completename,'Sem categoria') AS label, COUNT(*) AS total
            FROM glpi_tickets t
            LEFT JOIN glpi_itilcategories ic ON ic.id = t.itilcategories_id
            WHERE t.is_deleted = 0 $dw $ew
            GROUP BY ic.completename ORDER BY total DESC LIMIT 10
        ");
        $out = [];
        while ($r = $DB->fetchAssoc($res)) {
            $out[] = ['label' => $r['label'], 'total' => (int)$r['total']];
        }
        return $out;
    }

    // -------------------------------------------------------
    // MÉTRICA 6 — Por tipo (Incidente vs Requisição)
    // -------------------------------------------------------

    static function getByType(string $period, int $eid, string $from, string $to): array {
        global $DB;
        $dw = self::buildDateWhere($period, $from, $to);
        $ew = self::buildEntityWhere($eid);

        $labels = [1=>'Incidente', 2=>'Requisição'];
        $res = $DB->query("
            SELECT type, COUNT(*) AS total
            FROM glpi_tickets t
            WHERE is_deleted = 0 $dw $ew
            GROUP BY type
        ");
        $out = [];
        while ($r = $DB->fetchAssoc($res)) {
            $out[] = ['label' => $labels[$r['type']] ?? 'Outro', 'total' => (int)$r['total']];
        }
        return $out;
    }

    // -------------------------------------------------------
    // MÉTRICA 7 — Por prioridade
    // -------------------------------------------------------

    static function getByPriority(string $period, int $eid, string $from, string $to): array {
        global $DB;
        $dw = self::buildDateWhere($period, $from, $to);
        $ew = self::buildEntityWhere($eid);

        $labels = [1=>'Muito baixa',2=>'Baixa',3=>'Média',4=>'Alta',5=>'Muito alta',6=>'Crítica'];
        $colors = [1=>'#64b5f6',2=>'#81c784',3=>'#fff176',4=>'#ffb74d',5=>'#e57373',6=>'#b71c1c'];
        $res = $DB->query("
            SELECT priority, COUNT(*) AS total
            FROM glpi_tickets t
            WHERE is_deleted = 0 $dw $ew
            GROUP BY priority ORDER BY priority DESC
        ");
        $out = [];
        while ($r = $DB->fetchAssoc($res)) {
            $p = (int)$r['priority'];
            $out[] = ['label' => $labels[$p] ?? 'P'.$p, 'color' => $colors[$p] ?? '#ccc', 'total' => (int)$r['total']];
        }
        return $out;
    }

    // -------------------------------------------------------
    // MÉTRICA 8 — Tempo médio por prioridade
    // -------------------------------------------------------

    static function getAvgTime(string $period, int $eid, string $from, string $to): array {
        global $DB;
        $dw = self::buildDateWhere($period, $from, $to);
        $ew = self::buildEntityWhere($eid);

        $labels = [1=>'Muito baixa',2=>'Baixa',3=>'Média',4=>'Alta',5=>'Muito alta',6=>'Crítica'];
        $res = $DB->query("
            SELECT priority,
                ROUND(AVG(TIMESTAMPDIFF(HOUR, date_creation, solvedate)), 1) AS avg_h
            FROM glpi_tickets t
            WHERE is_deleted = 0 AND solvedate IS NOT NULL AND status IN (5,6)
                $dw $ew
            GROUP BY priority ORDER BY priority
        ");
        $out = [];
        while ($r = $DB->fetchAssoc($res)) {
            $p = (int)$r['priority'];
            $out[] = ['label' => $labels[$p] ?? 'P'.$p, 'avg_h' => (float)$r['avg_h']];
        }
        return $out;
    }

    // -------------------------------------------------------
    // MÉTRICA 9 — Série temporal (chamados por dia)
    // -------------------------------------------------------

    static function getTimeSeries(string $period, int $eid, string $from, string $to): array {
        global $DB;
        $dw = self::buildDateWhere($period, $from, $to);
        $ew = self::buildEntityWhere($eid);

        $res = $DB->query("
            SELECT DATE(date_creation) AS dia, COUNT(*) AS total
            FROM glpi_tickets t
            WHERE is_deleted = 0 $dw $ew
            GROUP BY dia ORDER BY dia ASC
        ");
        $out = [];
        while ($r = $DB->fetchAssoc($res)) {
            $out[] = ['dia' => $r['dia'], 'total' => (int)$r['total']];
        }
        return $out;
    }

    // -------------------------------------------------------
    // MÉTRICA 10 — Inventário de ativos
    // -------------------------------------------------------

    static function getInventory(int $eid): array {
        global $DB;
        $ew = $eid > 0
            ? "AND entities_id IN (" . implode(',', getSonsOf('glpi_entities', $eid)) . ")"
            : '';

        $types = [
            'Computadores' => 'glpi_computers',
            'Impressoras'  => 'glpi_printers',
            'Monitores'    => 'glpi_monitors',
            'Softwares'    => 'glpi_softwares',
            'Redes'        => 'glpi_networkequipments',
            'Smartphones'  => 'glpi_phones',
        ];
        $out = [];
        foreach ($types as $label => $table) {
            $res = $DB->query("SELECT COUNT(*) AS c FROM $table WHERE is_deleted=0 AND is_template=0 $ew");
            $r   = $DB->fetchAssoc($res);
            $out[] = ['label' => $label, 'total' => (int)($r['c'] ?? 0)];
        }
        return $out;
    }

    // -------------------------------------------------------
    // MÉTODO CENTRAL — retorna TODOS os dados em JSON
    // -------------------------------------------------------

    static function getAllData(string $period, int $eid, string $from, string $to): string {
        return json_encode([
            'kpis'       => self::getSummaryKpis($period, $eid, $from, $to),
            'status'     => self::getByStatus($period, $eid, $from, $to),
            'sla'        => self::getSla($period, $eid, $from, $to),
            'technician' => self::getByTechnician($period, $eid, $from, $to),
            'group'      => self::getByGroup($period, $eid, $from, $to),
            'category'   => self::getByCategory($period, $eid, $from, $to),
            'type'       => self::getByType($period, $eid, $from, $to),
            'priority'   => self::getByPriority($period, $eid, $from, $to),
            'avgtime'    => self::getAvgTime($period, $eid, $from, $to),
            'timeseries' => self::getTimeSeries($period, $eid, $from, $to),
            'inventory'  => self::getInventory($eid),
        ]);
    }
}
