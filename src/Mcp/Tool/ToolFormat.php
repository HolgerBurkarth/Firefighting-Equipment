<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mcp\Tool;

use FirefightingEquipment\Device\ScanCode;

/**
 * Kompakte Darstellung der Antworten der REST-API für Claude, mit den Begriffen der App
 * (Ausrüstung, Nummer, Ausrüstungstyp, Standort, Wartungsdatum). Zeiten in Ortszeit
 * (Europe/Berlin) mit Abstand zu UTC, Datumsangaben als JJJJ-MM-TT.
 */
final class ToolFormat
{
    /** Höchstens so viele Einträge je Liste und Aufruf. */
    public const int MAX_LIST = 50;

    private const string ZONE = 'Europe/Berlin';

    private function __construct()
    {
    }

    /**
     * Ausrüstung kurz (Listen): aus DeviceJson::summary bzw. listItem.
     *
     * @param array<array-key, mixed> $device
     *
     * @return array<string, mixed>
     */
    public static function equipment(array $device): array
    {
        $item = [
            'id' => $device['id'] ?? null,
            'number' => $device['identifier'] ?? null,
            'equipment_type' => self::name($device['device_type'] ?? null),
            'location' => self::path($device['location'] ?? null),
            'status' => $device['status'] ?? null,
            'status_label' => $device['status_label'] ?? null,
            'maintenance_due' => $device['next_inspection_due'] ?? null,
            'version' => $device['version'] ?? null,
        ];
        foreach (['days_overdue', 'days_until_due'] as $key) {
            if (array_key_exists($key, $device)) {
                $item[$key] = $device[$key];
            }
        }

        return $item;
    }

    /**
     * Ausrüstung mit allen Feldern (Detailseite): aus DeviceJson::device.
     *
     * @param array<array-key, mixed> $device
     *
     * @return array<string, mixed>
     */
    public static function equipmentDetail(array $device): array
    {
        $scanCode = $device['scan_code'] ?? null;

        return [
            ...self::equipment($device),
            'equipment_type_id' => is_array($device['device_type'] ?? null) ? ($device['device_type']['id'] ?? null) : null,
            'location_id' => is_array($device['location'] ?? null) ? ($device['location']['id'] ?? null) : null,
            'retired' => ($device['status'] ?? null) === 'retired',
            'scan_code' => is_string($scanCode) ? ScanCode::display($scanCode) : null,
            'serial_number' => $device['serial_number'] ?? null,
            'manufacturer' => $device['manufacturer'] ?? null,
            'model' => $device['model'] ?? null,
            'procured_on' => $device['procured_on'] ?? null,
            'note' => $device['note'] ?? null,
            'last_change_at' => self::time($device['state_changed_at'] ?? null),
        ];
    }

    /**
     * Eintrag des Verlaufs: aus DeviceEventJson::event (bei device-events mit device).
     *
     * @param array<array-key, mixed> $event
     *
     * @return array<string, mixed>
     */
    public static function event(array $event): array
    {
        $changes = [];
        $location = $event['location'] ?? null;
        if (is_array($location)) {
            $changes[] = sprintf('Standort: %s → %s', self::eventLocation($location['from'] ?? null), self::eventLocation($location['to'] ?? null));
        }
        $status = $event['status'] ?? null;
        if (is_array($status)) {
            $changes[] = sprintf('Status: %s → %s', $status['from_label'] ?? '–', $status['to_label'] ?? '–');
        }
        $due = $event['inspection_due'] ?? null;
        if (is_array($due)) {
            $changes[] = sprintf('Wartungsdatum: %s → %s', $due['from'] ?? 'keins', $due['to'] ?? 'keins');
        }
        $field = $event['field'] ?? null;
        if (is_array($field)) {
            $changes[] = sprintf('%s: %s → %s', $field['label'] ?? '?', $field['old'] ?? 'leer', $field['new'] ?? 'leer');
        }
        $entry = [
            'id' => $event['id'] ?? null,
            'at' => self::time($event['occurred_at'] ?? null),
            'what' => $event['type_label'] ?? null,
            'user' => is_array($event['user'] ?? null) ? ($event['user']['name'] ?? null) : null,
            'changes' => $changes,
            'comment' => $event['comment'] ?? null,
        ];
        if (($event['channel'] ?? null) === 'claude') {
            // Vermerk „über Claude“ wie in App, Detailseite, CSV und Log-Mails.
            $entry['via_claude'] = true;
        }
        if (($event['applied'] ?? true) === false) {
            $entry['applied'] = false;
            $entry['not_applied_reason'] = $event['not_applied_label'] ?? null;
        }
        if (($event['received_at'] ?? null) !== ($event['occurred_at'] ?? null)) {
            $entry['received_at'] = self::time($event['received_at'] ?? null);
        }
        $device = $event['device'] ?? null;
        if (is_array($device)) {
            $entry = [
                'equipment' => [
                    'id' => $device['id'] ?? null,
                    'number' => $device['identifier'] ?? null,
                    'equipment_type' => self::name($device['device_type'] ?? null),
                ],
                ...$entry,
            ];
        }

        return $entry;
    }

