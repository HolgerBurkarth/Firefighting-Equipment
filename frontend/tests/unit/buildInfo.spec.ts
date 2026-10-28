// @vitest-environment node
import { describe, expect, it } from 'vitest';
import { buildId } from '../../build/buildInfo';

describe('buildId', () => {
    it('FF_BUILD_ID vor dem Commit, sonst der gekürzte Commit, sonst dev', () => {
        expect(buildId({ FF_BUILD_ID: 'e2e-next', GITHUB_SHA: 'a'.repeat(40) })).toBe('e2e-next');
        expect(buildId({ GITHUB_SHA: '0123456789abcdef0123' })).toBe('0123456789ab');
        expect(buildId({})).toBe('dev');
    });

    it.each(['', 'mit leer', 'x'.repeat(41), 'a/b', '"x"'])('ungültig: %j', (id) => {
        expect(() => buildId({ FF_BUILD_ID: id })).toThrow();
    });
});
