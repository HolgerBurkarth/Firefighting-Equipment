/**
 * Schritte der bildlichen Anleitung (Runde 14, Punkt 3): Aushänge und Seiten der Rückgabe und der
 * Registrierung; die Bilder zeichnet `HowToSteps`.
 */
export type StepArt = 'scan' | 'number' | 'condition' | 'shelf' | 'form' | 'mail' | 'approved';

export interface HowToStep {
    readonly art: StepArt;
    readonly title: string;
    readonly text: string;
}

/** Rückgabe ohne Anmeldung (Aushang `ReturnPoster`, Seite `rueckgabe`). */
export const RETURN_STEPS: readonly HowToStep[] = [
    { art: 'scan', title: 'QR-Code scannen', text: 'Handy-Kamera auf den Aushang halten' },
    { art: 'number', title: 'Nummer eingeben', text: 'Steht auf dem Etikett der Ausrüstung' },
    { art: 'condition', title: 'Zustand und Name', text: 'Auswählen und Namen eintragen' },
    { art: 'shelf', title: 'Ablegen', text: 'Ausrüstung ins Regal legen' },
];

/** Registrierung mit Einladungscode (Aushang `InvitationPoster`, Seite `registrieren`). */
export const REGISTER_STEPS: readonly HowToStep[] = [
    { art: 'scan', title: 'QR-Code scannen', text: 'Handy-Kamera auf den Aushang halten' },
    { art: 'form', title: 'Formular ausfüllen', text: 'Name, E-Mail und Passwort' },
    { art: 'mail', title: 'E-Mail bestätigen', text: 'Auf den Link in der Mail tippen' },
    { art: 'approved', title: 'Freischaltung', text: 'Ein Admin schaltet Ihr Konto frei' },
];
