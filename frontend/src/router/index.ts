import { createRouter, createWebHistory, type Router, type RouteRecordRaw } from 'vue-router';
import type { AppBase } from '../runtime/appBase';

/** Pfade relativ zum Basispfad; deutsche Adressen wie die Mail-Links (AppUrls in PHP). */
export const routes: RouteRecordRaw[] = [
    { path: '/', name: 'home', component: () => import('../pages/HomePage.vue') },
    { path: '/anmelden', name: 'login', component: () => import('../pages/LoginPage.vue') },
    {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: () => import('../pages/NotFoundPage.vue'),
    },
];

export function createAppRouter(base: AppBase): Router {
    return createRouter({ history: createWebHistory(base.path), routes });
}