    /**
     * Satz zur Zusammenfassung eines Verlaufs: „3 davon über Claude.“ bzw. leer.
     *
     * @param list<array<string, mixed>> $events Einträge aus event()
     */
    public static function viaClaudeText(array $events): string
    {
        $count = count(array_filter($events, static fn (array $e): bool => ($e['via_claude'] ?? false) === true));

        return match ($count) {
            0 => '',
            1 => ' 1 Eintrag davon über Claude.',
            default => sprintf(' %d Einträge davon über Claude.', $count),
        };
    }

    /**
     * Seiteninformation einer Liste mit Hinweis auf weitere Einträge und Filter.
     *
     * @return array<string, mixed>
     */
    public static function paging(int $total, int $offset, int $shown): array
    {
        $next = $offset + $shown < $total ? $offset + $shown : null;

        return ['total' => $total, 'offset' => $offset, 'shown' => $shown, 'next_offset' => $next];
    }

    /**
     * Satz zur Zusammenfassung: „Angezeigt 1–25 von 312. Weitere mit offset=25 …“.
     */
    public static function pagingText(int $total, int $offset, int $shown, string $filterHint): string
    {
        if ($total === 0) {
            return 'Keine Einträge.';
        }
        if ($shown === 0) {
            return sprintf('%d Einträge, keiner ab offset=%d.', $total, $offset);
        }
        $text = sprintf('Angezeigt %d–%d von %d.', $offset + 1, $offset + $shown, $total);
        if ($offset + $shown < $total) {
            $text .= sprintf(' Weitere mit offset=%d (höchstens %d je Aufruf) oder enger filtern: %s.', $offset + $shown, self::MAX_LIST, $filterHint);
        }

        return $text;
    }

    /**
     * UTC-Zeit der API (…Z) in Ortszeit mit Abstand, z. B. 2026-11-07T15:02:00+01:00.
     */
    public static function time(mixed $utc): ?string
    {
        if (!is_string($utc) || $utc === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($utc)->setTimezone(new \DateTimeZone(self::ZONE))->format(DATE_ATOM);
        } catch (\Exception) {
            return $utc;
        }
    }

    /**
     * @param mixed $item Objekt mit name
     */
    public static function name(mixed $item): ?string
    {
        return is_array($item) && is_string($item['name'] ?? null) ? $item['name'] : null;
    }

    /**
     * @param mixed $location Objekt mit path und name
     */
    public static function path(mixed $location): ?string
    {
        if (!is_array($location)) {
            return null;
        }
        $path = $location['path'] ?? $location['name'] ?? null;

        return is_string($path) ? $path : null;
    }

    /**
     * @param mixed $location {id, name, path} oder null; außerhalb des Datenfilters „anderer Standort“
     */
    private static function eventLocation(mixed $location): string
    {
        return self::path($location) ?? '–';
    }
}
