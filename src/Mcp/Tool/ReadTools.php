<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mcp\Tool;

use FirefightingEquipment\Api\V1\Devices\DeviceFields;

/**
 * Lesewerkzeuge des Claude-Zugangs (docs/MCP-KONZEPT.md, Abschnitt 3, „Lesen“; Schritt M5): je
 * Werkzeug die REST-Routen, die es aufruft, und die kompakte Antwort. Alle mit readOnlyHint.
 */
final class ReadTools
{
    /** Standard für Listen, wenn Claude keine Anzahl angibt. */
    private const int DEFAULT_LIMIT = 25;

    private const array STATUS = ['ready', 'maintenance', 'defective', 'used', 'retired'];

    private const array SORT = [
        'number' => 'identifier',
        'equipment_type' => 'device_type',
        'location' => 'location',
        'status' => 'status',
        'maintenance_due' => 'next_inspection_due',
    ];

    private function __construct()
    {
    }

    /**
     * @return list<ToolDefinition>
     */
    public static function all(): array
    {
        return [
            self::overview(),
            self::listDue(),
            self::search(),
            self::getEquipment(),
            self::history(),
            self::locations(),
            self::types(),
            self::cityHistory(),
            self::listNotices(),
            self::getNotice(),
            self::listLoans(),
            self::listMaintenance(),
        ];
    }

    private static function overview(): ToolDefinition
    {
        return new ToolDefinition(
            'get_overview',
            'Übersicht',
            'Übersicht wie das Dashboard der App: Einsatzbereitschaft (Ausrüstungen je Status), nicht '
            . 'einsatzbereite Ausrüstung (die ersten 10), Termine (überfällig, in den nächsten 30 Tagen '
            . 'fällig, ohne Wartungsdatum) und, wenn die Rolle das Protokoll sieht, offene Hinweise.',
            self::schema([]),
            ['GET api/v1/device-summary', 'GET api/v1/devices'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $summary = $api->get('api/v1/device-summary');
                $byStatus = [];
                foreach (self::list($summary['devices']['by_status'] ?? []) as $row) {
                    $byStatus[(string) ($row['status'] ?? '')] = ['label' => $row['status_label'] ?? null, 'count' => $row['count'] ?? 0];
                }
                $total = (int) ($summary['devices']['total'] ?? 0);
                $ready = (int) ($byStatus['ready']['count'] ?? 0);
                $data = [
                    'today' => $summary['today'] ?? null,
                    'readiness' => ['ready' => $ready, 'total' => $total, 'by_status' => $byStatus],
                    'maintenance' => [
                        'overdue' => $summary['inspection']['overdue'] ?? 0,
                        'due_soon' => $summary['inspection']['due_soon'] ?? 0,
                        'due_soon_days' => $summary['inspection']['due_soon_days'] ?? 30,
                        'without_date' => $summary['inspection']['without_date'] ?? 0,
                    ],
                ];
                if (isset($summary['notices'])) {
                    $data['open_notices'] = $summary['notices']['open'] ?? 0;
                }
                $text = sprintf(
                    'Einsatzbereit: %d von %d Ausrüstungen. Termine: %d überfällig, %d in den nächsten %d Tagen fällig.',
                    $ready,
                    $total,
                    $data['maintenance']['overdue'],
                    $data['maintenance']['due_soon'],
                    $data['maintenance']['due_soon_days'],
                );
                if (isset($data['open_notices'])) {
                    $text .= sprintf(' Offene Hinweise im Protokoll: %d.', $data['open_notices']);
                }
                if ($api->allows('GET api/v1/devices')) {
                    $list = $api->get('api/v1/devices', query: ['status' => 'maintenance,defective,used', 'sort' => 'status', 'dir' => 'desc', 'limit' => 10]);
                    $devices = array_map(ToolFormat::equipment(...), self::list($list['devices'] ?? []));
                    $data['not_ready'] = ['equipment' => $devices, 'total' => $list['total'] ?? count($devices)];
                    $text .= sprintf(' Nicht einsatzbereit: %d (Liste mit search_equipment und status).', $data['not_ready']['total']);
                }

                return ToolResult::ok($text, $data);
            },
        );
    }

