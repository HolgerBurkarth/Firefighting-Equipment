/**
 * Kennung des Builds, beim Bauen gesetzt (vite.config.ts: FF_BUILD_ID, in GitHub Actions der
 * Commit, sonst "dev"). Steht an <html data-build> für Support und Browser-Tests.
 */
export const BUILD_ID: string = __APP_BUILD_ID__;
