// @vitest-environment node
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { INSPECTION_WINDOW_DAYS, SERVER_INSPECTION_LIMIT } from '../../src/data/inspections';

function constant(file: string, name: string): number {
    const php = readFileSync(new URL(`../../../src/${file}`, import.meta.url), 'utf8');
    const match = new RegExp(`const int ${name} = ([0-9]+);`).exec(php);
    return Number(match?.[1]);
}

describe('Prüffristen wie im Backend', () => {
    it('Fenster 30 Tage wie InspectionWindow::DEFAULT_DAYS', () => {
        expect(INSPECTION_WINDOW_DAYS).toBe(
            constant('Device/InspectionWindow.php', 'DEFAULT_DAYS'),
        );
    });

    it('Grenze je Abschnitt höchstens InspectionDueHandler::MAX_LIMIT', () => {
        expect(SERVER_INSPECTION_LIMIT).toBeLessThanOrEqual(
            constant('Api/V1/Inspections/InspectionDueHandler.php', 'MAX_LIMIT'),
        );
    });
});