    private static function listDue(): ToolDefinition
    {
        return new ToolDefinition(
            'list_due',
            'Termine',
            'Termine wie in der App: Ausrüstung mit überfälligem Wartungsdatum und mit Wartungsdatum in den '
            . 'nächsten N Tagen (Standard 30), am stärksten überfällige zuerst. Ausgesonderte nie.',
            self::schema([
                'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'description' => 'Fenster in Tagen ab heute (Standard 30).'],
                'include_without_date' => ['type' => 'boolean', 'description' => 'Zusätzlich Ausrüstung ohne Wartungsdatum.'],
                'equipment_type_id' => self::id('Nur dieser Ausrüstungstyp (ID aus list_equipment_types).'),
                'location_id' => self::id('Nur dieser Standort samt Fächern (ID aus list_locations).'),
                'limit' => self::limit('Höchstens so viele je Abschnitt'),
            ]),
            ['GET api/v1/inspection-due'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $limit = $args->int('limit') ?? ToolFormat::MAX_LIST;
                $query = ['limit' => $limit];
                if ($args->has('days')) {
                    $query['days'] = (int) $args->int('days');
                }
                if ($args->bool('include_without_date')) {
                    $query['include_without_date'] = true;
                }
                self::filters($args, $query);
                $due = $api->get('api/v1/inspection-due', query: $query);
                $data = ['today' => $due['today'] ?? null, 'days' => $due['days'] ?? null, 'until' => $due['until'] ?? null];
                $parts = [];
                foreach (['overdue' => 'überfällig', 'due_soon' => 'bald fällig', 'without_date' => 'ohne Wartungsdatum'] as $key => $label) {
                    if (!isset($due[$key])) {
                        continue;
                    }
                    $devices = array_map(ToolFormat::equipment(...), self::list($due[$key]['devices'] ?? []));
                    $total = (int) ($due[$key]['total'] ?? count($devices));
                    $data[$key] = ['equipment' => $devices, 'total' => $total, 'shown' => count($devices)];
                    $parts[] = sprintf('%d %s%s', $total, $label, $total > count($devices) ? sprintf(' (angezeigt %d)', count($devices)) : '');
                }

                return ToolResult::ok(sprintf('Termine bis %s: %s.', (string) ($data['until'] ?? '?'), implode(', ', $parts)), $data);
            },
        );
    }

