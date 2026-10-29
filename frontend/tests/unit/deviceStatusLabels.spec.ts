// @vitest-environment node
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { BOOKABLE_STATUSES, STATUS_LABELS } from '../../src/api/devices';

const php = readFileSync(new URL('../../../src/Device/DeviceStatus.php', import.meta.url), 'utf8');

describe('Status', () => {
    it('Bezeichnungen wie DeviceStatus::label() in PHP', () => {
        const labels = Object.fromEntries(
            [...php.matchAll(/self::(\w+) => '([^']+)'/g)].map((m) => [m[1], m[2]]),
        );
        expect(labels).toEqual({
            Ready: STATUS_LABELS.ready,
            Maintenance: STATUS_LABELS.maintenance,
            Defective: STATUS_LABELS.defective,
            Retired: STATUS_LABELS.retired,
        });
    });

    it('buchbar sind alle außer ausgesondert, in der Reihenfolge der Tasten', () => {
        expect(BOOKABLE_STATUSES).toEqual(['ready', 'maintenance', 'defective']);
    });
});
