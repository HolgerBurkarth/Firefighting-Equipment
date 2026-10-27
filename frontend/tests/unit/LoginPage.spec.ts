import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory } from 'vue-router';
import LoginPage from '../../src/pages/LoginPage.vue';
import { resetSession } from '../../src/auth/session';
import { createAppRouter } from '../../src/router';
import { currentAppBase } from '../../src/runtime/appBase';
import { respond } from './support/fetch';

async function mountLogin() {
    const router = createAppRouter(currentAppBase(), createMemoryHistory());
    await router.push('/anmelden');
    const wrapper = mount(LoginPage, { global: { plugins: [router] }, attachTo: document.body });
    return { wrapper, router };
}

afterEach(() => {
    vi.unstubAllGlobals();
    resetSession();
    document.body.innerHTML = '';
});

describe('LoginPage', () => {
    it('verbindet Beschriftungen und autocomplete', async () => {
        const { wrapper } = await mountLogin();
        expect(wrapper.find('label[for="login-email"]').exists()).toBe(true);
        expect(wrapper.find('label[for="login-password"]').exists()).toBe(true);
        expect(wrapper.get('#login-email').attributes('autocomplete')).toBe('username');
        expect(wrapper.get('#login-email').attributes('type')).toBe('email');
        expect(wrapper.get('#login-password').attributes('autocomplete')).toBe('current-password');
    });

    it('prüft leere Felder ohne Anfrage', async () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        const { wrapper } = await mountLogin();
        await wrapper.get('form').trigger('submit');
        expect(wrapper.text()).toContain('Bitte geben Sie Ihre E-Mail-Adresse ein.');
        expect(wrapper.get('#login-email').attributes('aria-invalid')).toBe('true');
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('sendet das Passwort unverändert, die E-Mail getrimmt', async () => {
        const { fetch, requests } = respond(401, {
            error: 'invalid_credentials',
            message: 'x',
        });
        vi.stubGlobal('fetch', fetch);
        const { wrapper } = await mountLogin();
        await wrapper.get('#login-email').setValue('  anna@example.org ');
        await wrapper.get('#login-password').setValue(' mit Leerzeichen ');
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(requests[0]?.body).toEqual({
            email: 'anna@example.org',
            password: ' mit Leerzeichen ',
        });
        expect(wrapper.get('[role="alert"]').text()).toContain('Passwort ist falsch');
        expect((wrapper.get('#login-password').element as HTMLInputElement).value).toBe('');
        // Die E-Mail bleibt stehen (type=email entfernt Leerraum am Rand selbst).
        expect((wrapper.get('#login-email').element as HTMLInputElement).value).toBe(
            'anna@example.org',
        );
    });
});
