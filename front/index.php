<?php
include('../../../inc/includes.php');

Session::checkLoginUser();
Plugin::load('techdashboard');
require_once(__DIR__ . '/../inc/branding.class.php');

$tdBranding = PluginTechdashboardBranding::load();
$tdDashboardTitle = $tdBranding['dashboard_title'] ?: 'Dashboard';
$tdCompanyName = trim((string)($tdBranding['company_name'] ?? ''));
$tdCsvPrefix = preg_replace('/[^a-zA-Z0-9_-]+/', '_', strtolower($tdDashboardTitle ?: 'dashboard'));

global $DB;

$loggedUserId = (int)Session::getLoginUserID();
$loggedUserName = $_SESSION['glpifriendlyname'] ?? $_SESSION['glpiname'] ?? 'Usuário não identificado';
$exportGeneratedAt = date('d/m/Y H:i:s');

$year   = (int)($_GET['year'] ?? date('Y'));
$period = $_GET['period'] ?? 'q1';
$eid    = (int)($_GET['entity'] ?? 0);
$from   = $_GET['date_from'] ?? '';
$to     = $_GET['date_to'] ?? '';

$hiddenUsersRaw = $_GET['hidden_users'] ?? '';
$hiddenUsers = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$hiddenUsersRaw)), static function($id) {
    return $id > 0;
})));
$hiddenUsersParam = implode(',', $hiddenUsers);

$validPeriods = ['q1','q2','q3','q4','custom'];
if (!in_array($period, $validPeriods, true)) {
    $period = 'q1';
}

$quarterMap = [
    'q1' => ['label' => '1º Quarter', 'start' => "$year-01-01", 'end' => "$year-03-31", 'months' => [1,2,3]],
    'q2' => ['label' => '2º Quarter', 'start' => "$year-04-01", 'end' => "$year-06-30", 'months' => [4,5,6]],
    'q3' => ['label' => '3º Quarter', 'start' => "$year-07-01", 'end' => "$year-09-30", 'months' => [7,8,9]],
    'q4' => ['label' => '4º Quarter', 'start' => "$year-10-01", 'end' => "$year-12-31", 'months' => [10,11,12]],
];

if ($period === 'custom' && $from && $to) {
    $periodStart = $from;
    $periodEnd   = $to;
    $periodLabel = 'Personalizado';
    $periodMonths = range((int)date('n', strtotime($from)), (int)date('n', strtotime($to)));
} else {
    $q = $quarterMap[$period];
    $periodStart  = $q['start'];
    $periodEnd    = $q['end'];
    $periodLabel  = $q['label'];
    $periodMonths = $q['months'];
}

$today = date('Y-m-d');
$yearStart = "$year-01-01";
$yearEnd   = "$year-12-31";

function td_sql($value) {
    global $DB;
    return method_exists($DB, 'escape') ? $DB->escape($value) : addslashes($value);
}

function td_entity_where(int $eid, string $alias = 't'): string {
    if ($eid <= 0) {
        return '';
    }
    $sons = getSonsOf('glpi_entities', $eid);
    if (empty($sons)) {
        $sons = [$eid];
    }
    $ids = implode(',', array_map('intval', $sons));
    return " AND {$alias}.entities_id IN ($ids)";
}

function td_hidden_users_where(string $alias = 't'): string {
    global $hiddenUsers;

    if (empty($hiddenUsers)) {
        return '';
    }

    $ids = implode(',', array_map('intval', $hiddenUsers));

    return "
        AND {$alias}.users_id_recipient NOT IN ($ids)
        AND NOT EXISTS (
            SELECT 1
            FROM glpi_tickets_users tu_hidden
            WHERE tu_hidden.tickets_id = {$alias}.id
              AND tu_hidden.type = 1
              AND tu_hidden.users_id IN ($ids)
        )
    ";
}

