import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import LoginPage from '../../src/pages/LoginPage.vue';

describe('LoginPage (Platzhalter)', () => {
    it('verbindet die Beschriftungen mit den Eingabefeldern', () => {
        const wrapper = mount(LoginPage);
        for (const id of ['login-email', 'login-password']) {
            expect(wrapper.find(`label[for="${id}"]`).exists()).toBe(true);
            expect(wrapper.find(`input#${id}`).exists()).toBe(true);
        }
    });

    it('sendet noch nichts ab', () => {
        const wrapper = mount(LoginPage);
        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
    });
});
