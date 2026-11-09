<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mcp\Tool;

use FirefightingEquipment\Api\V1\Devices\DeviceBulkHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceFields;
use FirefightingEquipment\Permission\Action;
use FirefightingEquipment\Permission\Requirement;

/**
 * Schreibwerkzeuge des Claude-Zugangs (docs/MCP-KONZEPT.md, Abschnitt 3, „Ändern“; Schritt M6):
 * nur mit Zugang „Ändern“ und nur, was die Rolle darf (die Routen prüfen ihr Erfordernis samt
 * Feldregeln). Ändernde Werkzeuge an einer Ausrüstung verlangen die Version aus dem letzten Lesen;
 * veraltet → Fehlerergebnis version_conflict mit dem aktuellen Stand. Der Verlauf vermerkt jede
 * Änderung „über Claude“ (RequestContext::channel()).
 *
 * destructiveHint nur bei aussondern, Import übernehmen und Standort sperren (Festlegung Holger,
 * 08.11.2026, wie docs/MCP-KONZEPT.md): Claude fragt dort vorher nach; alle anderen schreibenden
 * Werkzeuge ohne (Stammdaten, Code, Wartungsdatum und Typ deaktivieren lassen sich mit demselben
 * Werkzeug zurückstellen, der Verlauf behält den alten Wert). idempotentHint: ein zweiter gleicher
 * Aufruf ändert nichts mehr (Version, Zielzustand oder Buchungs-Kennung); nicht beim Anlegen und
 * beim Import.
 */
final class WriteTools
{
    private const array SERVICE_STATUS = ['ready', 'maintenance', 'defective', 'used'];
    private const array KINDS = ['vehicle', 'room', 'external'];
    private const int MIN_DEFECT_COMMENT = 5;

    private function __construct()
    {
    }

    /**
     * @return list<ToolDefinition>
     */
    public static function all(): array
    {
        return [
            self::book(),
            self::bulkBook(),
            self::setDue(),
            self::bulkDue(),
            self::create(),
            self::update(),
            self::assignCode(),
            self::retire(),
            self::reinstate(),
            self::reportUsed(),
            self::swap(),
            self::lend(),
            self::previewImport(),
            self::applyImport(),
            ...self::locationTools(),
            ...self::typeTools(),
            self::notice('resolve_notice', 'Hinweis erledigen', 'Einen Hinweis des Protokolls als erledigt markieren, optional mit Notiz (mehrzeilig, höchstens 500 Zeichen).', 'resolve', true),
            self::notice('reopen_notice', 'Hinweis wieder öffnen', 'Einen erledigten Hinweis des Protokolls wieder öffnen.', 'reopen', false),
        ];
    }

    private static function book(): ToolDefinition
    {
        return new ToolDefinition(
            'book_equipment',
            'Umbuchen / Status ändern',
            'Eine Ausrüstung umbuchen und/oder ihren Status setzen (einsatzbereit, in Wartung, defekt, benutzt) wie '
            . '„Umbuchen“ in der App. Braucht die Version aus get_equipment. Wechselt der Status auf defekt, '
            . 'ist ein Kommentar mit mindestens 5 Zeichen Pflicht („Was ist defekt?“). Ein zweiter gleicher '
            . 'Aufruf bucht nicht doppelt.',
            ReadTools::schema([
                'id' => ReadTools::id('ID der Ausrüstung.'),
                'version' => self::version(),
                'location_id' => ReadTools::id('Neuer Standort oder Fach (ID aus list_locations); weglassen = unverändert.'),
                'status' => ['type' => 'string', 'enum' => self::SERVICE_STATUS, 'description' => 'Neuer Status: ready (einsatzbereit), maintenance (in Wartung), defective (defekt), used (benutzt); weglassen = unverändert.'],
                'comment' => self::comment(),
            ], ['id', 'version']),
            ['POST api/v1/devices/{id}/state', 'GET api/v1/devices/{id}'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                if (!$args->has('location_id') && !$args->has('status')) {
                    throw ToolFailure::invalidArguments('Bitte location_id und/oder status angeben.');
                }
                $id = (int) $args->int('id');
                $body = ['version' => (int) $args->int('version')];
                if ($args->has('location_id')) {
                    $body['location_id'] = (int) $args->int('location_id');
                }
                if ($args->has('status')) {
                    $body['status'] = (string) $args->string('status');
                }
                $comment = self::text($args, 'comment');
                if ($comment !== null) {
                    $body['comment'] = $comment;
                }
                if (($body['status'] ?? null) === 'defective') {
                    $current = $api->get('api/v1/devices/{id}', ['id' => $id])['device'] ?? [];
                    if (is_array($current) && ($current['status'] ?? null) !== 'defective' && mb_strlen(trim($comment ?? '')) < self::MIN_DEFECT_COMMENT) {
                        throw ToolFailure::invalidArguments('Was ist defekt? Bitte einen Kommentar mit mindestens 5 Zeichen angeben.');
                    }
                }
                $body['client_ref'] = $api->stableRef('book', $id, $body);
                $response = $api->post('api/v1/devices/{id}/state', ['id' => $id], $body);
                $result = is_array($response['result'] ?? null) ? $response['result'] : [];

                return self::deviceResult(
                    $response,
                    (string) ($result['message'] ?? 'Gebucht.') . (($result['replayed'] ?? false) === true ? ' (Diese Buchung war schon angekommen.)' : ''),
                    ['outcome' => $result['outcome'] ?? null, 'outcome_label' => $result['outcome_label'] ?? null],
                );
            },
            readOnly: false,
        );
    }

