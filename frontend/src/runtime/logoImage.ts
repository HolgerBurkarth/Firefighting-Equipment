import { LOGO_EDGE, placeLogo } from '../data/appearance';

/**
 * Bringt ein gewähltes Bild (PNG, JPG, SVG …) auf ein PNG mit LOGO_EDGE × LOGO_EDGE Pixeln
 * (KONZEPT.md, "Admin-Konsole – Entscheidungen", 37): Der Browser liest es über eine
 * `blob:`-Adresse in ein <img> (CSP img-src blob:, ContentSecurityPolicy in PHP), die Canvas
 * zeichnet es mittig mit transparentem Rand. Der Server prüft und erzeugt die Größen danach selbst.
 * null = nicht als Bild lesbar.
 */
export async function renderLogo(source: Blob, doc: Document = document): Promise<Blob | null> {
    const url = URL.createObjectURL(source);
    try {
        const image = doc.createElement('img');
        image.src = url;
        try {
            await image.decode();
        } catch {
            return null;
        }
        const canvas = doc.createElement('canvas');
        canvas.width = LOGO_EDGE;
        canvas.height = LOGO_EDGE;
        const context = canvas.getContext('2d');
        if (context === null) {
            return null;
        }
        const place = placeLogo(image.naturalWidth, image.naturalHeight);
        context.imageSmoothingQuality = 'high';
        context.drawImage(image, place.x, place.y, place.width, place.height);
        return await new Promise<Blob | null>((resolve) => {
            canvas.toBlob(resolve, 'image/png');
        });
    } finally {
        URL.revokeObjectURL(url);
    }
}
