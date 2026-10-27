/** Gesendete Anfrage eines simulierten fetch. */
export interface RecordedRequest {
    readonly url: string;
    readonly method: string;
    readonly headers: Readonly<Record<string, string>>;
    readonly body: unknown;
}

/**
 * Simuliertes fetch mit fester Antwort; `requests` sammelt die Anfragen. `body` undefined = leere
 * Antwort, ein String wird unverändert gesendet.
 */
export function respond(
    status: number,
    body: unknown,
    headers: Record<string, string> = {},
): { fetch: typeof fetch; requests: RecordedRequest[] } {
    const requests: RecordedRequest[] = [];
    const fetchFn: typeof fetch = (input, init) => {
        const sent = init?.body;
        requests.push({
            url: input instanceof Request ? input.url : String(input),
            method: init?.method ?? 'GET',
            headers: { ...(init?.headers as Record<string, string> | undefined) },
            body: typeof sent === 'string' ? (JSON.parse(sent) as unknown) : undefined,
        });
        let text: string | null = null;
        if (typeof body === 'string') {
            text = body;
        } else if (body !== undefined) {
            text = JSON.stringify(body);
        }
        return Promise.resolve(
            new Response(text, {
                status,
                headers: { 'Content-Type': 'application/json', ...headers },
            }),
        );
    };
    return { fetch: fetchFn, requests };
}