function td_get_users_for_filter(): array {
    global $DB;

    $out = [];
    $res = $DB->query("
        SELECT
            u.id,
            u.name,
            u.firstname,
            u.realname,
            u.is_active,
            COALESCE(ut.name, '') AS user_title
        FROM glpi_users u
        LEFT JOIN glpi_usertitles ut ON ut.id = u.usertitles_id
        WHERE u.is_deleted = 0
        ORDER BY u.name ASC, u.firstname ASC, u.realname ASC
    ");

    if ($res) {
        while ($r = $DB->fetchAssoc($res)) {
            $name = trim((string)($r['name'] ?? ''));
            $full = trim(trim((string)($r['firstname'] ?? '')) . ' ' . trim((string)($r['realname'] ?? '')));
            $label = $name;
            if ($full !== '' && $full !== $name) {
                $label .= ' — ' . $full;
            }
            if ($label === '') {
                $label = 'Usuário #' . (int)$r['id'];
            }

            $out[] = [
                'id' => (int)$r['id'],
                'label' => $label,
                'title' => trim((string)($r['user_title'] ?? '')),
                'is_service_account' => in_array(
                    mb_strtolower(trim((string)($r['user_title'] ?? '')), 'UTF-8'),
                    ['conta de serviço', 'contas de serviço', 'conta de servico', 'contas de servico'],
                    true
                ),
                'active' => (int)($r['is_active'] ?? 0)
            ];
        }
    }

    return $out;
}

function td_date_where(string $start, string $end, string $field): string {
    $start = td_sql($start);
    $end   = td_sql($end);
    return " AND {$field} BETWEEN '$start 00:00:00' AND '$end 23:59:59'";
}

function td_month_name(int $m): string {
    $names = [
        1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr',
        5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago',
        9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez'
    ];
    return $names[$m] ?? (string)$m;
}

function td_period_status(string $start, string $end): string {
    $today = date('Y-m-d');
    if ($today < $start) {
        return 'Não iniciado';
    }
    if ($today > $end) {
        return 'Concluído';
    }
    return 'Em andamento';
}

function td_metric_sla(string $start, string $end, int $eid): array {
    global $DB;
    $ew = td_entity_where($eid, 't');
    $uw = td_hidden_users_where('t');
    $dw = td_date_where($start, $end, 't.solvedate');

    $res = $DB->query("
        SELECT
            SUM(t.time_to_resolve IS NOT NULL AND t.solvedate IS NOT NULL AND t.solvedate <= t.time_to_resolve) AS dentro,
            SUM(t.time_to_resolve IS NOT NULL AND t.solvedate IS NOT NULL) AS total
        FROM glpi_tickets t
        WHERE t.is_deleted = 0
          AND t.solvedate IS NOT NULL
          AND t.status = 6
          $dw
          $ew
          $uw
    ");

    $r = $DB->fetchAssoc($res) ?: [];
    $inside = (int)($r['dentro'] ?? 0);
    $total  = (int)($r['total'] ?? 0);
    $pct    = $total > 0 ? round(($inside / $total) * 100, 2) : 0;

    return ['inside' => $inside, 'total' => $total, 'pct' => $pct];
}

function td_metric_isu(string $start, string $end, int $eid): array {
    global $DB;

    $uw = td_hidden_users_where('t');
    $ew = '';
    if ($eid > 0) {
        $sons = getSonsOf('glpi_entities', $eid);
        if (empty($sons)) {
            $sons = [$eid];
        }
        $ids = implode(',', array_map('intval', $sons));
        $ew = " AND t.entities_id IN ($ids)";
    }

    $dw = td_date_where($start, $end, 'COALESCE(ts.date_answered, ts.date_begin)');

    $res = $DB->query("
        SELECT
            AVG(ts.satisfaction) AS media,
            COUNT(*) AS total
        FROM glpi_ticketsatisfactions ts
        INNER JOIN glpi_tickets t ON t.id = ts.tickets_id
        WHERE ts.satisfaction IS NOT NULL
          AND t.is_deleted = 0
          AND t.status = 6
          $dw
          $ew
          $uw
    ");

    $r = $DB->fetchAssoc($res) ?: [];
    $avg = round((float)($r['media'] ?? 0), 2);
    $total = (int)($r['total'] ?? 0);
    $pct = $avg > 0 ? round(($avg / 5) * 100, 2) : 0;

    return ['avg' => $avg, 'total' => $total, 'pct' => $pct];
}

function td_monthly_sla(array $months, int $year, int $eid): array {
    $out = [];
    $currentYear = (int)date('Y');
    $currentMonth = (int)date('n');

    foreach ($months as $m) {
        $start = sprintf('%04d-%02d-01', $year, $m);
        $end = date('Y-m-t', strtotime($start));

        $isFuture = ($year > $currentYear) || ($year === $currentYear && $m > $currentMonth);
        if ($isFuture) {
            $out[] = ['label' => td_month_name($m), 'value' => null, 'status' => 'Futuro'];
            continue;
        }

        $metric = td_metric_sla($start, $end, $eid);
        $out[] = [
            'label' => td_month_name($m),
            'value' => $metric['total'] > 0 ? $metric['pct'] : 0,
            'status' => $metric['total'] > 0 ? 'Realizado' : 'Sem dados',
            'total' => $metric['total'],
            'inside' => $metric['inside']
        ];
    }
    return $out;
}

function td_monthly_isu(array $months, int $year, int $eid): array {
    $out = [];
    $currentYear = (int)date('Y');
    $currentMonth = (int)date('n');

    foreach ($months as $m) {
        $start = sprintf('%04d-%02d-01', $year, $m);
        $end = date('Y-m-t', strtotime($start));

        $isFuture = ($year > $currentYear) || ($year === $currentYear && $m > $currentMonth);
        if ($isFuture) {
            $out[] = ['label' => td_month_name($m), 'value' => null, 'status' => 'Futuro'];
            continue;
        }

        $metric = td_metric_isu($start, $end, $eid);
        $out[] = [
            'label' => td_month_name($m),
            'value' => $metric['total'] > 0 ? $metric['pct'] : 0,
            'status' => $metric['total'] > 0 ? 'Realizado' : 'Sem dados',
            'total' => $metric['total'],
            'avg' => $metric['avg']
        ];
    }
    return $out;
}



function td_metric_sla_quarter_points(array $months, int $year, int $eid): array {
    $insideTotal = 0;
    $slaTotal = 0;
    $sumPct = 0.0;
    $points = 0.0;
    $monthWeight = 25 / 3;

    $currentYear = (int)date('Y');
    $currentMonth = (int)date('n');

    foreach ($months as $m) {
        $isFuture = ($year > $currentYear) || ($year === $currentYear && $m > $currentMonth);

        if ($isFuture) {
            $monthPct = 0.0;
        } else {
            $start = sprintf('%04d-%02d-01', $year, $m);
            $end = date('Y-m-t', strtotime($start));

            $metric = td_metric_sla($start, $end, $eid);

            $insideTotal += (int)($metric['inside'] ?? 0);
            $slaTotal += (int)($metric['total'] ?? 0);

            $monthPct = (float)($metric['pct'] ?? 0);
        }

        $sumPct += $monthPct;
        $points += ($monthPct / 100) * $monthWeight;
    }

    return [
        'inside' => $insideTotal,
        'total' => $slaTotal,
        'pct' => round($sumPct / 3, 2),
        'points' => round($points, 2),
        'max_points' => 25.0
    ];
}

function td_metric_isu_quarter_points(array $months, int $year, int $eid): array {
    $responseTotal = 0;
    $sumPct = 0.0;
    $points = 0.0;
    $monthWeight = 25 / 3;

    $currentYear = (int)date('Y');
    $currentMonth = (int)date('n');

    foreach ($months as $m) {
        $isFuture = ($year > $currentYear) || ($year === $currentYear && $m > $currentMonth);

        if ($isFuture) {
            $monthPct = 0.0;
        } else {
            $start = sprintf('%04d-%02d-01', $year, $m);
            $end = date('Y-m-t', strtotime($start));

            $metric = td_metric_isu($start, $end, $eid);

            $responseTotal += (int)($metric['total'] ?? 0);
            $monthPct = (float)($metric['pct'] ?? 0);
        }

        $sumPct += $monthPct;
        $points += ($monthPct / 100) * $monthWeight;
    }

    $pct = round($sumPct / 3, 2);

    return [
        'avg' => round(($pct / 100) * 5, 2),
        'total' => $responseTotal,
        'pct' => $pct,
        'points' => round($points, 2),
        'max_points' => 25.0
    ];
}

function td_metric_sla_year_points(int $year, int $eid): array {
    $insideTotal = 0;
    $slaTotal = 0;
    $totalPoints = 0.0;

    $quarters = [
        [1,2,3],
        [4,5,6],
        [7,8,9],
        [10,11,12]
    ];

    foreach ($quarters as $months) {
        $q = td_metric_sla_quarter_points($months, $year, $eid);
        $insideTotal += (int)($q['inside'] ?? 0);
        $slaTotal += (int)($q['total'] ?? 0);
        $totalPoints += (float)($q['points'] ?? 0);
    }

    return [
        'inside' => $insideTotal,
        'total' => $slaTotal,
        'pct' => round($totalPoints, 2),
        'points' => round($totalPoints, 2),
        'max_points' => 100.0
    ];
}

function td_metric_isu_year_points(int $year, int $eid): array {
    $responseTotal = 0;
    $totalPoints = 0.0;

    $quarters = [
        [1,2,3],
        [4,5,6],
        [7,8,9],
        [10,11,12]
    ];

    foreach ($quarters as $months) {
        $q = td_metric_isu_quarter_points($months, $year, $eid);
        $responseTotal += (int)($q['total'] ?? 0);
        $totalPoints += (float)($q['points'] ?? 0);
    }

    return [
        'avg' => round(($totalPoints / 100) * 5, 2),
        'total' => $responseTotal,
        'pct' => round($totalPoints, 2),
        'points' => round($totalPoints, 2),
        'max_points' => 100.0
    ];
}


function td_quarter_bounds(int $year, int $quarter): array {
    $starts = [1 => '01-01', 2 => '04-01', 3 => '07-01', 4 => '10-01'];
    $ends   = [1 => '03-31', 2 => '06-30', 3 => '09-30', 4 => '12-31'];
    return [
        'start' => sprintf('%04d-%s', $year, $starts[$quarter]),
        'end'   => sprintf('%04d-%s', $year, $ends[$quarter]),
        'label' => $quarter . 'º Quarter'
    ];
}

function td_quarter_contribution_row(int $year, int $quarter, int $eid, string $metricType): array {
    $bounds = td_quarter_bounds($year, $quarter);
    $today = date('Y-m-d');

    $quarterMonthsMap = [
        1 => [1,2,3],
        2 => [4,5,6],
        3 => [7,8,9],
        4 => [10,11,12]
    ];

    $months = $quarterMonthsMap[$quarter] ?? [1,2,3];

    if ($today < $bounds['start']) {
        return [
            'quarter' => 'Q' . $quarter,
            'label' => $bounds['label'],
            'result' => null,
            'weight' => 25.0,
            'points' => null,
            'status' => 'Futuro',
            'total' => 0
        ];
    }

    $metric = $metricType === 'isu'
        ? td_metric_isu_quarter_points($months, $year, $eid)
        : td_metric_sla_quarter_points($months, $year, $eid);

    return [
        'quarter' => 'Q' . $quarter,
        'label' => $bounds['label'],
        'result' => (float)($metric['pct'] ?? 0),
        'weight' => 25.0,
        'points' => (float)($metric['points'] ?? 0),
        'status' => td_period_status($bounds['start'], $bounds['end']),
        'total' => (int)($metric['total'] ?? 0),
    ];
}

function td_quarter_contributions(int $year, int $eid, string $metricType): array {
    $rows = [];
    $totalPoints = 0.0;
    foreach ([1,2,3,4] as $q) {
        $row = td_quarter_contribution_row($year, $q, $eid, $metricType);
        if ($row['points'] !== null) {
            $totalPoints += (float)$row['points'];
        }
        $rows[] = $row;
    }
    return [
        'rows' => $rows,
        'total_points' => round($totalPoints, 2),
        'max_points' => 100.0,
        'metric' => $metricType
    ];
}

function td_summary_kpis(string $start, string $end, int $eid): array {
    global $DB;
    $ew = td_entity_where($eid, 't');
    $uw = td_hidden_users_where('t');
    $dw = td_date_where($start, $end, 't.date_creation');

    $res = $DB->query("
        SELECT
            COUNT(*) AS total,
            SUM(t.status IN (1,2,3,4)) AS abertos,
            SUM(t.status = 6) AS fechados,
            SUM(t.status = 4) AS pendentes,
            ROUND(AVG(CASE WHEN t.solvedate IS NOT NULL AND t.status = 6 THEN TIMESTAMPDIFF(HOUR, t.date_creation, t.solvedate) END), 1) AS avg_h
        FROM glpi_tickets t
        WHERE t.is_deleted = 0
          $dw
          $ew
          $uw
    ");
    return $DB->fetchAssoc($res) ?: [];
}

function td_group_query(string $sql, string $labelField = 'label', string $valueField = 'total'): array {
    global $DB;
    $res = $DB->query($sql);
    $out = [];
    if ($res) {
        while ($r = $DB->fetchAssoc($res)) {
            $out[] = [
                'label' => html_entity_decode($r[$labelField] ?: 'Não informado', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'total' => (int)($r[$valueField] ?? 0)
            ];
        }
    }
    return $out;
}

function td_dashboard_data(string $start, string $end, int $eid): array {
    global $DB;
    $ew = td_entity_where($eid, 't');
    $uw = td_hidden_users_where('t');
    $dwCreation = td_date_where($start, $end, 't.date_creation');

    $statusLabels = [1=>'Novo',2=>'Em atendimento',3=>'Planejado',4=>'Pendente',5=>'Solucionado',6=>'Fechado'];
    $priorityLabels = [1=>'Muito baixa',2=>'Baixa',3=>'Média',4=>'Alta',5=>'Muito alta',6=>'Crítica'];
    $typeLabels = [1=>'Incidente', 2=>'Requisição'];

    // Sempre exibe todos os status, inclusive com zero, para fins de auditoria/visão completa
    $statusMap = [];
    $res = $DB->query("
        SELECT t.status, COUNT(*) total
        FROM glpi_tickets t
        WHERE t.is_deleted = 0 $dwCreation $ew $uw
        GROUP BY t.status
        ORDER BY t.status
    ");
    while ($r = $DB->fetchAssoc($res)) {
        $statusMap[(int)$r['status']] = (int)$r['total'];
    }

    $status = [];
    foreach ($statusLabels as $statusId => $statusName) {
        $status[] = [
            'label' => $statusName,
            'total' => (int)($statusMap[$statusId] ?? 0)
        ];
    }

    $type = [];
    $res = $DB->query("
        SELECT t.type, COUNT(*) total
        FROM glpi_tickets t
        WHERE t.is_deleted = 0 $dwCreation $ew $uw
        GROUP BY t.type
    ");
    while ($r = $DB->fetchAssoc($res)) {
        $tp = (int)$r['type'];
        $type[] = ['label' => $typeLabels[$tp] ?? "Tipo $tp", 'total' => (int)$r['total']];
    }

    $priority = [];
    $res = $DB->query("
        SELECT t.priority, COUNT(*) total
        FROM glpi_tickets t
        WHERE t.is_deleted = 0 $dwCreation $ew $uw
        GROUP BY t.priority
        ORDER BY t.priority DESC
    ");
    while ($r = $DB->fetchAssoc($res)) {
        $p = (int)$r['priority'];
        $priority[] = ['label' => ($priorityLabels[$p] ?? "P$p") . ' (' . $p . ')', 'total' => (int)$r['total']];
    }

    $sla = td_metric_sla($start, $end, $eid);
    $slaArr = [
        ['label' => 'Dentro do SLA', 'total' => $sla['inside']],
        ['label' => 'Fora do SLA', 'total' => max($sla['total'] - $sla['inside'], 0)],
    ];

    $technician = td_group_query("
        SELECT TRIM(CONCAT(COALESCE(u.firstname,''),' ',COALESCE(u.realname,''))) AS label, COUNT(tu.tickets_id) AS total
        FROM glpi_tickets_users tu
        INNER JOIN glpi_users u ON u.id = tu.users_id
        INNER JOIN glpi_tickets t ON t.id = tu.tickets_id
        WHERE tu.type = 2 AND t.is_deleted = 0 $dwCreation $ew $uw
        GROUP BY u.id
        ORDER BY total DESC
        LIMIT 10
    ");

    $groups = td_group_query("
        SELECT g.name AS label, COUNT(tg.tickets_id) AS total
        FROM glpi_groups_tickets tg
        INNER JOIN glpi_groups g ON g.id = tg.groups_id
        INNER JOIN glpi_tickets t ON t.id = tg.tickets_id
        WHERE tg.type = 2 AND t.is_deleted = 0 $dwCreation $ew $uw
        GROUP BY g.id
        ORDER BY total DESC
        LIMIT 10
    ");

    $category = td_group_query("
        SELECT
            CASE
                WHEN TRIM(COALESCE(ic.completename,'')) <> ''
                    THEN TRIM(SUBSTRING_INDEX(ic.completename, '>', 1))
                WHEN TRIM(COALESCE(ic.name,'')) <> ''
                    THEN ic.name
                ELSE 'Sem categoria'
            END AS label,
            COUNT(*) AS total
        FROM glpi_tickets t
        LEFT JOIN glpi_itilcategories ic
            ON ic.id = t.itilcategories_id
        WHERE t.is_deleted = 0
          $dwCreation
          $ew
          $uw
        GROUP BY label
        ORDER BY total DESC
        LIMIT 20
    ");

    $department = td_group_query("
        SELECT COALESCE(l.name, 'Sem localização') AS label, COUNT(DISTINCT t.id) AS total
        FROM glpi_tickets t
        LEFT JOIN glpi_tickets_users tu ON tu.tickets_id = t.id AND tu.type = 1
        LEFT JOIN glpi_users u ON u.id = tu.users_id
        LEFT JOIN glpi_locations l ON l.id = u.locations_id
        WHERE t.is_deleted = 0 $dwCreation $ew $uw
        GROUP BY l.id, l.name
        ORDER BY total DESC
        LIMIT 10
    ");

    $priorityRequest = [];
    $priorityIncident = [];
    foreach ([2 => 'priorityRequest', 1 => 'priorityIncident'] as $typeId => $varName) {
        $res = $DB->query("
            SELECT t.priority, COUNT(*) total
            FROM glpi_tickets t
            WHERE t.is_deleted = 0
              AND t.type = $typeId
              $dwCreation
              $ew
              $uw
            GROUP BY t.priority
            ORDER BY t.priority DESC
        ");
        $arr = [];
        while ($r = $DB->fetchAssoc($res)) {
            $p = (int)$r['priority'];
            $arr[] = ['label' => ($priorityLabels[$p] ?? "P$p") . ' (' . $p . ')', 'total' => (int)$r['total']];
        }
        if ($varName === 'priorityRequest') {
            $priorityRequest = $arr;
        } else {
            $priorityIncident = $arr;
        }
    }

    $avgtime = [];
    $res = $DB->query("
        SELECT t.priority, ROUND(AVG(TIMESTAMPDIFF(HOUR, t.date_creation, t.solvedate)), 1) AS avg_h
        FROM glpi_tickets t
        WHERE t.is_deleted = 0
          AND t.solvedate IS NOT NULL
          AND t.status = 6
          $dwCreation
          $ew
          $uw
        GROUP BY t.priority
        ORDER BY t.priority
    ");
    while ($r = $DB->fetchAssoc($res)) {
        $p = (int)$r['priority'];
        $avgtime[] = ['label' => ($priorityLabels[$p] ?? "P$p") . ' (' . $p . ')', 'avg_h' => (float)$r['avg_h']];
    }

    $timeseries = [];
    $res = $DB->query("
        SELECT
            DATE(t.date_creation) AS dia,
            COUNT(*) AS total,
            SUM(CASE WHEN t.type = 2 THEN 1 ELSE 0 END) AS requisicoes,
            SUM(CASE WHEN t.type = 1 THEN 1 ELSE 0 END) AS incidentes
        FROM glpi_tickets t
        WHERE t.is_deleted = 0
          $dwCreation
          $ew
          $uw
        GROUP BY dia
        ORDER BY dia ASC
    ");
    while ($r = $DB->fetchAssoc($res)) {
        $timeseries[] = [
            'dia' => $r['dia'],
            'total' => (int)$r['total'],
            'requisicoes' => (int)$r['requisicoes'],
            'incidentes' => (int)$r['incidentes']
        ];
    }

    $inventory = [];
    $invEw = '';
    if ($eid > 0) {
        $sons = getSonsOf('glpi_entities', $eid);
        if (empty($sons)) {
            $sons = [$eid];
        }
        $ids = implode(',', array_map('intval', $sons));
        $invEw = " AND entities_id IN ($ids)";
    }
    $types = [
        'Computadores' => 'glpi_computers',
        'Impressoras'  => 'glpi_printers',
        'Monitores'    => 'glpi_monitors',
        'Softwares'    => 'glpi_softwares',
        'Redes'        => 'glpi_networkequipments',
        'Smartphones'  => 'glpi_phones',
    ];
    foreach ($types as $label => $table) {
        $res = $DB->query("SELECT COUNT(*) AS c FROM $table WHERE is_deleted=0 AND is_template=0 $invEw");
        $r = $DB->fetchAssoc($res);
        $inventory[] = ['label' => $label, 'total' => (int)($r['c'] ?? 0)];
    }

    return [
        'status' => $status,
        'type' => $type,
        'priority' => $priority,
        'sla' => $slaArr,
        'technician' => $technician,
        'group' => $groups,
        'category' => $category,
        'department' => $department,
        'priorityRequest' => $priorityRequest,
        'priorityIncident' => $priorityIncident,
        'timeseries' => $timeseries,
        'inventory' => $inventory,
    ];
}


$filterUsers = td_get_users_for_filter();

$kpis = td_summary_kpis($periodStart, $periodEnd, $eid);
$d = td_dashboard_data($periodStart, $periodEnd, $eid);

$quarterSla = td_metric_sla_quarter_points($periodMonths, $year, $eid);
$quarterIsu = td_metric_isu_quarter_points($periodMonths, $year, $eid);

$yearSla = td_metric_sla_year_points($year, $eid);
$yearIsu = td_metric_isu_year_points($year, $eid);

$monthlySlaQuarter = td_monthly_sla($periodMonths, $year, $eid);
$monthlyIsuQuarter = td_monthly_isu($periodMonths, $year, $eid);
$monthlySlaYear = td_monthly_sla(range(1,12), $year, $eid);
$monthlyIsuYear = td_monthly_isu(range(1,12), $year, $eid);

$quarterContributionSla = td_quarter_contributions($year, $eid, 'sla');
$quarterContributionIsu = td_quarter_contributions($year, $eid, 'isu');

$metricPayload = [
    'quarter' => [
        'sla' => $quarterSla,
        'isu' => $quarterIsu,
        'monthlySla' => $monthlySlaQuarter,
        'monthlyIsu' => $monthlyIsuQuarter,
        'status' => td_period_status($periodStart, $periodEnd),
        'label' => $periodLabel,
        'start' => $periodStart,
        'end' => $periodEnd,
    ],
    'year' => [
        'sla' => $yearSla,
        'isu' => $yearIsu,
        'monthlySla' => $monthlySlaYear,
        'monthlyIsu' => $monthlyIsuYear,
        'status' => td_period_status($yearStart, $yearEnd),
        'label' => "Ano $year",
        'start' => $yearStart,
        'end' => $yearEnd,
    ],
    'quarterContribution' => [
        'sla' => $quarterContributionSla,
        'isu' => $quarterContributionIsu,
    ]
];

Html::header($tdDashboardTitle, '', 'tools', 'PluginTechdashboardDashboard');
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<style>
:root {
  --bg:#f0f2f5;
  --card:#fff;
  --border:#e2e8f0;
  --primary:<?= htmlspecialchars($tdBranding['primary_color']) ?>;
  --green:#22c55e;
  --red:#ef4444;
  --yellow:#f59e0b;
  --purple:#8b5cf6;
  --text:<?= htmlspecialchars($tdBranding['secondary_color']) ?>;
  --muted:#64748b;
  --radius:14px;
  --shadow:0 1px 5px rgba(0,0,0,.08);
}
#td-wrap * { box-sizing:border-box; font-family:'Segoe UI',sans-serif; }
#td-wrap { background:var(--bg); padding:24px; min-height:100vh; }
.td-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:24px; }
.td-header h1 { font-size:1.5rem; font-weight:800; color:var(--text); margin:0; }
.td-brand-title { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.td-brand-title small { font-size:.75rem; color:var(--muted); font-weight:700; margin-left:4px; }
.td-brand-logo { max-height:42px; max-width:180px; object-fit:contain; background:#fff; border:1px solid var(--border); border-radius:10px; padding:5px; }
.td-filters { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
.td-filters select, .td-filters input[type=date], .td-filters input[type=number] {
  padding:7px 12px; border:1px solid var(--border); border-radius:8px; font-size:.875rem; color:var(--text); background:var(--card);
}
.td-filters button { padding:7px 18px; background:var(--primary); color:#fff; border:none; border-radius:8px; font-size:.875rem; font-weight:700; cursor:pointer; }
#td-custom-range { display:none; gap:8px; align-items:center; }
#td-custom-range.show { display:flex; }
.td-section-title {
  font-size:1rem; font-weight:800; color:var(--text); margin:32px 0 14px; padding-bottom:8px; border-bottom:2px solid var(--border);
}
.td-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:16px; margin-bottom:24px; }
.td-kpi { background:var(--card); border-radius:var(--radius); padding:20px; box-shadow:var(--shadow); border-left:4px solid var(--primary); display:flex; flex-direction:column; gap:6px; }
.td-kpi.green{border-color:var(--green)} .td-kpi.red{border-color:var(--red)} .td-kpi.yellow{border-color:var(--yellow)} .td-kpi.purple{border-color:var(--purple)}
.td-kpi label{font-size:.78rem;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.td-kpi span{font-size:2rem;font-weight:900;color:var(--text)}
.td-kpi small{font-size:.78rem;color:var(--muted)}
.td-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); gap:20px; margin-bottom:8px; }
.td-grid.full{grid-template-columns:1fr} .td-grid.three{grid-template-columns:repeat(auto-fit,minmax(280px,1fr))} .td-grid.two-wide{grid-template-columns:repeat(2,minmax(420px,1fr))}
.td-chart-card { background:var(--card); border-radius:var(--radius); padding:20px; box-shadow:var(--shadow); overflow:hidden; }
.td-chart-card h3 { font-size:.95rem; font-weight:800; color:var(--text); margin:0 0 16px; }
.td-chart-card canvas { max-height:285px; }

.td-chart-card canvas[id="chartStatus"],
.td-chart-card canvas[id="chartType"],
.td-chart-card canvas[id="chartSla"] {
  max-height: 300px;
}
.td-grid.two-wide .td-chart-card canvas { max-height:340px; }
.td-inventory { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; }
.td-inv-item { background:var(--card); border-radius:var(--radius); padding:18px; box-shadow:var(--shadow); text-align:center; }
.td-inv-item .inv-num { font-size:1.8rem; font-weight:900; color:var(--text); }
.td-inv-item .inv-lbl { font-size:.8rem; color:var(--muted); margin-top:4px; }

.metric-panel { background:var(--card); border-radius:18px; box-shadow:var(--shadow); padding:22px; margin-bottom:22px; }
.metric-panel-header { display:flex; align-items:flex-start; justify-content:space-between; gap:18px; flex-wrap:wrap; margin-bottom:16px; }
.metric-panel-title { font-size:1.25rem; font-weight:900; color:var(--text); margin:0; }
.metric-panel-sub { color:var(--muted); font-size:.85rem; margin-top:5px; }
.metric-status { padding:7px 12px; border-radius:999px; background:#e0f2fe; color:#075985; font-weight:800; font-size:.8rem; }
.metric-status.done { background:#dcfce7; color:#166534; }
.metric-status.future { background:#f1f5f9; color:#475569; }
.metric-body { display:grid; grid-template-columns:minmax(280px,360px) 1fr; gap:30px; align-items:center; }
.metric-gauge-box { position:relative; min-height:210px; display:flex; flex-direction:column; align-items:center; justify-content:center; }
.metric-gauge-box canvas { width:100%; max-height:190px; }
.metric-gauge-value { position:absolute; top:92px; left:0; right:0; text-align:center; font-size:2.15rem; font-weight:950; color:var(--primary); }
.metric-gauge-caption { position:absolute; top:138px; left:0; right:0; text-align:center; color:var(--muted); font-weight:700; }
.metric-details { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:10px; margin-top:8px; width:100%; }
.metric-mini { background:#f8fafc; border:1px solid #e5e7eb; border-radius:12px; padding:10px; text-align:center; }
.metric-mini strong { display:block; font-size:1.1rem; color:var(--text); }
.metric-mini span { font-size:.75rem; color:var(--muted); }
.annual-fixed { border:2px solid #dbeafe; }
@media(max-width:780px){ .metric-body{grid-template-columns:1fr} .td-grid, .td-grid.two-wide{grid-template-columns:1fr} .td-header{align-items:flex-start;flex-direction:column} }


/* ===== AJUSTE COMPACTO REAL DAS MÉTRICAS ===== */
.metric-panel {
  padding: 14px !important;
  margin-bottom: 14px !important;
  border-radius: 14px !important;
}

.metric-panel-header {
  margin-bottom: 8px !important;
}

.metric-panel-title {
  font-size: 1rem !important;
  line-height: 1.15 !important;
}

.metric-panel-sub {
  font-size: .72rem !important;
  margin-top: 3px !important;
}

.metric-status {
  padding: 4px 9px !important;
  font-size: .68rem !important;
}

.metric-body {
  grid-template-columns: 230px minmax(0, 1fr) !important;
  gap: 14px !important;
  align-items: center !important;
}

.metric-gauge-box {
  min-height: 145px !important;
  justify-content: flex-start !important;
}

.metric-gauge-box canvas {
  height: 118px !important;
  max-height: 118px !important;
}

.metric-gauge-value {
  top: 54px !important;
  font-size: 1.45rem !important;
}

.metric-gauge-caption {
  top: 86px !important;
  font-size: .72rem !important;
}

.metric-details {
  gap: 6px !important;
  margin-top: -4px !important;
}

.metric-mini {
  padding: 6px !important;
  border-radius: 8px !important;
}

.metric-mini strong {
  font-size: .9rem !important;
}

.metric-mini span {
  font-size: .65rem !important;
}

.metric-line-box {
  height: 175px !important;
  min-height: 175px !important;
  max-height: 175px !important;
  position: relative !important;
}

.metric-line-box canvas {
  height: 175px !important;
  max-height: 175px !important;
}

@media(max-width:780px){
  .metric-body{grid-template-columns:1fr !important;}
  .metric-line-box{height:190px !important;}
}

/* ===== EXPORTAÇÃO ===== */
.export-wrap {
  position: relative;
  display: inline-block;
}

.export-btn {
  padding: 7px 18px;
  background: #16a34a;
  color: #fff;
  border: none;
  border-radius: 8px;
  font-size: .875rem;
  font-weight: 800;
  cursor: pointer;
}

.export-menu {
  display: none;
  position: absolute;
  right: 0;
  top: 40px;
  background: #fff;
  border: 1px solid #dbe3ef;
  border-radius: 10px;
  box-shadow: 0 6px 18px rgba(15,23,42,.16);
  min-width: 150px;
  z-index: 99999;
  overflow: hidden;
}

.export-menu.show {
  display: block;
}

.export-menu button {
  width: 100%;
  padding: 10px 14px;
  border: none;
  background: #fff;
  color: #0f172a;
  text-align: left;
  font-size: .85rem;
  font-weight: 700;
  cursor: pointer;
}

.export-menu button:hover {
  background: #f1f5f9;
}



/* ===== PERSONALIZAÇÃO DA VISÃO ===== */
.layout-actions {
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  align-items:center;
}
.layout-btn {
  padding:7px 12px;
  border:1px solid var(--border);
  background:#fff;
  color:var(--text);
  border-radius:8px;
  font-size:.82rem;
  font-weight:800;
  cursor:pointer;
}
.layout-btn.active {
  background:#16a34a;
  color:#fff;
  border-color:#16a34a;
}
.dashboard-widget {
  position:relative;
  transition:box-shadow .2s, transform .2s, opacity .2s;
}
body.dashboard-edit-mode .dashboard-widget {
  cursor:move;
  outline:2px dashed #93c5fd;
  outline-offset:6px;
  border-radius:14px;
}
body.dashboard-edit-mode .dashboard-widget::before {
  content:'Arraste para reorganizar';
  position:absolute;
  top:-10px;
  right:8px;
  z-index:10;
  background:#2563eb;
  color:#fff;
  font-size:.68rem;
  font-weight:900;
  padding:3px 8px;
  border-radius:999px;
  box-shadow:0 1px 5px rgba(0,0,0,.18);
}
.dashboard-widget.dragging {
  opacity:.45;
  transform:scale(.995);
}
.dashboard-widget.drag-over {
  outline-color:#22c55e !important;
  box-shadow:0 0 0 4px rgba(34,197,94,.15);
}
.contribution-grid {
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(280px,1fr));
  gap:14px;
  margin-bottom:18px;
}
.contribution-card {
  background:var(--card);
  border-radius:var(--radius);
  box-shadow:var(--shadow);
  padding:14px;
  border:1px solid var(--border);
}
.contribution-card h3 {
  margin:0 0 8px;
  font-size:.95rem;
  font-weight:900;
  color:var(--text);
}
.contribution-total {
  font-size:1.65rem;
  font-weight:950;
  color:var(--primary);
  margin-bottom:8px;
}
.contribution-table {
  width:100%;
  border-collapse:collapse;
  font-size:.78rem;
}
.contribution-table th,
.contribution-table td {
  padding:6px 5px;
  border-bottom:1px solid #edf2f7;
  text-align:left;
  white-space:nowrap;
}
.contribution-table th {
  color:var(--muted);
  font-weight:900;
  text-transform:uppercase;
  font-size:.65rem;
}
.contribution-status {
  display:inline-block;
  border-radius:999px;
  padding:2px 7px;
  font-size:.65rem;
  font-weight:900;
  background:#e0f2fe;
  color:#075985;
}
.contribution-status.future { background:#f1f5f9; color:#475569; }
.contribution-status.done { background:#dcfce7; color:#166534; }

@media print {
  .td-filters,
  .export-wrap,
  .main-header,
  .navbar,
  .breadcrumb,
  .search-bar,
  header {
    display: none !important;
  }

  body,
  #td-wrap {
    background: #fff !important;
  }

  #td-wrap {
    padding: 8px !important;
  }

  .td-chart-card,
  .td-kpi,
  .metric-panel,
  .td-inv-item {
    box-shadow: none !important;
    border: 1px solid #dbe3ef !important;
    page-break-inside: avoid !important;
  }

  .metric-line-box {
    height: 160px !important;
  }

  .metric-line-box canvas {
    height: 160px !important;
  }
}


/* ===== FILTRO DE USUÁRIOS OCULTOS ===== */
.user-filter-btn {
  padding:7px 16px;
  background:#475569;
  color:#fff;
  border:none;
  border-radius:8px;
  font-size:.875rem;
  font-weight:700;
  cursor:pointer;
}
.user-filter-btn.has-hidden { background:#7c3aed; }
.user-modal-backdrop {
  display:none;
  position:fixed;
  inset:0;
  background:rgba(15,23,42,.55);
  z-index:99999;
  align-items:center;
  justify-content:center;
  padding:20px;
}
.user-modal-backdrop.show { display:flex; }
.user-modal {
  width:min(760px,96vw);
  max-height:82vh;
  background:#fff;
  border-radius:16px;
  box-shadow:0 20px 60px rgba(0,0,0,.25);
  display:flex;
  flex-direction:column;
  overflow:hidden;
}
.user-modal-header {
  padding:16px 18px;
  border-bottom:1px solid #e2e8f0;
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:14px;
}
.user-modal-title { font-size:1.05rem; font-weight:900; color:#0f172a; margin:0; }
.user-modal-sub { font-size:.78rem; color:#64748b; margin-top:3px; }
.user-modal-close {
  border:none;
  background:#f1f5f9;
  color:#0f172a;
  border-radius:10px;
  width:34px;
  height:34px;
  font-weight:900;
  cursor:pointer;
}
.user-modal-tools {
  padding:12px 18px;
  border-bottom:1px solid #e2e8f0;
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  align-items:center;
}
.user-modal-tools input {
  flex:1;
  min-width:220px;
  padding:9px 12px;
  border:1px solid #cbd5e1;
  border-radius:10px;
}
.user-modal-tools button,
.user-modal-footer button {
  padding:8px 12px;
  border:none;
  border-radius:9px;
  font-weight:800;
  cursor:pointer;
}
.user-modal-tools button { background:#f1f5f9; color:#0f172a; }
.user-list {
  padding:10px 18px;
  overflow:auto;
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
  gap:8px;
}
.user-item {
  border:1px solid #e2e8f0;
  border-radius:10px;
  padding:9px 10px;
  display:flex;
  align-items:center;
  gap:9px;
  background:#fff;
}
.user-item.hidden-by-search { display:none; }
.user-item input { transform:scale(1.05); }
.user-item-label { font-size:.82rem; color:#1e293b; line-height:1.2; }
.user-item-inactive { font-size:.68rem; color:#ef4444; font-weight:800; margin-left:4px; }
.user-modal-footer {
  padding:14px 18px;
  border-top:1px solid #e2e8f0;
  display:flex;
  justify-content:space-between;
  gap:10px;
  flex-wrap:wrap;
}
.user-save { background:#2563eb; color:#fff; }
.user-clear { background:#fee2e2; color:#991b1b; }
.user-cancel { background:#f1f5f9; color:#0f172a; }
@media print { .user-modal-backdrop, .user-filter-btn { display:none !important; } }


/* ===== RODAPÉ DE AUDITORIA ===== */
.audit-footer {
  display: none;
}

body.exporting-report .audit-footer {
  display: block;
  margin-top: 18px;
  padding: 10px 14px;
  border-top: 1px solid #dbe3ef;
  color: #475569;
  font-size: .75rem;
  text-align: right;
  background: transparent;
}

@media print {
  .audit-footer {
    display: block !important;
    position: fixed;
    bottom: 7px;
    left: 18px;
    right: 18px;
    padding-top: 5px;
    border-top: 1px solid #cbd5e1;
    background: #fff;
    color: #334155;
    font-size: 9px;
    z-index: 999999;
    text-align: right;
  }

  @page {
    margin-bottom: 18mm;
  }
}


.widget-move-actions {
  display: none;
  position: absolute;
  top: 8px;
  left: 8px;
  z-index: 20;
  gap: 6px;
}

body.dashboard-edit-mode .widget-move-actions {
  display: flex;
}

.widget-move-actions button {
  border: none;
  background: #2563eb;
  color: #fff;
  border-radius: 8px;
  padding: 4px 8px;
  font-size: .68rem;
  font-weight: 900;
  cursor: pointer;
  box-shadow: 0 1px 5px rgba(0,0,0,.18);
}

.widget-move-actions button:hover {
  background: #1d4ed8;
}

body.dashboard-edit-mode .dashboard-widget {
  cursor: default !important;
}


</style>

<div id="td-wrap">
  <div class="td-header">
    <h1 class="td-brand-title">
      <?php if (!empty($tdBranding['show_logo']) && !empty($tdBranding['logo_url'])): ?>
        <img src="<?= htmlspecialchars($tdBranding['logo_url']) ?>" alt="<?= htmlspecialchars($tdCompanyName ?: $tdDashboardTitle) ?>" class="td-brand-logo">
      <?php endif; ?>
      <span><?= htmlspecialchars($tdDashboardTitle) ?></span>
      <?php if ($tdCompanyName !== ''): ?>
        <small><?= htmlspecialchars($tdCompanyName) ?></small>
      <?php endif; ?>
    </h1>
    <form method="GET" action="" class="td-filters" id="td-form">
      <input type="hidden" name="action" value="dashboard">
      <input type="hidden" name="hidden_users" id="hidden_users" value="<?= htmlspecialchars($hiddenUsersParam) ?>">
      <input type="number" name="year" value="<?= htmlspecialchars((string)$year) ?>" min="2020" max="2100" style="width:95px">

      <select name="period" id="td-period" onchange="toggleCustom(this.value)">
        <option value="q1" <?= $period==='q1' ? 'selected' : '' ?>>1º Quarter</option>
        <option value="q2" <?= $period==='q2' ? 'selected' : '' ?>>2º Quarter</option>
        <option value="q3" <?= $period==='q3' ? 'selected' : '' ?>>3º Quarter</option>
        <option value="q4" <?= $period==='q4' ? 'selected' : '' ?>>4º Quarter</option>
        <option value="custom" <?= $period==='custom' ? 'selected' : '' ?>>Personalizado</option>
      </select>

      <div id="td-custom-range" class="<?= $period==='custom' ? 'show' : '' ?>">
        <input type="date" name="date_from" value="<?= htmlspecialchars($from) ?>" placeholder="De">
        <span style="color:var(--muted)">→</span>
        <input type="date" name="date_to" value="<?= htmlspecialchars($to) ?>" placeholder="Até">
      </div>

      <button type="submit">Atualizar</button>

      <div class="export-wrap">
        <button type="button" class="export-btn" onclick="toggleExportMenu()">Exportar</button>
        <div id="export-menu" class="export-menu">
          <button type="button" onclick="exportarPDF()">PDF</button>
          <button type="button" onclick="exportarExcel()">Excel / CSV</button>
        </div>
      </div>

      <button type="button" id="btnHiddenUsers" class="user-filter-btn <?= !empty($hiddenUsers) ? 'has-hidden' : '' ?>" onclick="openHiddenUsersModal()">
        Ocultar usuários<?= !empty($hiddenUsers) ? ' (' . count($hiddenUsers) . ')' : '' ?>
      </button>

      <div class="layout-actions">
        <button type="button" id="btnCustomizeLayout" class="layout-btn" onclick="toggleLayoutEdit()" title="Editar e salvar a ordem dos quadros">Editar visão</button>
      </div>
    </form>
  </div>
<div id="hiddenUsersModal" class="user-modal-backdrop" aria-hidden="true">
    <div class="user-modal" role="dialog" aria-modal="true" aria-labelledby="hiddenUsersTitle">
      <div class="user-modal-header">
        <div>
          <h2 id="hiddenUsersTitle" class="user-modal-title">Ocultar usuários do relatório</h2>
          <div class="user-modal-sub">Marque usuários de serviço, automações ou contas que não devem entrar nas métricas. A lista vem direto do GLPI.</div>
        </div>
        <button type="button" class="user-modal-close" onclick="closeHiddenUsersModal()">×</button>
      </div>

      <div class="user-modal-tools">
        <input type="text" id="userSearchInput" placeholder="Buscar usuário..." oninput="filterHiddenUsersList()">
        <button type="button" onclick="selectServiceAccounts()">Selecionar contas de serviço</button>
        <button type="button" onclick="checkVisibleUsers(true)">Marcar visíveis</button>
        <button type="button" onclick="checkVisibleUsers(false)">Desmarcar visíveis</button>
      </div>

      <div id="hiddenUsersList" class="user-list">
        <?php foreach ($filterUsers as $user): ?>
          <?php $uid = (int)$user['id']; ?>
          <label class="user-item" data-search="<?= htmlspecialchars(mb_strtolower($user['label'] . ' ' . ($user['title'] ?? ''))) ?>">
            <input type="checkbox" class="hidden-user-checkbox" value="<?= $uid ?>" data-service-account="<?= !empty($user['is_service_account']) ? '1' : '0' ?>" <?= in_array($uid, $hiddenUsers, true) ? 'checked' : '' ?>>
            <span class="user-item-label">
              <?= htmlspecialchars($user['label']) ?>
              <?php if (!empty($user['is_service_account'])): ?>
                <span class="user-item-inactive">Conta de Serviço</span>
              <?php endif; ?>
              <?= empty($user['active']) ? '<span class="user-item-inactive">inativo</span>' : '' ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="user-modal-footer">
        <button type="button" class="user-clear" onclick="clearHiddenUsers()">Limpar ocultos</button>
        <div style="display:flex;gap:10px;">
          <button type="button" class="user-cancel" onclick="closeHiddenUsersModal()">Cancelar</button>
          <button type="button" class="user-save" onclick="saveHiddenUsers()">Salvar filtro</button>
        </div>
      </div>
    </div>
  </div>

  <div id="dashboard-widgets">

  <div class="dashboard-widget" data-widget-id="kpis"><div class="widget-move-actions"><button type="button" onclick="moveWidget(this, -1)">↑</button><button type="button" onclick="moveWidget(this, 1)">↓</button></div>
  <div class="td-kpis">
    <div class="td-kpi">
      <label>Total de chamados</label>
      <span><?= (int)($kpis['total'] ?? 0) ?></span>
      <small>no período selecionado</small>
    </div>
    <div class="td-kpi green">
      <label>Fechados</label>
      <span><?= (int)($kpis['fechados'] ?? 0) ?></span>
      <small><?= ((int)($kpis['total'] ?? 0)) > 0 ? round((((int)($kpis['fechados'] ?? 0))/((int)$kpis['total']))*100,1) : 0 ?>% do total</small>
    </div>
    <div class="td-kpi red">
      <label>Em aberto</label>
      <span><?= (int)($kpis['abertos'] ?? 0) ?></span>
      <small>aguardando resolução</small>
    </div>
    <div class="td-kpi yellow">
      <label>Pendentes</label>
      <span><?= (int)($kpis['pendentes'] ?? 0) ?></span>
      <small>aguardando cliente</small>
    </div>
    <div class="td-kpi purple">
      <label>Tempo médio</label>
      <span><?= htmlspecialchars((string)($kpis['avg_h'] ?? '—')) ?></span>
      <small>horas até resolução</small>
    </div>
  </div>
  </div>

  <div class="dashboard-widget" data-widget-id="metricas-quarter"><div class="widget-move-actions"><button type="button" onclick="moveWidget(this, -1)">↑</button><button type="button" onclick="moveWidget(this, 1)">↓</button></div>
  <div class="td-section-title">Métricas do Quarter Selecionado</div>

  <div class="metric-panel">
    <div class="metric-panel-header">
      <div>
        <h2 class="metric-panel-title">Garantir cumprimento do SLA</h2>
        <div class="metric-panel-sub">
          <?= htmlspecialchars($periodLabel) ?> |
          <?= date('d/m/Y', strtotime($periodStart)) ?> até <?= date('d/m/Y', strtotime($periodEnd)) ?> |
          cálculo por data de fechamento
        </div>
      </div>
      <?php $qs = td_period_status($periodStart, $periodEnd); ?>
      <div class="metric-status <?= $qs === 'Concluído' ? 'done' : ($qs === 'Não iniciado' ? 'future' : '') ?>"><?= $qs ?></div>
    </div>

    <div class="metric-body">
      <div class="metric-gauge-box">
        <canvas id="gaugeSlaQuarter"></canvas>
        <div class="metric-gauge-value"><?= number_format((float)$quarterSla['pct'], 2, ',', '.') ?>%</div>
        <div class="metric-gauge-caption">SLA do período</div>
        <div class="metric-details">
          <div class="metric-mini"><strong><?= (int)$quarterSla['inside'] ?></strong><span>Dentro do SLA</span></div>
          <div class="metric-mini"><strong><?= (int)$quarterSla['total'] ?></strong><span>Total com SLA</span></div>
        </div>
      </div>
      <div class="metric-line-box">
        <canvas id="chartSlaQuarter"></canvas>
      </div>
    </div>
  </div>

  <div class="metric-panel">
    <div class="metric-panel-header">
      <div>
        <h2 class="metric-panel-title">Índice de Satisfação do Usuário</h2>
        <div class="metric-panel-sub">
          <?= htmlspecialchars($periodLabel) ?> |
          <?= date('d/m/Y', strtotime($periodStart)) ?> até <?= date('d/m/Y', strtotime($periodEnd)) ?> |
          respostas do período
        </div>
      </div>
      <div class="metric-status <?= $qs === 'Concluído' ? 'done' : ($qs === 'Não iniciado' ? 'future' : '') ?>"><?= $qs ?></div>
    </div>

    <div class="metric-body">
      <div class="metric-gauge-box">
        <canvas id="gaugeIsuQuarter"></canvas>
        <div class="metric-gauge-value" style="color:var(--purple)"><?= number_format((float)$quarterIsu['pct'], 2, ',', '.') ?>%</div>
        <div class="metric-gauge-caption">ISU do período</div>
        <div class="metric-details">
          <div class="metric-mini"><strong><?= number_format((float)$quarterIsu['avg'], 2, ',', '.') ?></strong><span>Média de 5</span></div>
          <div class="metric-mini"><strong><?= (int)$quarterIsu['total'] ?></strong><span>Respostas</span></div>
        </div>
      </div>
      <div class="metric-line-box">
        <canvas id="chartIsuQuarter"></canvas>
      </div>
    </div>

  </div>

  <div class="dashboard-widget" data-widget-id="contribuicao-quarter"><div class="widget-move-actions"><button type="button" onclick="moveWidget(this, -1)">↑</button><button type="button" onclick="moveWidget(this, 1)">↓</button></div>
  <div class="td-section-title">Contribuição Anual por Quarter</div>
  <div class="contribution-grid">
    <div class="contribution-card">
      <h3>SLA — contribuição para o ano</h3>
      <div class="contribution-total"><?= number_format((float)$quarterContributionSla['total_points'], 2, ',', '.') ?>%</div>
      <table class="contribution-table">
        <thead><tr><th>Quarter</th><th>Resultado</th><th>Peso</th><th>% do Ano</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($quarterContributionSla['rows'] as $row): ?>
          <?php $stClass = $row['status'] === 'Futuro' ? 'future' : ($row['status'] === 'Concluído' ? 'done' : ''); ?>
          <tr>
            <td><?= htmlspecialchars($row['quarter']) ?></td>
            <td><?= $row['result'] === null ? '—' : number_format((float)$row['result'], 2, ',', '.') . '%' ?></td>
            <td>25%</td>
            <td><?= $row['points'] === null ? '—' : number_format((float)$row['points'], 2, ',', '.') . '%' ?></td>
            <td><span class="contribution-status <?= $stClass ?>"><?= htmlspecialchars($row['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="contribution-card">
      <h3>ISU — contribuição para o ano</h3>
      <div class="contribution-total" style="color:var(--purple)"><?= number_format((float)$quarterContributionIsu['total_points'], 2, ',', '.') ?>%</div>
      <table class="contribution-table">
        <thead><tr><th>Quarter</th><th>Resultado</th><th>Peso</th><th>% do Ano</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($quarterContributionIsu['rows'] as $row): ?>
          <?php $stClass = $row['status'] === 'Futuro' ? 'future' : ($row['status'] === 'Concluído' ? 'done' : ''); ?>
          <tr>
            <td><?= htmlspecialchars($row['quarter']) ?></td>
            <td><?= $row['result'] === null ? '—' : number_format((float)$row['result'], 2, ',', '.') . '%' ?></td>
            <td>25%</td>
            <td><?= $row['points'] === null ? '—' : number_format((float)$row['points'], 2, ',', '.') . '%' ?></td>
            <td><span class="contribution-status <?= $stClass ?>"><?= htmlspecialchars($row['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  </div>

  <div class="dashboard-widget" data-widget-id="visao-anual"><div class="widget-move-actions"><button type="button" onclick="moveWidget(this, -1)">↑</button><button type="button" onclick="moveWidget(this, 1)">↓</button></div>
  <div class="td-section-title">Visão Anual Fixa</div>

  <div class="metric-panel annual-fixed">
    <div class="metric-panel-header">
      <div>
        <h2 class="metric-panel-title">SLA — Total do Ano</h2>
        <div class="metric-panel-sub">Janeiro a Dezembro de <?= $year ?> | meses futuros tratados automaticamente</div>
      </div>
      <div class="metric-status <?= td_period_status($yearStart, $yearEnd) === 'Concluído' ? 'done' : '' ?>"><?= td_period_status($yearStart, $yearEnd) ?></div>
    </div>

    <div class="metric-body">
      <div class="metric-gauge-box">
        <canvas id="gaugeSlaYear"></canvas>
        <div class="metric-gauge-value"><?= number_format((float)$yearSla['pct'], 2, ',', '.') ?>%</div>
        <div class="metric-gauge-caption">SLA anual</div>
        <div class="metric-details">
          <div class="metric-mini"><strong><?= (int)$yearSla['inside'] ?></strong><span>Dentro do SLA</span></div>
          <div class="metric-mini"><strong><?= (int)$yearSla['total'] ?></strong><span>Total com SLA</span></div>
        </div>
      </div>
      <div class="metric-line-box">
        <canvas id="chartSlaYear"></canvas>
      </div>
    </div>
  </div>

  <div class="metric-panel annual-fixed">
    <div class="metric-panel-header">
      <div>
        <h2 class="metric-panel-title">ISU — Total do Ano</h2>
        <div class="metric-panel-sub">Janeiro a Dezembro de <?= $year ?> | meses futuros tratados automaticamente</div>
      </div>
      <div class="metric-status <?= td_period_status($yearStart, $yearEnd) === 'Concluído' ? 'done' : '' ?>"><?= td_period_status($yearStart, $yearEnd) ?></div>
    </div>

    <div class="metric-body">
      <div class="metric-gauge-box">
        <canvas id="gaugeIsuYear"></canvas>
        <div class="metric-gauge-value" style="color:var(--purple)"><?= number_format((float)$yearIsu['pct'], 2, ',', '.') ?>%</div>
        <div class="metric-gauge-caption">ISU anual</div>
        <div class="metric-details">
          <div class="metric-mini"><strong><?= number_format((float)$yearIsu['avg'], 2, ',', '.') ?></strong><span>Média de 5</span></div>
          <div class="metric-mini"><strong><?= (int)$yearIsu['total'] ?></strong><span>Respostas</span></div>
        </div>
      </div>
      <div class="metric-line-box">
        <canvas id="chartIsuYear"></canvas>
      </div>
    </div>
  </div>
  </div>

  <div class="dashboard-widget" data-widget-id="visao-geral"><div class="widget-move-actions"><button type="button" onclick="moveWidget(this, -1)">↑</button><button type="button" onclick="moveWidget(this, 1)">↓</button></div>
  <div class="td-section-title">Visão Geral</div>
  <div class="td-grid three">
    <div class="td-chart-card"><h3>CHAMADOS POR STATUS</h3><canvas id="chartStatus"></canvas></div>
    <div class="td-chart-card"><h3>CHAMADOS POR TIPO</h3><canvas id="chartType"></canvas></div>
    <div class="td-chart-card"><h3>Chamados por Prioridade</h3><canvas id="chartPriority"></canvas></div>
  </div>
  </div>

  <div class="dashboard-widget" data-widget-id="conformidade-sla"><div class="widget-move-actions"><button type="button" onclick="moveWidget(this, -1)">↑</button><button type="button" onclick="moveWidget(this, 1)">↓</button></div>
  <div class="td-section-title">Conformidade de SLA</div>
  <div class="td-grid">
    <div class="td-chart-card"><h3>Dentro vs Fora do SLA</h3><canvas id="chartSla"></canvas></div>
  </div>
  </div>

  <div class="dashboard-widget" data-widget-id="equipe-departamento"><div class="widget-move-actions"><button type="button" onclick="moveWidget(this, -1)">↑</button><button type="button" onclick="moveWidget(this, 1)">↓</button></div>
  <div class="td-section-title">Equipe e Departamento</div>
  <div class="td-grid two-wide">
    <div class="td-chart-card"><h3>Grupos</h3><canvas id="chartGroup"></canvas></div>
    <div class="td-chart-card"><h3>Chamados por Departamento/Localização</h3><canvas id="chartDepartment"></canvas></div>
  </div>
  </div>

  <div class="dashboard-widget" data-widget-id="prioridade-tipo"><div class="widget-move-actions"><button type="button" onclick="moveWidget(this, -1)">↑</button><button type="button" onclick="moveWidget(this, 1)">↓</button></div>
  <div class="td-section-title">Prioridade por Tipo</div>
  <div class="td-grid">
    <div class="td-chart-card"><h3>Prioridades em Requisições</h3><canvas id="chartPriorityRequest"></canvas></div>
    <div class="td-chart-card"><h3>Prioridades em Incidentes</h3><canvas id="chartPriorityIncident"></canvas></div>
  </div>
  </div>

  <div class="dashboard-widget" data-widget-id="categorias"><div class="widget-move-actions"><button type="button" onclick="moveWidget(this, -1)">↑</button><button type="button" onclick="moveWidget(this, 1)">↓</button></div>
  <div class="td-section-title">Categorias</div>
  <div class="td-grid full">
    <div class="td-chart-card"><h3>Chamados por Categoria</h3><canvas id="chartCategory"></canvas></div>
  </div>
  </div>

  <div class="dashboard-widget" data-widget-id="evolucao"><div class="widget-move-actions"><button type="button" onclick="moveWidget(this, -1)">↑</button><button type="button" onclick="moveWidget(this, 1)">↓</button></div>
  <div class="td-section-title">Evolução no Período</div>
  <div class="td-grid full">
    <div class="td-chart-card"><h3>Chamados Abertos por Dia</h3><canvas id="chartTimeSeries"></canvas></div>
  </div>
  </div>

  <div class="dashboard-widget" data-widget-id="inventario"><div class="widget-move-actions"><button type="button" onclick="moveWidget(this, -1)">↑</button><button type="button" onclick="moveWidget(this, 1)">↓</button></div>
  <div class="td-section-title">Inventário de Ativos</div>
  <div class="td-inventory">
    <?php foreach ($d['inventory'] ?? [] as $item): ?>
      <div class="td-inv-item">
        <div class="inv-num"><?= (int)$item['total'] ?></div>
        <div class="inv-lbl"><?= htmlspecialchars($item['label']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  </div>

  </div><!-- #dashboard-widgets -->
<div class="audit-footer">
    Relatório exportado por: <?= htmlspecialchars($loggedUserName) ?> |
    ID usuário: <?= (int)$loggedUserId ?> |
    Data/Hora da geração: <?= htmlspecialchars($exportGeneratedAt) ?>
  </div>
</div>

<script>
const D = <?= json_encode($d, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;
const M = <?= json_encode($metricPayload, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;
const AUDIT_USER = <?= json_encode($loggedUserName, JSON_UNESCAPED_UNICODE) ?>;
const AUDIT_USER_ID = <?= (int)$loggedUserId ?>;
const AUDIT_GENERATED_AT = <?= json_encode($exportGeneratedAt, JSON_UNESCAPED_UNICODE) ?>;

const PALETTE = ['#3b82f6','#22c55e','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#ec4899','#84cc16','#f97316','#6366f1'];

// Cores oficiais conforme matriz de prioridade do GLPI
const PRIORITY_COLORS = {
  'Muito baixa': '#1200ff',
  'Baixa': '#00e600',
  'Média': '#ffff00',
  'Media': '#ffff00',
  'Alta': '#ffaa00',
  'Muito alta': '#ff0000',
  'Crítica': '#ff0000',
  'Critica': '#ff0000'
};

function priorityColorByLabel(label) {
  const clean = String(label || '').replace(/\s*\(\d+\)\s*/g, '').trim();

  if (clean.includes('Muito baixa')) return PRIORITY_COLORS['Muito baixa'];
  if (clean.includes('Baixa')) return PRIORITY_COLORS['Baixa'];
  if (clean.includes('Média') || clean.includes('Media')) return PRIORITY_COLORS['Média'];
  if (clean.includes('Muito alta')) return PRIORITY_COLORS['Muito alta'];
  if (clean.includes('Crítica') || clean.includes('Critica')) return PRIORITY_COLORS['Crítica'];
  if (clean.includes('Alta')) return PRIORITY_COLORS['Alta'];

  return '#94a3b8';
}

const LAYOUT_STORAGE_KEY = 'techdashboard_layout_v2';
let layoutEditMode = false;
let draggedWidget = null;
let changesChartsInitialized = false;

function getWidgetContainer() {
  return document.getElementById('dashboard-widgets');
}

function applySavedLayout() {
  const container = getWidgetContainer();
  if (!container) return;
  const saved = localStorage.getItem(LAYOUT_STORAGE_KEY);
  if (!saved) return;
  let order = [];
  try {
    order = JSON.parse(saved);
  } catch (e) {
    return;
  }
  if (!Array.isArray(order)) return;
  order.forEach(id => {
    const widget = container.querySelector(`.dashboard-widget[data-widget-id="${id}"]`);
    if (widget) container.appendChild(widget);
  });
}

function setWidgetsDraggable(enabled) {
  document.querySelectorAll('.dashboard-widget').forEach(widget => {
    widget.setAttribute('draggable', enabled ? 'true' : 'false');
  });
}

function toggleLayoutEdit() {
  const btn = document.getElementById('btnCustomizeLayout');

  if (!layoutEditMode) {
    layoutEditMode = true;
    document.body.classList.add('dashboard-edit-mode');

    if (btn) {
      btn.classList.add('active');
      btn.textContent = 'Salvar visão';
      btn.title = 'Salvar a ordem atual dos quadros';
    }
    return;
  }

  saveLayoutOrder(false);

  layoutEditMode = false;
  document.body.classList.remove('dashboard-edit-mode');

  if (btn) {
    btn.classList.remove('active');
    btn.textContent = 'Editar visão';
    btn.title = 'Editar e salvar a ordem dos quadros';
  }
}


function moveWidget(btn, direction) {
  const widget = btn.closest('.dashboard-widget');
  const container = getWidgetContainer();

  if (!widget || !container) return;

  if (direction < 0) {
    const prev = widget.previousElementSibling;
    if (prev && prev.classList.contains('dashboard-widget')) {
      container.insertBefore(widget, prev);
    }
  } else {
    const next = widget.nextElementSibling;
    if (next && next.classList.contains('dashboard-widget')) {
      container.insertBefore(next, widget);
    }
  }
}

function saveLayoutOrder(showAlert = true) {
  const container = getWidgetContainer();
  if (!container) return;
  const order = Array.from(container.querySelectorAll('.dashboard-widget')).map(w => w.dataset.widgetId);
  localStorage.setItem(LAYOUT_STORAGE_KEY, JSON.stringify(order));
  if (showAlert) {
    alert('Layout salvo para este navegador.');
  }
}

function resetLayoutOrder() {
  localStorage.removeItem(LAYOUT_STORAGE_KEY);
  location.reload();
}

function initDraggableLayout() {
  applySavedLayout();
  setWidgetsDraggable(false);

  document.querySelectorAll('.dashboard-widget').forEach(widget => {
    widget.addEventListener('dragstart', event => {
      if (!layoutEditMode) {
        event.preventDefault();
        return;
      }
      draggedWidget = widget;
      widget.classList.add('dragging');
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', widget.dataset.widgetId || '');
    });

    widget.addEventListener('dragend', () => {
      widget.classList.remove('dragging');
      document.querySelectorAll('.dashboard-widget.drag-over').forEach(el => el.classList.remove('drag-over'));
      draggedWidget = null;
    });

    widget.addEventListener('dragover', event => {
      if (!layoutEditMode || !draggedWidget || draggedWidget === widget) return;
      event.preventDefault();
      widget.classList.add('drag-over');
    });

    widget.addEventListener('dragleave', () => {
      widget.classList.remove('drag-over');
    });

    widget.addEventListener('drop', event => {
      if (!layoutEditMode || !draggedWidget || draggedWidget === widget) return;
      event.preventDefault();
      widget.classList.remove('drag-over');

      const container = getWidgetContainer();
      const rect = widget.getBoundingClientRect();
      const insertAfter = event.clientY > rect.top + rect.height / 2;
      if (insertAfter) {
        container.insertBefore(draggedWidget, widget.nextSibling);
      } else {
        container.insertBefore(draggedWidget, widget);
      }
    });
  });
}

initDraggableLayout();



const doughnutExternalLabelsPlugin = {
  id: 'doughnutExternalLabelsPlugin',
  afterDraw(chart) {
    if (chart.config.type !== 'doughnut') return;

    const enabled = chart.options.plugins?.doughnutExternalLabelsPlugin?.enabled;
    if (!enabled) return;

    const { ctx } = chart;
    const meta = chart.getDatasetMeta(0);
    const data = chart.data.datasets[0].data || [];

    ctx.save();
    ctx.font = '700 12px Segoe UI';
    ctx.fillStyle = '#1e293b';

    meta.data.forEach((arc, index) => {
      const value = Number(data[index] || 0);
      if (value <= 0) return;

      const angle = (arc.startAngle + arc.endAngle) / 2;
      const x = arc.x;
      const y = arc.y;
      const r = arc.outerRadius;

      const labelX = x + Math.cos(angle) * (r + 18);
      const labelY = y + Math.sin(angle) * (r + 18);

      ctx.textAlign = labelX >= x ? 'left' : 'right';
      ctx.textBaseline = 'middle';
      ctx.fillText(String(value), labelX, labelY);
    });

    ctx.restore();
  }
};

const valueLabelsPlugin = {
  id: 'valueLabelsPlugin',
  afterDatasetsDraw(chart) {
    const {ctx} = chart;
    ctx.save();
    ctx.font = '700 11px Segoe UI';
    ctx.fillStyle = '#1e293b';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    chart.data.datasets.forEach((dataset, datasetIndex) => {
      const meta = chart.getDatasetMeta(datasetIndex);
      if (!meta || meta.hidden) return;

      meta.data.forEach((element, index) => {
        const rawValue = dataset.data[index];
        if (rawValue === null || rawValue === undefined || Number.isNaN(rawValue)) return;

        const value = typeof rawValue === 'number'
          ? (Number.isInteger(rawValue) ? rawValue : rawValue.toFixed(1))
          : rawValue;

        const pos = element.tooltipPosition();
        if (chart.config.type === 'bar' && chart.options.indexAxis === 'y') {
          ctx.textAlign = 'left';
          ctx.fillText(String(value), pos.x + 8, pos.y);
        } else if (chart.config.type === 'bar') {
          ctx.textAlign = 'center';
          ctx.fillText(String(value), pos.x, pos.y - 10);
        } else if (chart.config.type === 'line') {
          ctx.textAlign = 'center';
          ctx.fillText(String(value), pos.x, pos.y - 12);
        }
      });
    });

    ctx.restore();
  }
};

const doughnutCenterTextPlugin = {
  id: 'doughnutCenterTextPlugin',
  afterDraw(chart) {
    if (chart.config.type !== 'doughnut') return;

    const cfg = chart.options?.plugins?.doughnutCenterText;
    if (!cfg || !cfg.display) return;

    const {ctx, chartArea} = chart;
    if (!chartArea) return;

    const centerX = (chartArea.left + chartArea.right) / 2;
    const centerY = (chartArea.top + chartArea.bottom) / 2;

    ctx.save();
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    ctx.fillStyle = cfg.color || '#1e293b';
    ctx.font = cfg.font || '800 24px Segoe UI';
    ctx.fillText(cfg.text || '', centerX, centerY - 8);

    if (cfg.subtext) {
      ctx.fillStyle = cfg.subColor || '#64748b';
      ctx.font = cfg.subFont || '700 11px Segoe UI';
      ctx.fillText(cfg.subtext, centerX, centerY + 16);
    }

    ctx.restore();
  }
};

Chart.register(valueLabelsPlugin, doughnutExternalLabelsPlugin);

function labels(arr, key='label') { return (arr || []).map(i => i[key]); }
function values(arr, key='total') { return (arr || []).map(i => i[key]); }
function labelsWithValues(arr, labelKey='label', valueKey='total') {
  return (arr || []).map(i => `${i[labelKey]} (${i[valueKey] ?? 0})`);
}
function sumValues(arr, key='total') {
  return (arr || []).reduce((acc, i) => acc + Number(i[key] || 0), 0);
}

function futureAwareValues(arr) {
  return (arr || []).map(i => i.value === null ? null : Number(i.value || 0));
}

function futureAwareLabels(arr) {
  return (arr || []).map(i => i.status === 'Futuro' ? `${i.label} — futuro` : i.label);
}

function metaText(arr) {
  return (arr || []).map(i => i.status || '');
}

const defaults = {
  responsive: true,
  maintainAspectRatio: true,
  layout: { padding: { top: 24, right: 28, left: 10, bottom: 8 } },
  plugins: {
    legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
    valueLabelsPlugin: true
  }
};

function makeGauge(id, value, color) {
  const safeValue = Math.max(0, Math.min(100, Number(value || 0)));
  new Chart(document.getElementById(id), {
    type: 'doughnut',
    data: {
      datasets: [{
        data: [safeValue, 100 - safeValue],
        backgroundColor: [color, '#e5e7eb'],
        borderWidth: 0,
        circumference: 180,
        rotation: 270
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      cutout: '78%',
      plugins: {
        legend: { display: false },
        tooltip: { enabled: false },
        valueLabelsPlugin: false
      }
    }
  });
}

function makeMonthlyLine(id, monthly, color, label) {
  new Chart(document.getElementById(id), {
    type: 'line',
    data: {
      labels: futureAwareLabels(monthly),
      datasets: [{
        label,
        data: futureAwareValues(monthly),
        borderColor: color,
        backgroundColor: color,
        tension: 0.25,
        pointRadius: 5,
        spanGaps: false
      }]
    },
    options: {
      ...defaults,
      maintainAspectRatio: false,
      plugins: {
        ...defaults.plugins,
        legend: { display: false },
        tooltip: {
          callbacks: {
            afterLabel(ctx) {
              const item = monthly[ctx.dataIndex] || {};
              if (item.status === 'Futuro') return 'Mês ainda não alcançado';
              if (item.status === 'Sem dados') return 'Sem dados no mês';
              return item.status || '';
            }
          }
        }
      },
      scales: {
        y: { min: 0, max: 100, ticks: { callback: v => v + '%' } },
        x: { grid: { display: false } }
      }
    }
  });
}

function barChart(id, arr, color, horizontal=true, key='total', usePriorityColors=false) {
  const backgroundColors = usePriorityColors
    ? labels(arr).map(priorityColorByLabel)
    : color;

  new Chart(document.getElementById(id), {
    type: 'bar',
    data: {
      labels: labels(arr),
      datasets: [{
        label: 'Total',
        data: values(arr, key),
        backgroundColor: backgroundColors,
        borderRadius: 6
      }]
    },
    options: {
      ...defaults,
      indexAxis: horizontal ? 'y' : 'x',
      plugins: { ...defaults.plugins, legend: { display: false } },
      scales: {
        x: { beginAtZero: true, grid: { display: false } },
        y: { beginAtZero: true, grid: { display: false }, ticks: { font: { size: 11 } } }
      }
    }
  });
}

makeGauge('gaugeSlaQuarter', M.quarter.sla.pct, '#3b82f6');
makeGauge('gaugeIsuQuarter', M.quarter.isu.pct, '#8b5cf6');
makeGauge('gaugeSlaYear', M.year.sla.pct, '#3b82f6');
makeGauge('gaugeIsuYear', M.year.isu.pct, '#8b5cf6');

makeMonthlyLine('chartSlaQuarter', M.quarter.monthlySla, '#3b82f6', 'SLA');
makeMonthlyLine('chartIsuQuarter', M.quarter.monthlyIsu, '#8b5cf6', 'ISU');
makeMonthlyLine('chartSlaYear', M.year.monthlySla, '#3b82f6', 'SLA anual');
makeMonthlyLine('chartIsuYear', M.year.monthlyIsu, '#8b5cf6', 'ISU anual');

new Chart(document.getElementById('chartStatus'), {
  type: 'doughnut',
  data: {
    labels: labelsWithValues(D.status),
    datasets: [{ data: values(D.status), backgroundColor: PALETTE, borderWidth: 2 }]
  },
  options: {
    ...defaults,
    cutout: '58%',
    maintainAspectRatio: false,
    layout: { padding: { top: 28, right: 80, bottom: 28, left: 55 } },
    plugins: {
      ...defaults.plugins,
      valueLabelsPlugin: false,
      doughnutExternalLabelsPlugin: { enabled: true },
      legend: {
        display: true,
        position: 'right',
        align: 'center',
        labels: {
          boxWidth: 12,
          padding: 12,
          font: { size: 11 }
        }
      }
    }
  }
});

new Chart(document.getElementById('chartType'), {
  type: 'doughnut',
  data: {
    labels: labelsWithValues(D.type),
    datasets: [{ data: values(D.type), backgroundColor: ['#3b82f6','#f59e0b'], borderWidth: 2 }]
  },
  options: {
    ...defaults,
    cutout: '58%',
    maintainAspectRatio: false,
    layout: { padding: { top: 28, right: 80, bottom: 28, left: 55 } },
    plugins: {
      ...defaults.plugins,
      valueLabelsPlugin: false,
      doughnutExternalLabelsPlugin: { enabled: true },
      legend: {
        display: true,
        position: 'right',
        align: 'center',
        labels: {
          boxWidth: 12,
          padding: 12,
          font: { size: 11 }
        }
      }
    }
  }
});

barChart('chartPriority', D.priority, PALETTE, true, 'total', true);
new Chart(document.getElementById('chartSla'), {
  type: 'doughnut',
  data: {
    labels: labelsWithValues(D.sla),
    datasets: [{ data: values(D.sla), backgroundColor: ['#22c55e','#ef4444'], borderWidth: 2 }]
  },
  options: {
    ...defaults,
    cutout: '58%',
    maintainAspectRatio: false,
    layout: { padding: { top: 28, right: 80, bottom: 28, left: 55 } },
    plugins: {
      ...defaults.plugins,
      valueLabelsPlugin: false,
      doughnutExternalLabelsPlugin: { enabled: true },
      legend: {
        display: true,
        position: 'right',
        align: 'center',
        labels: {
          boxWidth: 12,
          padding: 12,
          font: { size: 11 }
        }
      }
    }
  }
});
barChart('chartGroup', D.group, '#8b5cf6', true);
barChart('chartDepartment', D.department, '#06b6d4', true);
barChart('chartPriorityRequest', D.priorityRequest, PALETTE, true, 'total', true);
barChart('chartPriorityIncident', D.priorityIncident, PALETTE, true, 'total', true);
barChart('chartCategory', D.category, PALETTE, true);

new Chart(document.getElementById('chartTimeSeries'), {
  type: 'line',
  data: {
    labels: (D.timeseries || []).map(i => i.dia),
    datasets: [
      {
        label: 'Total',
        data: (D.timeseries || []).map(i => i.total),
        borderColor: '#3b82f6',
        backgroundColor: 'rgba(59,130,246,.12)',
        fill: true,
        tension: 0.35,
        pointRadius: 3,
        pointHoverRadius: 5,
        borderWidth: 3
      },
      {
        label: 'Requisições',
        data: (D.timeseries || []).map(i => i.requisicoes),
        borderColor: '#22c55e',
        backgroundColor: 'rgba(34,197,94,.10)',
        fill: false,
        tension: 0.35,
        pointRadius: 3,
        pointHoverRadius: 5,
        borderWidth: 3
      },
      {
        label: 'Incidentes',
        data: (D.timeseries || []).map(i => i.incidentes),
        borderColor: '#f59e0b',
        backgroundColor: 'rgba(245,158,11,.10)',
        fill: false,
        tension: 0.35,
        pointRadius: 3,
        pointHoverRadius: 5,
        borderWidth: 3
      }
    ]
  },
  options: {
    ...defaults,
    maintainAspectRatio: false,
    layout: {
      padding: {
        top: 28,
        right: 28,
        bottom: 18,
        left: 10
      }
    },
    plugins: {
      ...defaults.plugins,
      legend: {
        display: true,
        position: 'bottom',
        labels: {
          boxWidth: 28,
          padding: 22,
          font: { size: 13, weight: '700' }
        }
      },
      valueLabelsPlugin: true
    },
    scales: {
      x: {
        grid: { display: false },
        ticks: { maxTicksLimit: 12, font: { size: 10 } }
      },
      y: {
        beginAtZero: true,
        grid: { color: '#f1f5f9' }
      }
    }
  }
});

function toggleCustom(val) {
  document.getElementById('td-custom-range').classList.toggle('show', val === 'custom');
}

function toggleExportMenu() {
  const menu = document.getElementById('export-menu');
  if (menu) {
    menu.classList.toggle('show');
  }
}

document.addEventListener('click', function(e) {
  const wrap = document.querySelector('.export-wrap');
  const menu = document.getElementById('export-menu');

  if (wrap && menu && !wrap.contains(e.target)) {
    menu.classList.remove('show');
  }
});

function exportarPDF() {
  const menu = document.getElementById('export-menu');
  if (menu) {
    menu.classList.remove('show');
  }

  document.body.classList.add('exporting-report');

  setTimeout(() => {
    window.print();

    setTimeout(() => {
      document.body.classList.remove('exporting-report');
    }, 1000);
  }, 200);
}

function limparValorExcel(valor) {
  return String(valor ?? '')
    .replace(/\s+/g, ' ')
    .replace(/"/g, '""')
    .trim();
}

function adicionarSecaoExcel(linhas, titulo, dados, campos) {
  linhas.push([]);
  linhas.push([titulo]);
  linhas.push(campos.map(c => c.titulo));

  (dados || []).forEach(item => {
    linhas.push(campos.map(c => item[c.campo] ?? ''));
  });
}

function exportarExcel() {
  const menu = document.getElementById('export-menu');
  if (menu) {
    menu.classList.remove('show');
  }

  const linhas = [];

  linhas.push(['Relatório', 'Dashboard']);
  linhas.push(['Ano', '<?= (int)$year ?>']);
  linhas.push(['Período', '<?= htmlspecialchars($periodLabel, ENT_QUOTES) ?>']);
  linhas.push(['Data inicial', '<?= date('d/m/Y', strtotime($periodStart)) ?>']);
  linhas.push(['Data final', '<?= date('d/m/Y', strtotime($periodEnd)) ?>']);
  linhas.push(['Exportado em', new Date().toLocaleString('pt-BR')]);

  linhas.push([]);
  linhas.push(['KPIs do Período']);
  linhas.push(['Indicador', 'Valor']);
  document.querySelectorAll('.td-kpi').forEach(card => {
    const indicador = card.querySelector('label')?.innerText || '';
    const valor = card.querySelector('span')?.innerText || '';
    linhas.push([indicador, valor]);
  });

  linhas.push([]);
  linhas.push(['Métricas do Quarter Selecionado']);
  linhas.push(['Métrica', 'Percentual', 'Numerador/Média', 'Total/Respostas', 'Status']);
  linhas.push(['SLA', M.quarter.sla.pct + '%', M.quarter.sla.inside, M.quarter.sla.total, M.quarter.status]);
  linhas.push(['ISU', M.quarter.isu.pct + '%', M.quarter.isu.avg, M.quarter.isu.total, M.quarter.status]);

  linhas.push([]);
  linhas.push(['Métricas Anuais']);
  linhas.push(['Métrica', 'Percentual', 'Numerador/Média', 'Total/Respostas', 'Status']);
  linhas.push(['SLA Anual', M.year.sla.pct + '%', M.year.sla.inside, M.year.sla.total, M.year.status]);
  linhas.push(['ISU Anual', M.year.isu.pct + '%', M.year.isu.avg, M.year.isu.total, M.year.status]);

  adicionarSecaoExcel(linhas, 'SLA Mensal do Quarter', M.quarter.monthlySla, [
    {titulo: 'Mês', campo: 'label'},
    {titulo: 'Valor %', campo: 'value'},
    {titulo: 'Status', campo: 'status'},
    {titulo: 'Dentro do SLA', campo: 'inside'},
    {titulo: 'Total com SLA', campo: 'total'}
  ]);

  adicionarSecaoExcel(linhas, 'ISU Mensal do Quarter', M.quarter.monthlyIsu, [
    {titulo: 'Mês', campo: 'label'},
    {titulo: 'Valor %', campo: 'value'},
    {titulo: 'Status', campo: 'status'},
    {titulo: 'Média', campo: 'avg'},
    {titulo: 'Respostas', campo: 'total'}
  ]);

  adicionarSecaoExcel(linhas, 'SLA Mensal do Ano', M.year.monthlySla, [
    {titulo: 'Mês', campo: 'label'},
    {titulo: 'Valor %', campo: 'value'},
    {titulo: 'Status', campo: 'status'},
    {titulo: 'Dentro do SLA', campo: 'inside'},
    {titulo: 'Total com SLA', campo: 'total'}
  ]);

  adicionarSecaoExcel(linhas, 'ISU Mensal do Ano', M.year.monthlyIsu, [
    {titulo: 'Mês', campo: 'label'},
    {titulo: 'Valor %', campo: 'value'},
    {titulo: 'Status', campo: 'status'},
    {titulo: 'Média', campo: 'avg'},
    {titulo: 'Respostas', campo: 'total'}
  ]);

  adicionarSecaoExcel(linhas, 'Chamados por Status', D.status, [
    {titulo: 'Status', campo: 'label'},
    {titulo: 'Total', campo: 'total'}
  ]);

  adicionarSecaoExcel(linhas, 'Chamados por Tipo', D.type, [
    {titulo: 'Tipo', campo: 'label'},
    {titulo: 'Total', campo: 'total'}
  ]);

  adicionarSecaoExcel(linhas, 'Chamados por Prioridade', D.priority, [
    {titulo: 'Prioridade', campo: 'label'},
    {titulo: 'Total', campo: 'total'}
  ]);

  adicionarSecaoExcel(linhas, 'SLA — Dentro x Fora', D.sla, [
    {titulo: 'Indicador', campo: 'label'},
    {titulo: 'Total', campo: 'total'}
  ]);

  adicionarSecaoExcel(linhas, 'Grupos', D.group, [
    {titulo: 'Grupo', campo: 'label'},
    {titulo: 'Total', campo: 'total'}
  ]);

  adicionarSecaoExcel(linhas, 'Chamados por Departamento/Localização', D.department, [
    {titulo: 'Departamento/Localização', campo: 'label'},
    {titulo: 'Total', campo: 'total'}
  ]);

  adicionarSecaoExcel(linhas, 'Prioridades em Requisições', D.priorityRequest, [
    {titulo: 'Prioridade', campo: 'label'},
    {titulo: 'Total', campo: 'total'}
  ]);

  adicionarSecaoExcel(linhas, 'Prioridades em Incidentes', D.priorityIncident, [
    {titulo: 'Prioridade', campo: 'label'},
    {titulo: 'Total', campo: 'total'}
  ]);

  adicionarSecaoExcel(linhas, 'Categorias Principais', D.category, [
    {titulo: 'Categoria', campo: 'label'},
    {titulo: 'Total', campo: 'total'}
  ]);

  adicionarSecaoExcel(linhas, 'Evolução por Dia', D.timeseries, [
    {titulo: 'Dia', campo: 'dia'},
    {titulo: 'Total', campo: 'total'},
    {titulo: 'Requisições', campo: 'requisicoes'},
    {titulo: 'Incidentes', campo: 'incidentes'}
  ]);

  adicionarSecaoExcel(linhas, 'Inventário de Ativos', D.inventory, [
    {titulo: 'Ativo', campo: 'label'},
    {titulo: 'Total', campo: 'total'}
  ]);

  const csv = linhas
    .map(linha => linha.map(campo => '"' + limparValorExcel(campo) + '"').join(';'))
    .join('\n');

  const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
  const link = document.createElement('a');

  link.href = URL.createObjectURL(blob);
  link.download = <?= json_encode($tdCsvPrefix, JSON_UNESCAPED_UNICODE) ?> + '_<?= (int)$year ?>_<?= preg_replace('/[^a-zA-Z0-9]/', '_', $period) ?>_' + new Date().toISOString().slice(0, 10) + '.csv';

  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);

  URL.revokeObjectURL(link.href);
}


// ===== MODAL OCULTAR USUÁRIOS =====
function openHiddenUsersModal() {
  const modal = document.getElementById('hiddenUsersModal');
  if (modal) {
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
  }
}

function closeHiddenUsersModal() {
  const modal = document.getElementById('hiddenUsersModal');
  if (modal) {
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
  }
}

function filterHiddenUsersList() {
  const input = document.getElementById('userSearchInput');
  const term = (input?.value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');

  document.querySelectorAll('#hiddenUsersList .user-item').forEach(item => {
    const search = (item.dataset.search || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    item.classList.toggle('hidden-by-search', term && !search.includes(term));
  });
}

function checkVisibleUsers(checked) {
  document.querySelectorAll('#hiddenUsersList .user-item:not(.hidden-by-search) .hidden-user-checkbox')
    .forEach(cb => cb.checked = checked);
}

function selectServiceAccounts() {
  document.querySelectorAll('#hiddenUsersList .hidden-user-checkbox').forEach(cb => {
    cb.checked = cb.dataset.serviceAccount === '1';
  });
}

function clearHiddenUsers() {
  document.querySelectorAll('#hiddenUsersList .hidden-user-checkbox').forEach(cb => cb.checked = false);
}

function saveHiddenUsers() {
  const ids = Array.from(document.querySelectorAll('#hiddenUsersList .hidden-user-checkbox:checked'))
    .map(cb => cb.value)
    .filter(Boolean);

  const hiddenInput = document.getElementById('hidden_users');
  if (hiddenInput) {
    hiddenInput.value = ids.join(',');
  }

  localStorage.setItem('techdashboard_hidden_users', ids.join(','));

  const form = document.getElementById('td-form');
  if (form) {
    form.submit();
  }
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeHiddenUsersModal();
  }
});

document.addEventListener('click', function(e) {
  const modal = document.getElementById('hiddenUsersModal');
  if (modal && e.target === modal) {
    closeHiddenUsersModal();
  }
});


// initChangeCharts será chamado ao abrir a aba Mudanças

</script>


<script>
document.addEventListener('DOMContentLoaded', function () {
  function forceOpenTab(tabName) {
    document.querySelectorAll('.td-tab-content').forEach(function (el) {
      el.classList.remove('active');
      el.style.display = 'none';
    });

    document.querySelectorAll('.td-tab-btn').forEach(function (btn) {
      btn.classList.toggle('active', btn.dataset.tab === tabName);
    });

    var tab = document.getElementById('tab-' + tabName);
    if (tab) {
      tab.classList.add('active');
      tab.style.display = 'block';
    }

    localStorage.setItem('techdashboard_active_tab', tabName);
  }

  document.querySelectorAll('.td-tab-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      forceOpenTab(btn.dataset.tab || 'chamados');

      setTimeout(function () {
        if (btn.dataset.tab === 'mudancas' && typeof initChangeCharts === 'function') {
          try { initChangeCharts(); } catch (e) { console.error(e); }
        }

        if (window.Chart && Chart.instances) {
          Object.values(Chart.instances).forEach(function (chart) {
            if (chart && typeof chart.resize === 'function') chart.resize();
          });
        }
      }, 300);
    });
  });

  var saved = localStorage.getItem('techdashboard_active_tab') || 'chamados';
  if (!document.getElementById('tab-' + saved)) saved = 'chamados';
  forceOpenTab(saved);
});
</script>




<?php Html::footer(); ?>


