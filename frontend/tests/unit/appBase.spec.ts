import { describe, expect, it } from 'vitest';
import { apiUrl, parseAppBase } from '../../src/runtime/appBase';

describe('parseAppBase', () => {
    it('liest Stadt und Basispfad aus <base href>', () => {
        expect(parseAppBase('https://www.prodad.de/firefighting/immendingen/')).toEqual({
            path: '/firefighting/immendingen/',
            area: 'immendingen',
            isService: false,
        });
    });

    it('erkennt den Bereich des Super-Admins', () => {
        expect(parseAppBase('http://localhost:8000/firefighting/service/')?.isService).toBe(true);
    });

    it.each([
        'https://www.prodad.de/',
        'https://www.prodad.de/firefighting',
        'https://www.prodad.de/firefighting/test/index.html',
        'https://www.prodad.de/firefighting/Test/',
        'https://www.prodad.de/firefighting/__FF_BASE__/',
        'https://www.prodad.de/__FF_BASE__/',
    ])('lehnt %s ab', (uri) => {
        expect(parseAppBase(uri)).toBeNull();
    });
});

describe('apiUrl', () => {
    it('baut die Adresse relativ zum Basispfad', () => {
        expect(apiUrl('tenant', 'https://www.prodad.de/firefighting/test/')).toBe(
            'https://www.prodad.de/firefighting/test/api/v1/tenant',
        );
    });
});