    private static function search(): ToolDefinition
    {
        return new ToolDefinition(
            'search_equipment',
            'Ausrüstung suchen',
            'Ausrüstung suchen und auflisten wie die Ausrüstungsliste der App. Suchtext in Nummer, Scan-Code, '
            . 'Seriennummer, Hersteller und Modell (Typ und Standort über die IDs filtern). Ohne Angabe von '
            . 'status keine ausgesonderte. Höchstens 50 je Aufruf; weitere über offset.',
            self::schema([
                'query' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Suchtext (Teil der Nummer, Seriennummer, Hersteller, Modell oder Scan-Code).'],
                'equipment_type_id' => self::id('Nur dieser Ausrüstungstyp (ID aus list_equipment_types).'),
                'location_id' => self::id('Nur dieser Standort (ID aus list_locations).'),
                'include_compartments' => ['type' => 'boolean', 'description' => 'Bei location_id auch die Fächer des Standorts (Standard true).'],
                'status' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => self::STATUS],
                    'minItems' => 1,
                    'maxItems' => 4,
                    'uniqueItems' => true,
                    'description' => 'Nur diese Status: ready (einsatzbereit), maintenance (in Wartung), defective (defekt), used (benutzt), retired (ausgesondert).',
                ],
                'include_retired' => ['type' => 'boolean', 'description' => 'Ohne status: auch ausgesonderte Ausrüstung.'],
                'sort' => ['type' => 'string', 'enum' => array_keys(self::SORT), 'description' => 'Sortierung (Standard number).'],
                'direction' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'description' => 'Richtung (Standard asc).'],
                'limit' => self::limit('Höchstens so viele'),
                'offset' => self::offset(),
            ]),
            ['GET api/v1/devices'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $limit = $args->int('limit') ?? self::DEFAULT_LIMIT;
                $offset = $args->int('offset') ?? 0;
                $query = ['limit' => $limit, 'offset' => $offset];
                if ($args->string('query') !== null) {
                    $query['q'] = (string) $args->string('query');
                }
                if ($args->has('equipment_type_id')) {
                    $query['device_type_id'] = (int) $args->int('equipment_type_id');
                }
                if ($args->has('location_id')) {
                    $query['location_id'] = (int) $args->int('location_id');
                    if ($args->bool('include_compartments', true)) {
                        $query['include_sublocations'] = true;
                    }
                }
                $status = $args->list('status');
                if ($status !== null) {
                    $query['status'] = implode(',', array_map(strval(...), $status));
                } elseif ($args->bool('include_retired')) {
                    $query['include_retired'] = true;
                }
                if ($args->has('sort')) {
                    $query['sort'] = self::SORT[(string) $args->string('sort')];
                }
                if ($args->string('direction') === 'desc') {
                    $query['dir'] = 'desc';
                }
                $list = $api->get('api/v1/devices', query: $query);
                $devices = array_map(ToolFormat::equipment(...), self::list($list['devices'] ?? []));
                $total = (int) ($list['total'] ?? count($devices));

                return ToolResult::ok(
                    sprintf('%d Ausrüstungen gefunden. %s', $total, ToolFormat::pagingText($total, $offset, count($devices), 'query, equipment_type_id, location_id, status')),
                    ['equipment' => $devices, 'paging' => ToolFormat::paging($total, $offset, count($devices))],
                );
            },
        );
    }

    private static function getEquipment(): ToolDefinition
    {
        return new ToolDefinition(
            'get_equipment',
            'Ausrüstung ansehen',
            'Detailseite einer Ausrüstung: Standort, Status, Wartungsdatum, Stammdaten, Notiz und Version '
            . '(für Änderungen). Genau eines angeben: id, number (Nummer, ohne Groß-/Kleinschreibung) '
            . 'oder scan_code (genau wie gescannt). Auch ausgesonderte über id.',
            self::schema([
                    'id' => self::id('ID der Ausrüstung.'),
                    'number' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 30, 'description' => 'Nummer der Ausrüstung (z. B. M12).'],
                    'scan_code' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => 'Inhalt des Barcodes bzw. QR-Codes.'],
                ]),
            ['GET api/v1/devices/{id}', 'GET api/v1/devices'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $given = array_filter(['id', 'number', 'scan_code'], $args->has(...));
                if (count($given) !== 1) {
                    throw ToolFailure::invalidArguments('Bitte genau eines angeben: id, number oder scan_code.');
                }
                $id = $args->int('id') ?? self::findId($args, $api);
                $device = $api->get('api/v1/devices/{id}', ['id' => $id])['device'] ?? [];
                $item = ToolFormat::equipmentDetail(is_array($device) ? $device : []);

                return ToolResult::ok(
                    sprintf(
                        '%s (%s): %s, %s. Wartungsdatum: %s. Version %s.',
                        (string) $item['number'],
                        (string) $item['equipment_type'],
                        (string) $item['status_label'],
                        (string) $item['location'],
                        (string) ($item['maintenance_due'] ?? 'keins'),
                        (string) $item['version'],
                    ),
                    ['equipment' => $item],
                    ['equipment.note', 'equipment.serial_number', 'equipment.manufacturer', 'equipment.model'],
                );
            },
        );
    }

    private static function history(): ToolDefinition
    {
        return new ToolDefinition(
            'get_equipment_history',
            'Verlauf einer Ausrüstung',
            'Verlauf einer Ausrüstung wie in der App (neueste zuerst): wer wann umgebucht, den Status, das '
            . 'Wartungsdatum oder Stammdaten geändert hat, mit Kommentar. Nicht übernommene Offline-Buchungen '
            . 'mit Grund.',
            self::schema([
                'id' => self::id('ID der Ausrüstung (aus search_equipment oder get_equipment).'),
                'limit' => self::limit('Höchstens so viele Einträge'),
                'offset' => self::offset(),
            ], ['id']),
            ['GET api/v1/devices/{id}/events'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $offset = $args->int('offset') ?? 0;
                $page = $api->get('api/v1/devices/{id}/events', ['id' => (int) $args->int('id')], [
                    'limit' => $args->int('limit') ?? self::DEFAULT_LIMIT,
                    'offset' => $offset,
                ]);
                $events = array_map(ToolFormat::event(...), self::list($page['events'] ?? []));
                $total = (int) ($page['total'] ?? count($events));

                return ToolResult::ok(
                    sprintf('Verlauf: %s%s', ToolFormat::pagingText($total, $offset, count($events), 'offset'), ToolFormat::viaClaudeText($events)),
                    ['events' => $events, 'paging' => ToolFormat::paging($total, $offset, count($events))],
                    ['events[].user', 'events[].comment', 'events[].changes'],
                );
            },
        );
    }

    private static function locations(): ToolDefinition
    {
        return new ToolDefinition(
            'list_locations',
            'Standorte',
            'Standorte (Fahrzeuge, Räume, Extern) mit ihren Fächern und der Zahl der Ausrüstungen. IDs für '
            . 'die Filter anderer Werkzeuge. Höchstens 50 Standorte der obersten Ebene je Aufruf.',
            self::schema([
                'include_locked' => ['type' => 'boolean', 'description' => 'Auch gesperrte Standorte und Fächer.'],
                'offset' => self::offset(),
            ]),
            ['GET api/v1/locations'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $offset = $args->int('offset') ?? 0;
                $all = self::list($api->get('api/v1/locations', query: $args->bool('include_locked') ? ['include_inactive' => true] : [])['locations'] ?? []);
                $shown = array_slice($all, $offset, ToolFormat::MAX_LIST);
                $locations = array_map(static fn (array $l): array => [
                    'id' => $l['id'] ?? null,
                    'name' => $l['name'] ?? null,
                    'kind' => $l['kind_label'] ?? null,
                    'active' => $l['active'] ?? null,
                    'selectable' => $l['selectable'] ?? true,
                    'equipment_count' => $l['device_count'] ?? null,
                    'equipment_count_total' => $l['device_count_total'] ?? null,
                    'compartments' => array_map(static fn (array $c): array => [
                        'id' => $c['id'] ?? null,
                        'name' => $c['name'] ?? null,
                        'active' => $c['active'] ?? null,
                        'equipment_count' => $c['device_count'] ?? null,
                    ], self::list($l['children'] ?? [])),
                ], $shown);

                return ToolResult::ok(
                    sprintf('Standorte: %s', ToolFormat::pagingText(count($all), $offset, count($shown), 'offset')),
                    ['locations' => $locations, 'paging' => ToolFormat::paging(count($all), $offset, count($shown))],
                    ['locations[].name', 'locations[].compartments[].name'],
                );
            },
        );
    }

    private static function types(): ToolDefinition
    {
        return new ToolDefinition(
            'list_equipment_types',
            'Ausrüstungstypen',
            'Ausrüstungstypen (z. B. Atemschutzmaske, Funkgerät) mit der Zahl der Ausrüstungen. IDs für die '
            . 'Filter anderer Werkzeuge. Höchstens 50 je Aufruf.',
            self::schema([
                'include_inactive' => ['type' => 'boolean', 'description' => 'Auch deaktivierte Ausrüstungstypen.'],
                'offset' => self::offset(),
            ]),
            ['GET api/v1/device-types'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $offset = $args->int('offset') ?? 0;
                $all = self::list($api->get('api/v1/device-types', query: $args->bool('include_inactive') ? ['include_inactive' => true] : [])['device_types'] ?? []);
                $shown = array_slice($all, $offset, ToolFormat::MAX_LIST);
                $types = array_map(static fn (array $t): array => [
                    'id' => $t['id'] ?? null,
                    'name' => $t['name'] ?? null,
                    'active' => $t['active'] ?? null,
                    'equipment_count' => $t['device_count'] ?? null,
                ], $shown);

                return ToolResult::ok(
                    sprintf('Ausrüstungstypen: %s', ToolFormat::pagingText(count($all), $offset, count($shown), 'offset')),
                    ['equipment_types' => $types, 'paging' => ToolFormat::paging(count($all), $offset, count($shown))],
                    ['equipment_types[].name'],
                );
            },
        );
    }

    private static function cityHistory(): ToolDefinition
    {
        return new ToolDefinition(
            'list_city_history',
            'Letzte Aktivitäten',
            'Verlauf der ganzen Stadt (letzte Aktivitäten wie im Dashboard), neueste zuerst: Ausrüstung, '
            . 'Vorgang, wer, wann, Änderungen und Kommentar. Ältere über offset.',
            self::schema([
                'limit' => self::limit('Höchstens so viele Einträge'),
                'offset' => self::offset(),
            ]),
            ['GET api/v1/device-events'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $offset = $args->int('offset') ?? 0;
                $page = $api->get('api/v1/device-events', query: ['limit' => $args->int('limit') ?? self::DEFAULT_LIMIT, 'offset' => $offset]);
                $events = array_map(ToolFormat::event(...), self::list($page['events'] ?? []));
                $total = (int) ($page['total'] ?? count($events));

                return ToolResult::ok(
                    sprintf('Letzte Aktivitäten: %s%s', ToolFormat::pagingText($total, $offset, count($events), 'offset'), ToolFormat::viaClaudeText($events)),
                    ['events' => $events, 'paging' => ToolFormat::paging($total, $offset, count($events))],
                    ['events[].user', 'events[].comment', 'events[].changes'],
                );
            },
        );
    }

    private static function listNotices(): ToolDefinition
    {
        return new ToolDefinition(
            'list_notices',
            'Hinweise des Protokolls',
            'Hinweise des Protokolls (z. B. überschriebene oder nicht übernommene Offline-Buchungen), neueste '
            . 'zuerst. Standard: offene.',
            self::schema([
                'status' => ['type' => 'string', 'enum' => ['open', 'resolved', 'all'], 'description' => 'open (offen, Standard), resolved (erledigt) oder all.'],
                'limit' => self::limit('Höchstens so viele'),
                'offset' => self::offset(),
            ]),
            ['GET api/v1/notices'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $offset = $args->int('offset') ?? 0;
                $page = $api->get('api/v1/notices', query: [
                    'status' => $args->string('status') ?? 'open',
                    'limit' => $args->int('limit') ?? self::DEFAULT_LIMIT,
                    'offset' => $offset,
                ]);
                $notices = array_map(self::notice(...), self::list($page['notices'] ?? []));
                $total = (int) ($page['total'] ?? count($notices));

                return ToolResult::ok(
                    sprintf('%d offene Hinweise insgesamt. %s', (int) ($page['open'] ?? 0), ToolFormat::pagingText($total, $offset, count($notices), 'status')),
                    ['notices' => $notices, 'open' => $page['open'] ?? null, 'paging' => ToolFormat::paging($total, $offset, count($notices))],
                    ['notices[].booking.user', 'notices[].booking.comment', 'notices[].other.user'],
                );
            },
        );
    }

    private static function getNotice(): ToolDefinition
    {
        return new ToolDefinition(
            'get_notice',
            'Hinweis ansehen',
            'Ein Hinweis des Protokolls mit Buchung, dem anderen Stand und dem Verlauf (erledigt, wieder geöffnet).',
            self::schema(['id' => self::id('ID des Hinweises (aus list_notices).')], ['id']),
            ['GET api/v1/notices/{id}'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $notice = $api->get('api/v1/notices/{id}', ['id' => (int) $args->int('id')])['notice'] ?? [];
                $item = self::notice(is_array($notice) ? $notice : []);
                $item['history'] = array_map(static fn (array $h): array => [
                    'action' => $h['action_label'] ?? null,
                    'user' => is_array($h['user'] ?? null) ? ($h['user']['name'] ?? null) : null,
                    'at' => ToolFormat::time($h['at'] ?? null),
                    'note' => $h['note'] ?? null,
                    ...(($h['channel'] ?? null) === 'claude' ? ['via_claude' => true] : []),
                ], self::list(is_array($notice) ? ($notice['history'] ?? []) : []));

                return ToolResult::ok(
                    sprintf('Hinweis %s: %s (%s).', (string) $item['id'], (string) $item['kind'], (string) $item['status_label']),
                    ['notice' => $item],
                    ['notice.booking.user', 'notice.booking.comment', 'notice.other.user', 'notice.history[].user', 'notice.history[].note'],
                );
            },
        );
    }

    private static function listLoans(): ToolDefinition
    {
        return new ToolDefinition(
            'list_loans',
            'Verleih',
            'Verliehene Ausrüstung wie die Seite „Verleih“: offene Verleihe nach Leihnehmer und „Rückgabe bis“ '
            . '(überfällige gekennzeichnet), dazu die möglichen Leihnehmer (borrowers) und die Ausrüstung, die in '
            . 'der Rückgabe liegt (in_returns). Die Seite gibt es nur in Städten mit freigeschalteter Aufgabe.',
            self::schema([
                'limit' => self::limit('Höchstens so viele Verleihe'),
                'offset' => self::offset(),
            ]),
            ['GET api/v1/loans'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $limit = $args->int('limit') ?? self::DEFAULT_LIMIT;
                $offset = $args->int('offset') ?? 0;
                $data = $api->get('api/v1/loans');
                $all = self::list($data['loans'] ?? []);
                $loans = [];
                foreach (array_slice($all, $offset, $limit) as $loan) {
                    $loans[] = [
                        'id' => $loan['id'] ?? null,
                        'equipment' => ToolFormat::equipment(is_array($loan['device'] ?? null) ? $loan['device'] : []),
                        'borrower' => ToolFormat::name($loan['borrower'] ?? null),
                        'borrower_id' => is_array($loan['borrower'] ?? null) ? ($loan['borrower']['id'] ?? null) : null,
                        'contact' => $loan['contact'] ?? null,
                        'return_by' => $loan['due_back_on'] ?? null,
                        'overdue' => $loan['overdue'] ?? false,
                        'days_overdue' => $loan['days_overdue'] ?? 0,
                        'comment' => $loan['comment'] ?? null,
                        'lent_at' => ToolFormat::time($loan['lent_at'] ?? null),
                        'lent_by' => ToolFormat::name($loan['lent_by'] ?? null),
                    ];
                }
                $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
                $total = count($all);
                $returns = array_map(ToolFormat::equipment(...), self::list($data['in_returns'] ?? []));

                return ToolResult::ok(
                    sprintf(
                        '%d offene Verleihe, davon %d überfällig; %d Ausrüstungen in der Rückgabe. %s',
                        (int) ($summary['open'] ?? $total),
                        (int) ($summary['overdue'] ?? 0),
                        count($returns),
                        ToolFormat::pagingText($total, $offset, count($loans), 'offset'),
                    ),
                    [
                        'loans' => $loans,
                        'summary' => ['open' => $summary['open'] ?? null, 'overdue' => $summary['overdue'] ?? null],
                        'paging' => ToolFormat::paging($total, $offset, count($loans)),
                        'borrowers' => array_map(static fn (array $b): array => ['id' => $b['id'] ?? null, 'name' => $b['name'] ?? null], self::list($data['borrowers'] ?? [])),
                        'in_returns' => array_slice($returns, 0, ToolFormat::MAX_LIST),
                    ],
                    ['loans[].contact', 'loans[].comment', 'loans[].borrower', 'loans[].lent_by', 'borrowers[].name'],
                );
            },
        );
    }

    private static function listMaintenance(): ToolDefinition
    {
        return new ToolDefinition(
            'list_maintenance',
            'Wartung',
            'Die Seite „Wartung“: Ausrüstung in der Sammelstelle (wartet auf die Werkstatt) und in der Werkstatt, '
            . 'die am längsten wartende zuerst, mit dem Zeitpunkt der letzten Änderung (waiting_since). Dazu das '
            . 'Standard-Lager. Die Seite gibt es nur in Städten mit freigeschalteter Aufgabe.',
            self::schema([
                'limit' => self::limit('Höchstens so viele je Stufe'),
                'offset' => self::offset(),
            ]),
            ['GET api/v1/maintenance'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $limit = $args->int('limit') ?? self::DEFAULT_LIMIT;
                $offset = $args->int('offset') ?? 0;
                $data = $api->get('api/v1/maintenance');
                $out = [];
                $parts = [];
                foreach (['collection' => 'Sammelstelle', 'workshop' => 'Werkstatt'] as $key => $fallback) {
                    $stage = is_array($data[$key] ?? null) ? $data[$key] : [];
                    $devices = self::list($stage['devices'] ?? []);
                    $shown = array_map(static fn (array $d): array => [...ToolFormat::equipment($d), 'waiting_since' => ToolFormat::time($d['state_changed_at'] ?? null)], array_slice($devices, $offset, $limit));
                    $total = (int) ($stage['total'] ?? count($devices));
                    $label = is_string($stage['label'] ?? null) ? $stage['label'] : $fallback;
                    $out[$key] = [
                        'label' => $label,
                        'locations' => array_map(static fn (array $l): array => ['id' => $l['id'] ?? null, 'name' => $l['name'] ?? null], self::list($stage['locations'] ?? [])),
                        'equipment' => $shown,
                        'paging' => ToolFormat::paging($total, $offset, count($shown)),
                    ];
                    $parts[] = sprintf('%s: %d', $label, $total);
                }
                $store = is_array($data['default_store'] ?? null) ? $data['default_store'] : null;
                $out['default_store'] = $store === null ? null : ['id' => $store['id'] ?? null, 'name' => $store['name'] ?? null];

                return ToolResult::ok(
                    sprintf('Wartung – %s. Weitere mit offset (höchstens %d je Stufe).', implode(', ', $parts), ToolFormat::MAX_LIST),
                    $out,
                );
            },
        );
    }

    /**
     * Hinweis kompakt (NoticeJson::notice); E-Mail-Adressen nicht (Claude braucht sie nicht).
     *
     * @param array<array-key, mixed> $notice
     *
     * @return array<string, mixed>
     */
    private static function notice(array $notice): array
    {
        $booking = is_array($notice['booking'] ?? null) ? $notice['booking'] : [];
        $other = is_array($notice['other'] ?? null) ? $notice['other'] : [];
        $device = is_array($notice['device'] ?? null) ? $notice['device'] : [];

        return [
            'id' => $notice['id'] ?? null,
            'kind' => $notice['kind_label'] ?? null,
            'status' => $notice['status'] ?? null,
            'status_label' => $notice['status_label'] ?? null,
            'stale_confirmed' => $notice['stale_confirmed'] ?? false,
            'detail' => is_string($notice['detail'] ?? null) ? $notice['detail'] : null,
            'detail_label' => is_string($notice['detail_label'] ?? null) ? $notice['detail_label'] : null,
            'equipment' => ['id' => $device['id'] ?? null, 'number' => $device['identifier'] ?? null, 'equipment_type' => $device['device_type'] ?? null],
            'booking' => [
                'user' => is_array($booking['user'] ?? null) ? ($booking['user']['name'] ?? null) : null,
                'at' => ToolFormat::time($booking['occurred_at'] ?? null),
                'received_at' => ToolFormat::time($booking['received_at'] ?? null),
                'location' => ToolFormat::path($booking['location'] ?? null),
                'status' => $booking['status_label'] ?? null,
                'comment' => $booking['comment'] ?? null,
            ],
            'other' => [
                'user' => is_array($other['user'] ?? null) ? ($other['user']['name'] ?? null) : null,
                'at' => ToolFormat::time($other['occurred_at'] ?? null),
                'location' => ToolFormat::path($other['location'] ?? null),
                'status' => $other['status_label'] ?? null,
            ],
        ];
    }

    /**
     * ID zu Nummer bzw. Scan-Code über GET devices (die Nummer genau ohne Groß-/Kleinschreibung).
     *
     * @throws ToolFailure not_found
     */
    private static function findId(ToolArguments $args, ToolApi $api): int
    {
        $scanCode = $args->string('scan_code');
        if ($scanCode !== null) {
            $devices = self::list($api->get('api/v1/devices', query: ['scan_code' => $scanCode, 'limit' => 1])['devices'] ?? []);
            if ($devices === []) {
                throw ToolFailure::notFound('Keine Ausrüstung mit diesem Scan-Code gefunden.');
            }

            return (int) ($devices[0]['id'] ?? 0);
        }
        $number = trim((string) $args->string('number'));
        $key = self::numberKey($number);
        foreach (self::list($api->get('api/v1/devices', query: ['q' => $number, 'limit' => 200])['devices'] ?? []) as $device) {
            if (is_string($device['identifier'] ?? null) && self::numberKey($device['identifier']) === $key) {
                return (int) ($device['id'] ?? 0);
            }
        }

        throw ToolFailure::notFound(sprintf('Keine Ausrüstung mit der Nummer „%s“ gefunden (ausgesonderte nur über id).', $number));
    }

    /** Vergleichsform der Nummer (wie identifier_key: klein, ẞ = ß, Leerzeichen zusammengefasst). */
    private static function numberKey(string $number): string
    {
        return str_replace('ẞ', 'ß', mb_strtolower((string) preg_replace('/ +/', ' ', trim($number))));
    }

    /**
     * Filter Ausrüstungstyp und Standort (immer mit Fächern) für GET-Parameter.
     *
     * @param array<string, scalar> $query
     */
    private static function filters(ToolArguments $args, array &$query): void
    {
        if ($args->has('equipment_type_id')) {
            $query['device_type_id'] = (int) $args->int('equipment_type_id');
        }
        if ($args->has('location_id')) {
            $query['location_id'] = (int) $args->int('location_id');
            $query['include_sublocations'] = true;
        }
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string>                        $required
     *
     * @return array<string, mixed>
     */
    public static function schema(array $properties, array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    public static function id(string $description): array
    {
        return ['type' => 'integer', 'minimum' => 1, 'maximum' => DeviceFields::MAX_ID, 'description' => $description];
    }

    /**
     * @return array<string, mixed>
     */
    private static function limit(string $what): array
    {
        return ['type' => 'integer', 'minimum' => 1, 'maximum' => ToolFormat::MAX_LIST, 'description' => sprintf('%s (1–%d).', $what, ToolFormat::MAX_LIST)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function offset(): array
    {
        return ['type' => 'integer', 'minimum' => 0, 'maximum' => 1_000_000, 'description' => 'Ab diesem Eintrag (für weitere Seiten, Standard 0).'];
    }
}