    private static function bulkBook(): ToolDefinition
    {
        return new ToolDefinition(
            'bulk_book_equipment',
            'Mehrere umbuchen / Status ändern',
            'Bis zu 50 Ausrüstungen in einem Aufruf umbuchen und/oder ihren Status setzen (Sammelaktion der '
            . 'Ausrüstungsliste). Je Eintrag id und version aus dem letzten Lesen. Teilerfolg möglich: das '
            . 'Ergebnis nennt je Ausrüstung geändert, unverändert oder den Fehler. Bei defekt Kommentar Pflicht.',
            ReadTools::schema([
                'entries' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => DeviceBulkHandler::MAX_DEVICES,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ReadTools::id('ID der Ausrüstung.'),
                            'version' => self::version(),
                            'location_id' => ReadTools::id('Neuer Standort oder Fach.'),
                            'status' => ['type' => 'string', 'enum' => self::SERVICE_STATUS, 'description' => 'Neuer Status.'],
                        ],
                        'required' => ['id', 'version'],
                        'additionalProperties' => false,
                    ],
                    'description' => 'Ausrüstungen mit Version und Ziel (Standort und/oder Status).',
                ],
                'comment' => self::comment(),
            ], ['entries']),
            ['POST api/v1/devices/bulk/state'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $comment = self::text($args, 'comment');
                $devices = [];
                foreach (self::entries($args) as $i => $entry) {
                    if (!isset($entry['location_id']) && !isset($entry['status'])) {
                        throw ToolFailure::invalidArguments(sprintf('Eintrag %d: bitte location_id und/oder status angeben.', $i + 1));
                    }
                    if (($entry['status'] ?? null) === 'defective' && mb_strlen(trim($comment ?? '')) < self::MIN_DEFECT_COMMENT) {
                        throw ToolFailure::invalidArguments('Was ist defekt? Bitte einen Kommentar mit mindestens 5 Zeichen angeben.');
                    }
                    $entry = array_filter($entry, static fn (mixed $v): bool => $v !== null);
                    $entry['client_ref'] = $api->stableRef('bulk-book', (int) $entry['id'], [...$entry, 'comment' => $comment]);
                    $devices[] = $entry;
                }

                return self::bulkResult($api->post('api/v1/devices/bulk/state', [], array_filter(['devices' => $devices, 'comment' => $comment], static fn (mixed $v): bool => $v !== null)), 'Umbuchen / Status');
            },
            readOnly: false,
        );
    }

    private static function setDue(): ToolDefinition
    {
        return new ToolDefinition(
            'set_maintenance_date',
            'Wartungsdatum ändern',
            'Wartungsdatum (nächste Wartung fällig) einer Ausrüstung setzen oder mit "" entfernen. Braucht die '
            . 'Version aus get_equipment.',
            ReadTools::schema([
                'id' => ReadTools::id('ID der Ausrüstung.'),
                'version' => self::version(),
                'maintenance_due' => self::date('Neues Wartungsdatum JJJJ-MM-TT; "" entfernt es.'),
                'comment' => self::comment(),
            ], ['id', 'version', 'maintenance_due']),
            ['POST api/v1/devices/{id}/inspection-due'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $body = ['version' => (int) $args->int('version'), 'next_inspection_due' => self::emptyToNull($args->string('maintenance_due'))];
                self::addComment($args, $body);

                return self::deviceResult($api->post('api/v1/devices/{id}/inspection-due', ['id' => (int) $args->int('id')], $body), 'Wartungsdatum gespeichert.');
            },
            readOnly: false,
        );
    }

    private static function bulkDue(): ToolDefinition
    {
        return new ToolDefinition(
            'bulk_set_maintenance_date',
            'Wartungsdatum für mehrere setzen',
            'Wartungsdatum für bis zu 50 Ausrüstungen setzen oder mit "" entfernen (Sammelaktion). Je Eintrag id '
            . 'und version aus dem letzten Lesen; Teilerfolg möglich.',
            ReadTools::schema([
                'entries' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => DeviceBulkHandler::MAX_DEVICES,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ReadTools::id('ID der Ausrüstung.'),
                            'version' => self::version(),
                            'maintenance_due' => self::date('Wartungsdatum JJJJ-MM-TT; "" entfernt es.'),
                        ],
                        'required' => ['id', 'version', 'maintenance_due'],
                        'additionalProperties' => false,
                    ],
                    'description' => 'Ausrüstungen mit Version und Wartungsdatum.',
                ],
                'comment' => self::comment(),
            ], ['entries']),
            ['POST api/v1/devices/bulk/inspection-due'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $devices = array_map(static fn (array $e): array => [
                    'id' => $e['id'],
                    'version' => $e['version'],
                    'next_inspection_due' => self::emptyToNull(is_string($e['maintenance_due'] ?? null) ? $e['maintenance_due'] : null),
                ], self::entries($args));
                $body = ['devices' => $devices];
                self::addComment($args, $body);

                return self::bulkResult($api->post('api/v1/devices/bulk/inspection-due', [], $body), 'Wartungsdatum');
            },
            readOnly: false,
        );
    }

    private static function create(): ToolDefinition
    {
        return new ToolDefinition(
            'create_equipment',
            'Neue Ausrüstung',
            'Eine Ausrüstung anlegen (wie „Neue Ausrüstung“ in der App). Pflicht: Nummer, Ausrüstungstyp und '
            . 'Standort (IDs aus list_equipment_types und list_locations, nur aktive). Status Standard einsatzbereit.',
            ReadTools::schema([
                'number' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 30, 'description' => 'Nummer (z. B. M24; Buchstaben, Ziffern, Leerzeichen, - / .).'],
                'equipment_type_id' => ReadTools::id('Ausrüstungstyp.'),
                'location_id' => ReadTools::id('Standort oder Fach.'),
                'status' => ['type' => 'string', 'enum' => self::SERVICE_STATUS, 'description' => 'Status (Standard ready).'],
                'scan_code' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Inhalt des Barcodes bzw. QR-Codes.'],
                'serial_number' => self::field('Seriennummer', 100),
                'manufacturer' => self::field('Hersteller', 100),
                'model' => self::field('Modell', 100),
                'procured_on' => self::date('Beschaffungsdatum JJJJ-MM-TT.'),
                'maintenance_due' => self::date('Wartungsdatum JJJJ-MM-TT.'),
                'note' => self::field('Notiz (mehrzeilig)', 1000),
                'comment' => self::comment(),
            ], ['number', 'equipment_type_id', 'location_id']),
            ['POST api/v1/devices'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $body = [
                    'identifier' => (string) $args->string('number'),
                    'device_type_id' => (int) $args->int('equipment_type_id'),
                    'location_id' => (int) $args->int('location_id'),
                ];
                foreach (['status' => 'status', 'scan_code' => 'scan_code', 'serial_number' => 'serial_number', 'manufacturer' => 'manufacturer', 'model' => 'model', 'procured_on' => 'procured_on', 'maintenance_due' => 'next_inspection_due', 'note' => 'note'] as $arg => $field) {
                    $value = self::emptyToNull($args->string($arg));
                    if ($value !== null) {
                        $body[$field] = $value;
                    }
                }
                self::addComment($args, $body);

                return self::deviceResult($api->post('api/v1/devices', [], $body), 'Angelegt.');
            },
            readOnly: false,
            idempotent: false,
        );
    }

    private static function update(): ToolDefinition
    {
        return new ToolDefinition(
            'update_equipment',
            'Stammdaten bearbeiten',
            'Stammdaten einer Ausrüstung ändern (wie „Bearbeiten“ in der App): Nummer, Ausrüstungstyp, '
            . 'Seriennummer, Hersteller, Modell, Beschaffungsdatum, Notiz. Nur angegebene Felder; "" leert ein '
            . 'optionales Feld. Braucht die Version aus get_equipment. Den Scan-Code ändert assign_code.',
            ReadTools::schema([
                'id' => ReadTools::id('ID der Ausrüstung.'),
                'version' => self::version(),
                'number' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 30, 'description' => 'Neue Nummer.'],
                'equipment_type_id' => ReadTools::id('Neuer Ausrüstungstyp (aktiv).'),
                'serial_number' => self::field('Seriennummer ("" leert)', 100),
                'manufacturer' => self::field('Hersteller ("" leert)', 100),
                'model' => self::field('Modell ("" leert)', 100),
                'procured_on' => self::date('Beschaffungsdatum JJJJ-MM-TT ("" leert).'),
                'note' => self::field('Notiz ("" leert)', 1000),
                'comment' => self::comment(),
            ], ['id', 'version']),
            ['POST api/v1/devices/{id}/fields'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $body = ['version' => (int) $args->int('version')];
                if ($args->has('number')) {
                    $body['identifier'] = (string) $args->string('number');
                }
                if ($args->has('equipment_type_id')) {
                    $body['device_type_id'] = (int) $args->int('equipment_type_id');
                }
                foreach (['serial_number', 'manufacturer', 'model', 'procured_on', 'note'] as $field) {
                    if ($args->has($field)) {
                        $body[$field] = self::emptyToNull($args->string($field));
                    }
                }
                if (count($body) === 1) {
                    throw ToolFailure::invalidArguments('Bitte mindestens ein Feld angeben, das sich ändern soll.');
                }
                self::addComment($args, $body);

                return self::deviceResult($api->post('api/v1/devices/{id}/fields', ['id' => (int) $args->int('id')], $body), 'Stammdaten gespeichert.');
            },
            readOnly: false,
            requires: Requirement::action(Action::DeviceEdit),
        );
    }

    private static function assignCode(): ToolDefinition
    {
        return new ToolDefinition(
            'assign_code',
            'Code zuordnen',
            'Scan-Code (Barcode, QR-Code, GS1-DataMatrix) einer Ausrüstung zuordnen oder mit "" entfernen. '
            . 'Braucht die Version aus get_equipment.',
            ReadTools::schema([
                'id' => ReadTools::id('ID der Ausrüstung.'),
                'version' => self::version(),
                'scan_code' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Inhalt des Codes; "" entfernt ihn.'],
                'comment' => self::comment(),
            ], ['id', 'version', 'scan_code']),
            ['POST api/v1/devices/{id}/fields'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $body = ['version' => (int) $args->int('version'), 'scan_code' => self::emptyToNull($args->string('scan_code'))];
                self::addComment($args, $body);

                return self::deviceResult($api->post('api/v1/devices/{id}/fields', ['id' => (int) $args->int('id')], $body), 'Code gespeichert.');
            },
            readOnly: false,
            requires: Requirement::action(Action::DeviceCode),
        );
    }

    private static function retire(): ToolDefinition
    {
        return new ToolDefinition(
            'retire_equipment',
            'Aussondern',
            'Eine Ausrüstung aussondern: sie verschwindet aus Listen und Terminen, Nummer und Scan-Code werden '
            . 'frei. Nur nach ausdrücklicher Bestätigung durch die Person. Braucht die Version aus get_equipment.',
            ReadTools::schema([
                'id' => ReadTools::id('ID der Ausrüstung.'),
                'version' => self::version(),
                'comment' => self::comment('Grund (freiwillig)'),
            ], ['id', 'version']),
            ['POST api/v1/devices/{id}/retire'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $body = ['version' => (int) $args->int('version')];
                self::addComment($args, $body);

                return self::deviceResult($api->post('api/v1/devices/{id}/retire', ['id' => (int) $args->int('id')], $body), 'Ausgesondert.');
            },
            readOnly: false,
            destructive: true,
        );
    }

    private static function reinstate(): ToolDefinition
    {
        return new ToolDefinition(
            'reinstate_equipment',
            'Wieder aufnehmen',
            'Eine ausgesonderte Ausrüstung wieder aufnehmen, mit Status und optional neuem Standort, neuer Nummer, '
            . 'neuem Ausrüstungstyp oder Scan-Code ("" entfernt den Code). Braucht die Version aus get_equipment.',
            ReadTools::schema([
                'id' => ReadTools::id('ID der ausgesonderten Ausrüstung.'),
                'version' => self::version(),
                'status' => ['type' => 'string', 'enum' => self::SERVICE_STATUS, 'description' => 'Status nach dem Wiederaufnehmen.'],
                'location_id' => ReadTools::id('Standort (Standard: der bisherige).'),
                'number' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 30, 'description' => 'Neue Nummer, falls die bisherige vergeben ist.'],
                'equipment_type_id' => ReadTools::id('Anderer Ausrüstungstyp.'),
                'scan_code' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Neuer Scan-Code; "" entfernt ihn.'],
                'comment' => self::comment(),
            ], ['id', 'version', 'status']),
            ['POST api/v1/devices/{id}/reinstate'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $body = ['version' => (int) $args->int('version'), 'status' => (string) $args->string('status')];
                if ($args->has('location_id')) {
                    $body['location_id'] = (int) $args->int('location_id');
                }
                if ($args->has('number')) {
                    $body['identifier'] = (string) $args->string('number');
                }
                if ($args->has('equipment_type_id')) {
                    $body['device_type_id'] = (int) $args->int('equipment_type_id');
                }
                if ($args->has('scan_code')) {
                    $body['scan_code'] = self::emptyToNull($args->string('scan_code'));
                }
                self::addComment($args, $body);

                return self::deviceResult($api->post('api/v1/devices/{id}/reinstate', ['id' => (int) $args->int('id')], $body), 'Wieder aufgenommen.');
            },
            readOnly: false,
        );
    }

    private static function reportUsed(): ToolDefinition
    {
        return new ToolDefinition(
            'report_used',
            'Benutzt melden',
            'Eine Ausrüstung als benutzt melden (wie „Benutzt melden“ in der App): Status benutzt, sie kommt in die '
            . 'Sammelstelle der Stadt, falls es eine gibt, sonst bleibt der Standort. Braucht die Version aus '
            . 'get_equipment. Ein zweiter gleicher Aufruf bucht nicht doppelt.',
            ReadTools::schema([
                'id' => ReadTools::id('ID der Ausrüstung.'),
                'version' => self::version(),
                'comment' => self::comment(),
            ], ['id', 'version']),
            ['POST api/v1/devices/{id}/used'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $id = (int) $args->int('id');
                $body = ['version' => (int) $args->int('version')];
                self::addComment($args, $body);
                $body['client_ref'] = $api->stableRef('used', $id, $body);
                $response = $api->post('api/v1/devices/{id}/used', ['id' => $id], $body);
                $result = is_array($response['result'] ?? null) ? $response['result'] : [];

                return self::deviceResult(
                    $response,
                    (string) ($result['message'] ?? 'Als benutzt gemeldet.') . (($result['replayed'] ?? false) === true ? ' (Diese Meldung war schon angekommen.)' : ''),
                    ['outcome' => $result['outcome'] ?? null, 'outcome_label' => $result['outcome_label'] ?? null],
                );
            },
            readOnly: false,
            requires: Requirement::action(Action::DeviceUsed),
        );
    }

    private static function swap(): ToolDefinition
    {
        return new ToolDefinition(
            'swap_equipment',
            'Tauschen',
            'Eine Ausrüstung gegen einsatzbereiten Ersatz tauschen (wie „Tauschen …“ in der App): die Ausrüstung geht '
            . 'heraus (Status benutzt, defekt oder in Wartung; benutzt und defekt kommen in die Sammelstelle, wenn es '
            . 'eine gibt), der Ersatz kommt an ihren bisherigen Standort. Beide werden zusammen gebucht und brauchen '
            . 'die Version aus get_equipment. Würde der Sollbestand am Standort trotzdem unterschritten, lehnt die '
            . 'Stadt den Tausch ab; über Claude lässt sich das nicht übergehen. Ein zweiter gleicher Aufruf bucht '
            . 'nicht doppelt.',
            ReadTools::schema([
                'out_id' => ReadTools::id('ID der Ausrüstung, die herausgeht.'),
                'out_version' => self::version(),
                'in_id' => ReadTools::id('ID des einsatzbereiten Ersatzes.'),
                'in_version' => self::version(),
                'out_status' => ['type' => 'string', 'enum' => ['used', 'defective', 'maintenance'], 'description' => 'Status der herausgenommenen Ausrüstung: used (benutzt, Standard), defective (defekt), maintenance (in Wartung).'],
                'comment' => self::comment(),
            ], ['out_id', 'out_version', 'in_id', 'in_version']),
            ['POST api/v1/devices/swap'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $outId = (int) $args->int('out_id');
                $body = [
                    'out' => ['id' => $outId, 'version' => (int) $args->int('out_version')],
                    'in' => ['id' => (int) $args->int('in_id'), 'version' => (int) $args->int('in_version')],
                ];
                if ($args->has('out_status')) {
                    $body['out_status'] = (string) $args->string('out_status');
                }
                self::addComment($args, $body);
                $body['client_ref'] = $api->stableRef('swap', $outId, $body);
                $response = $api->post('api/v1/devices/swap', [], $body);
                $out = ToolFormat::equipmentDetail(is_array($response['out'] ?? null) ? $response['out'] : []);
                $in = ToolFormat::equipmentDetail(is_array($response['in'] ?? null) ? $response['in'] : []);
                $result = is_array($response['result'] ?? null) ? $response['result'] : [];

                return ToolResult::ok(
                    sprintf(
                        'Getauscht: %s ist jetzt %s (%s), Ersatz %s steht in %s.%s',
                        (string) $out['number'],
                        (string) $out['status_label'],
                        (string) $out['location'],
                        (string) $in['number'],
                        (string) $in['location'],
                        ($result['replayed'] ?? false) === true ? ' (Dieser Tausch war schon angekommen.)' : '',
                    ),
                    ['equipment_out' => $out, 'equipment_in' => $in, 'outcome' => $result['outcome'] ?? null],
                    ['equipment_out.note', 'equipment_out.serial_number', 'equipment_out.manufacturer', 'equipment_out.model', 'equipment_in.note', 'equipment_in.serial_number', 'equipment_in.manufacturer', 'equipment_in.model'],
                );
            },
            readOnly: false,
            requires: Requirement::action(Action::DeviceSwap),
        );
    }

    private static function lend(): ToolDefinition
    {
        return new ToolDefinition(
            'lend_equipment',
            'Verleihen',
            'Bis zu 50 Ausrüstungen an einen Leihnehmer verleihen (wie „Verleihen“ in der App): sie werden auf den '
            . 'Standort des Leihnehmers gebucht (borrowers aus list_loans), der Status bleibt. Je Eintrag id und '
            . 'version aus dem letzten Lesen; Teilerfolg möglich. Ansprechpartner und „Rückgabe bis“ sind freiwillig.',
            ReadTools::schema([
                'entries' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => DeviceBulkHandler::MAX_DEVICES,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ReadTools::id('ID der Ausrüstung.'),
                            'version' => self::version(),
                        ],
                        'required' => ['id', 'version'],
                        'additionalProperties' => false,
                    ],
                    'description' => 'Ausrüstungen mit Version.',
                ],
                'borrower_id' => ReadTools::id('Leihnehmer: ID eines Standorts mit der Aufgabe „Ausgeliehen an“ (borrowers aus list_loans).'),
                'contact' => self::field('Ansprechpartner beim Leihnehmer', 100),
                'return_by' => self::date('Rückgabe bis JJJJ-MM-TT.'),
                'comment' => self::comment(),
            ], ['entries', 'borrower_id']),
            ['POST api/v1/loans'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $body = [
                    'devices' => array_map(static fn (array $e): array => ['id' => $e['id'], 'version' => $e['version']], self::entries($args)),
                    'borrower_location_id' => (int) $args->int('borrower_id'),
                ];
                $contact = self::text($args, 'contact');
                if ($contact !== null) {
                    $body['contact'] = $contact;
                }
                $due = self::emptyToNull($args->string('return_by'));
                if ($due !== null) {
                    $body['due_back_on'] = $due;
                }
                self::addComment($args, $body);
                $response = $api->post('api/v1/loans', [], $body);
                $borrower = is_array($response['borrower'] ?? null) ? ToolFormat::name($response['borrower']) : null;

                return self::bulkResult($response, sprintf('Verleih an „%s“', $borrower ?? '?'), ['borrower' => $borrower]);
            },
            readOnly: false,
        );
    }

    private static function previewImport(): ToolDefinition
    {
        return new ToolDefinition(
            'preview_import',
            'CSV-Import prüfen',
            'CSV-Datei der Ausrüstungsliste prüfen, ohne etwas zu speichern (wie „Datei prüfen“ in der App; '
            . 'Spalten wie der Export: Nummer, Scan-Code, Ausrüstungstyp, Standort, Status, Nächste Wartung …). '
            . 'Ohne Fehler kommt ein Kennzeichen (token); übernommen wird nur mit apply_import, derselben Datei '
            . 'und diesem Kennzeichen, nach Bestätigung durch die Person.',
            ReadTools::schema([
                'csv' => self::csv(),
                'rows' => ['type' => 'string', 'enum' => ['errors', 'warnings', 'changes', 'all'], 'description' => 'Welche Zeilen zeigen (Standard: Fehler, sonst Warnungen, sonst Änderungen; höchstens 50).'],
            ], ['csv']),
            ['POST api/v1/devices/import/preview'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $preview = $api->post('api/v1/devices/import/preview', [], ['file' => base64_encode((string) $args->string('csv'))]);

                return self::importResult($preview, $args->string('rows'));
            },
            readOnly: true,
        );
    }

    private static function applyImport(): ToolDefinition
    {
        return new ToolDefinition(
            'apply_import',
            'CSV-Import übernehmen',
            'Einen geprüften CSV-Import übernehmen: dieselbe Datei wie bei preview_import und deren Kennzeichen '
            . '(token). Legt Ausrüstung an und ändert bestehende, alles oder nichts. Hat sich seit der Prüfung '
            . 'etwas geändert, kommt import_outdated: dann neu prüfen. Nur nach ausdrücklicher Bestätigung.',
            ReadTools::schema([
                'csv' => self::csv(),
                'token' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'description' => 'Kennzeichen aus preview_import.'],
                'comment' => self::comment(),
            ], ['csv', 'token']),
            ['POST api/v1/devices/import'],
            static function (ToolArguments $args, ToolApi $api): ToolResult {
                $body = ['file' => base64_encode((string) $args->string('csv')), 'token' => (string) $args->string('token')];
                self::addComment($args, $body);
                $result = $api->post('api/v1/devices/import', [], $body);
                $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];

                return ToolResult::ok(
                    sprintf('Import übernommen: %d neu, %d geändert, %d unverändert, %d übersprungen.', (int) ($summary['new'] ?? 0), (int) ($summary['changed'] ?? 0), (int) ($summary['unchanged'] ?? 0), (int) ($summary['skipped'] ?? 0)),
                    ['summary' => $summary],
                );
            },
            readOnly: false,
            destructive: true,
            idempotent: false,
        );
    }

    /**
     * @return list<ToolDefinition>
     */
    private static function locationTools(): array
    {
        $result = static fn (array $response, string $text): ToolResult => ToolResult::ok(
            $text,
            ['location' => self::location(is_array($response['location'] ?? null) ? $response['location'] : [])],
            ['location.name'],
        );

        return [
            new ToolDefinition(
                'create_location',
                'Standort anlegen',
                'Einen Standort der obersten Ebene (Fahrzeug, Raum/Lager, Extern; kind Pflicht) oder ein Fach in '
                . 'einem Standort (parent_id) anlegen.',
                ReadTools::schema([
                    'name' => self::field('Name', 100, 1),
                    'kind' => ['type' => 'string', 'enum' => self::KINDS, 'description' => 'vehicle (Fahrzeug), room (Raum/Lager), external (Extern); bei Fächern weglassen.'],
                    'parent_id' => ReadTools::id('Standort, in dem das Fach liegt.'),
                ], ['name']),
                ['POST api/v1/locations'],
                static function (ToolArguments $args, ToolApi $api) use ($result): ToolResult {
                    $body = ['name' => (string) $args->string('name')];
                    if ($args->has('kind')) {
                        $body['kind'] = (string) $args->string('kind');
                    }
                    if ($args->has('parent_id')) {
                        $body['parent_id'] = (int) $args->int('parent_id');
                    }

                    return $result($api->post('api/v1/locations', [], $body), 'Standort angelegt.');
                },
                readOnly: false,
                idempotent: false,
            ),
            new ToolDefinition(
                'rename_location',
                'Standort umbenennen',
                'Einen Standort oder ein Fach umbenennen.',
                ReadTools::schema(['id' => ReadTools::id('ID des Standorts.'), 'name' => self::field('Neuer Name', 100, 1)], ['id', 'name']),
                ['POST api/v1/locations/{id}/rename'],
                static fn (ToolArguments $args, ToolApi $api): ToolResult => $result($api->post('api/v1/locations/{id}/rename', ['id' => (int) $args->int('id')], ['name' => (string) $args->string('name')]), 'Standort umbenannt.'),
                readOnly: false,
            ),
            new ToolDefinition(
                'lock_location',
                'Standort sperren',
                'Einen leeren Standort sperren (kein Ziel mehr für Buchungen). Geht nur ohne Ausrüstung und ohne '
                . 'aktive Fächer. Nur nach Bestätigung durch die Person.',
                ReadTools::schema(['id' => ReadTools::id('ID des Standorts.')], ['id']),
                ['POST api/v1/locations/{id}/lock'],
                static fn (ToolArguments $args, ToolApi $api): ToolResult => $result($api->post('api/v1/locations/{id}/lock', ['id' => (int) $args->int('id')]), 'Standort gesperrt.'),
                readOnly: false,
                destructive: true,
            ),
            new ToolDefinition(
                'unlock_location',
                'Standort entsperren',
                'Einen gesperrten Standort wieder entsperren (ein Fach erst nach seinem Standort).',
                ReadTools::schema(['id' => ReadTools::id('ID des Standorts.')], ['id']),
                ['POST api/v1/locations/{id}/unlock'],
                static fn (ToolArguments $args, ToolApi $api): ToolResult => $result($api->post('api/v1/locations/{id}/unlock', ['id' => (int) $args->int('id')]), 'Standort entsperrt.'),
                readOnly: false,
            ),
        ];
    }

    /**
     * @return list<ToolDefinition>
     */
    private static function typeTools(): array
    {
        $result = static function (array $response, string $text): ToolResult {
            $type = is_array($response['device_type'] ?? null) ? $response['device_type'] : [];

            return ToolResult::ok($text, ['equipment_type' => [
                'id' => $type['id'] ?? null,
                'name' => $type['name'] ?? null,
                'active' => $type['active'] ?? null,
                'equipment_count' => $type['device_count'] ?? null,
            ]], ['equipment_type.name']);
        };
        $id = static fn (ToolArguments $args): array => ['id' => (int) $args->int('id')];

        return [
            new ToolDefinition(
                'create_equipment_type',
                'Ausrüstungstyp anlegen',
                'Einen Ausrüstungstyp anlegen (Name je Stadt eindeutig).',
                ReadTools::schema(['name' => self::field('Name', 50, 1)], ['name']),
                ['POST api/v1/device-types'],
                static fn (ToolArguments $args, ToolApi $api): ToolResult => $result($api->post('api/v1/device-types', [], ['name' => (string) $args->string('name')]), 'Ausrüstungstyp angelegt.'),
                readOnly: false,
                idempotent: false,
            ),
            new ToolDefinition(
                'rename_equipment_type',
                'Ausrüstungstyp umbenennen',
                'Einen Ausrüstungstyp umbenennen.',
                ReadTools::schema(['id' => ReadTools::id('ID des Ausrüstungstyps.'), 'name' => self::field('Neuer Name', 50, 1)], ['id', 'name']),
                ['POST api/v1/device-types/{id}/rename'],
                static fn (ToolArguments $args, ToolApi $api): ToolResult => $result($api->post('api/v1/device-types/{id}/rename', $id($args), ['name' => (string) $args->string('name')]), 'Ausrüstungstyp umbenannt.'),
                readOnly: false,
            ),
            new ToolDefinition(
                'deactivate_equipment_type',
                'Ausrüstungstyp deaktivieren',
                'Einen Ausrüstungstyp deaktivieren (kein Ziel mehr für neue Ausrüstung; vorhandene behalten ihn). '
                . 'Nur nach Bestätigung durch die Person.',
                ReadTools::schema(['id' => ReadTools::id('ID des Ausrüstungstyps.')], ['id']),
                ['POST api/v1/device-types/{id}/deactivate'],
                static fn (ToolArguments $args, ToolApi $api): ToolResult => $result($api->post('api/v1/device-types/{id}/deactivate', $id($args)), 'Ausrüstungstyp deaktiviert.'),
                readOnly: false,
            ),
            new ToolDefinition(
                'activate_equipment_type',
                'Ausrüstungstyp aktivieren',
                'Einen deaktivierten Ausrüstungstyp wieder aktivieren.',
                ReadTools::schema(['id' => ReadTools::id('ID des Ausrüstungstyps.')], ['id']),
                ['POST api/v1/device-types/{id}/activate'],
                static fn (ToolArguments $args, ToolApi $api): ToolResult => $result($api->post('api/v1/device-types/{id}/activate', $id($args)), 'Ausrüstungstyp aktiviert.'),
                readOnly: false,
            ),
        ];
    }

    private static function notice(string $name, string $title, string $description, string $action, bool $withNote): ToolDefinition
    {
        $properties = ['id' => ReadTools::id('ID des Hinweises (aus list_notices).')];
        if ($withNote) {
            $properties['note'] = self::field('Notiz (freiwillig)', 500);
        }
        $route = 'POST api/v1/notices/{id}/' . $action;

        return new ToolDefinition(
            $name,
            $title,
            $description,
            ReadTools::schema($properties, ['id']),
            [$route],
            static function (ToolArguments $args, ToolApi $api) use ($action, $withNote): ToolResult {
                $body = [];
                $note = $withNote ? self::text($args, 'note') : null;
                if ($note !== null) {
                    $body['note'] = $note;
                }
                $notice = $api->post('api/v1/notices/{id}/' . $action, ['id' => (int) $args->int('id')], $body)['notice'] ?? [];

                return ToolResult::ok(
                    sprintf('Hinweis %d: %s.', (int) $args->int('id'), is_array($notice) ? (string) ($notice['status_label'] ?? '') : ''),
                    ['notice' => ['id' => is_array($notice) ? ($notice['id'] ?? null) : null, 'status' => is_array($notice) ? ($notice['status'] ?? null) : null, 'status_label' => is_array($notice) ? ($notice['status_label'] ?? null) : null]],
                );
            },
            readOnly: false,
        );
    }

    /**
     * Antwort mit einer Ausrüstung ({device}): kompakter Stand danach.
     *
     * @param array<array-key, mixed> $response
     * @param array<string, mixed>    $extra
     */
    private static function deviceResult(array $response, string $text, array $extra = []): ToolResult
    {
        $device = ToolFormat::equipmentDetail(is_array($response['device'] ?? null) ? $response['device'] : []);

        return ToolResult::ok(
            sprintf('%s %s (%s): %s, %s. Version jetzt %s.', $text, (string) $device['number'], (string) $device['equipment_type'], (string) $device['status_label'], (string) $device['location'], (string) $device['version']),
            [...$extra, 'equipment' => $device],
            ['equipment.note', 'equipment.serial_number', 'equipment.manufacturer', 'equipment.model'],
        );
    }

    /**
     * Ergebnis einer Sammelaktion: je Ausrüstung Ergebnis und Stand bzw. Fehler.
     *
     * @param array<array-key, mixed> $response
     * @param array<string, mixed>    $extra
     */
    private static function bulkResult(array $response, string $what, array $extra = []): ToolResult
    {
        $results = [];
        foreach (is_array($response['results'] ?? null) ? $response['results'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $entry = ['id' => $row['id'] ?? null, 'outcome' => $row['outcome'] ?? null];
            if (is_array($row['device'] ?? null)) {
                $entry['equipment'] = ToolFormat::equipment($row['device']);
            }
            if (is_array($row['error'] ?? null)) {
                $entry['error'] = ['error' => $row['error']['error'] ?? null, 'message' => $row['error']['message'] ?? null];
            }
            $results[] = $entry;
        }
        $summary = is_array($response['summary'] ?? null) ? $response['summary'] : [];

        return ToolResult::ok(
            sprintf('%s: %d geändert, %d unverändert, %d nicht möglich.', $what, (int) ($summary['applied'] ?? 0), (int) ($summary['unchanged'] ?? 0), (int) ($summary['errors'] ?? 0)),
            [...$extra, 'results' => $results, 'summary' => $summary],
        );
    }

    /**
     * Vorschau des Imports kompakt: Zusammenfassung, unbekannte Werte mit Vorschlägen, Zeilen
     * (höchstens 50) und Kennzeichen.
     *
     * @param array<array-key, mixed> $preview
     */
    private static function importResult(array $preview, ?string $filter): ToolResult
    {
        $summary = is_array($preview['summary'] ?? null) ? $preview['summary'] : [];
        $rows = is_array($preview['rows'] ?? null) ? array_values(array_filter($preview['rows'], is_array(...))) : [];
        $filter ??= ((int) ($summary['errors'] ?? 0)) > 0 ? 'errors' : (((int) ($summary['warnings'] ?? 0)) > 0 ? 'warnings' : 'changes');
        $matching = array_values(array_filter($rows, static fn (array $r): bool => match ($filter) {
            'errors' => ($r['result'] ?? null) === 'error',
            'warnings' => ($r['warnings'] ?? []) !== [],
            'changes' => in_array($r['result'] ?? null, ['new', 'changed'], true),
            default => true,
        }));
        $shown = array_map(static fn (array $r): array => [
            'line' => $r['line'] ?? null,
            'number' => $r['identifier'] ?? null,
            'result' => $r['result_label'] ?? null,
            'changes' => array_map(static fn (mixed $c): string => is_array($c) ? sprintf('%s: %s → %s', $c['label'] ?? '?', $c['old'] ?? 'leer', $c['new'] ?? 'leer') : '', is_array($r['changes'] ?? null) ? $r['changes'] : []),
            'messages' => $r['messages'] ?? [],
            'warnings' => $r['warnings'] ?? [],
        ], array_slice($matching, 0, ToolFormat::MAX_LIST));
        $token = $preview['token'] ?? null;

        return ToolResult::ok(
            sprintf(
                'Vorschau: %d Zeilen, %d neu, %d geändert, %d unverändert, %d übersprungen, %d Fehler, %d mit Warnung. %s',
                (int) ($summary['rows'] ?? 0),
                (int) ($summary['new'] ?? 0),
                (int) ($summary['changed'] ?? 0),
                (int) ($summary['unchanged'] ?? 0),
                (int) ($summary['skipped'] ?? 0),
                (int) ($summary['errors'] ?? 0),
                (int) ($summary['warnings'] ?? 0),
                is_string($token) ? 'Übernehmen mit apply_import und diesem token (nach Bestätigung).' : 'Erst die Fehler beheben, dann neu prüfen.',
            ),
            [
                'summary' => $summary,
                'encoding' => $preview['encoding'] ?? null,
                'delimiter' => $preview['delimiter_label'] ?? null,
                'unknown' => $preview['unknown'] ?? null,
                'suggestions' => $preview['suggestions'] ?? null,
                'rows_filter' => $filter,
                'rows' => $shown,
                'rows_total' => count($matching),
                'token' => $token,
            ],
            ['rows[].changes', 'rows[].messages'],
        );
    }

    /**
     * @param array<array-key, mixed> $location
     *
     * @return array<string, mixed>
     */
    private static function location(array $location): array
    {
        return [
            'id' => $location['id'] ?? null,
            'name' => $location['name'] ?? null,
            'kind' => $location['kind_label'] ?? null,
            'level' => $location['level'] ?? null,
            'parent_id' => $location['parent_id'] ?? null,
            'active' => $location['active'] ?? null,
        ];
    }

    /**
     * Einträge einer Sammelaktion (vom Schema geprüft).
     *
     * @return list<array<string, mixed>>
     */
    private static function entries(ToolArguments $args): array
    {
        return array_values(array_filter($args->list('entries') ?? [], is_array(...)));
    }

    /** Freitext eines Arguments; leer bzw. nur Leerraum = keiner. */
    private static function text(ToolArguments $args, string $name): ?string
    {
        $value = $args->string($name);

        return $value === null || trim($value) === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function addComment(ToolArguments $args, array &$body): void
    {
        $comment = self::text($args, 'comment');
        if ($comment !== null) {
            $body['comment'] = $comment;
        }
    }

    private static function emptyToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function version(): array
    {
        return ['type' => 'integer', 'minimum' => 1, 'maximum' => DeviceFields::MAX_ID, 'description' => 'Version aus dem letzten Lesen (get_equipment, search_equipment).'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function comment(string $what = 'Kommentar für den Verlauf (eine Zeile, höchstens 500 Zeichen)'): array
    {
        return ['type' => 'string', 'maxLength' => 500, 'description' => $what . '.'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function field(string $what, int $max, int $min = 0): array
    {
        $schema = ['type' => 'string', 'maxLength' => $max, 'description' => sprintf('%s (höchstens %d Zeichen).', $what, $max)];
        if ($min > 0) {
            $schema['minLength'] = $min;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private static function date(string $description): array
    {
        return ['type' => 'string', 'maxLength' => 10, 'description' => $description];
    }

    /**
     * @return array<string, mixed>
     */
    private static function csv(): array
    {
        return ['type' => 'string', 'minLength' => 1, 'maxLength' => 700_000, 'description' => 'Inhalt der CSV-Datei als Text (Kopfzeile wie die Vorlage bzw. der Export; Trennzeichen ; , oder Tab).'];
    }
}
