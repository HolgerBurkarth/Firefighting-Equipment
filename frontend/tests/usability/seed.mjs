/* eslint-disable no-console -- Kommandozeilen-Werkzeug des Benutzertests */
// Benutzertest: Phase-2-Einrichtung über die REST-API der lokalen Testumgebung (Port aus FF_UT_PORT,
// Standard 4300). Reihenfolge: `php seed.php reset` → dieses Skript → `php seed.php users` (siehe reset.mjs).
// Nur erfundene Daten (Feuerwehr Musterstadt) gegen die Testdatenbank, nie gegen den Live-Server.
import { writeFileSync } from 'node:fs';

const PORT = process.env.FF_UT_PORT ?? '4300';
const ORIGIN = `http://localhost:${PORT}`;
const PASSWORD = 'Muster-Passwort-2026';

class Client {
    cookie = '';
    constructor(area) {
        this.base = `${ORIGIN}/firefighting/${area}/api/v1/`;
    }
    async call(method, path, body) {
        const res = await fetch(this.base + path, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-Client-Api': '3',
                Cookie: this.cookie,
            },
            body: method === 'GET' ? undefined : JSON.stringify(body ?? {}),
        });
        const set = res.headers.getSetCookie?.() ?? [];
        if (set.length > 0) this.cookie = set.map((c) => c.split(';')[0]).join('; ');
        const data = await res.json().catch(() => null);
        if (!res.ok) throw new Error(`${method} ${path} → ${res.status} ${JSON.stringify(data)}`);
        return data;
    }
    login(email) {
        return this.call('POST', 'auth/login', { email, password: PASSWORD });
    }
}

const sa = new Client('service');
await sa.login('super@example.org');
const tenants = await sa.call('GET', 'tenants');
const tid = tenants.tenants.find((t) => t.slug === 'test').id;

await sa.call('POST', `tenants/${tid}/location-tasks`, {
    tasks: ['store', 'collection', 'workshop', 'returns', 'borrower'].map((task) => ({
        task,
        enabled: true,
    })),
});

const keeperAreas = {
    dashboard: 'read',
    search: 'read',
    devices: 'write',
    locations: 'write',
    device_types: 'write',
    due: 'write',
    log: 'write',
    maintenance: 'write',
    loans: 'write',
};
const keeperActions = [
    'book.move',
    'book.status',
    'device.used',
    'device.swap',
    'device.create',
    'device.edit',
    'device.code',
    'devices.bulk',
    'csv.import',
    'csv.export',
    'mail.log',
];
await sa.call('POST', `tenants/${tid}/roles`, {
    name: 'Gerätewart',
    description: 'Pflegt Ausrüstung, Wartung und Verleih',
    areas: keeperAreas,
    actions: keeperActions,
    locations: null,
    device_types: null,
});
await sa.call('POST', `tenants/${tid}/roles`, {
    name: 'Geräteträger',
    description: 'Bucht um, meldet benutzt und tauscht',
    areas: { search: 'read', due: 'read' },
    actions: ['book.move', 'book.status', 'device.used', 'device.swap'],
    locations: null,
    device_types: null,
    restrictions: [],
});

const ad = new Client('test');
await ad.login('anna.beispiel@example.org');
const loc = async (name, kind) => (await ad.call('POST', 'locations', { name, kind })).location.id;
const all = (await ad.call('GET', 'locations')).locations;
const byName = (n) => all.find((l) => l.name === n)?.id;
const store = byName('Lager Gerätehaus');
const workshop = byName('Atemschutzwerkstatt');
const collection = await loc('Sammelstelle', 'room');
const returns = await loc('Rückgabe-Regal', 'room');
const nachbar = await loc('FF Nachbarort', 'external');
const bauhof = await loc('Bauhof Musterstadt', 'external');
await ad.call('POST', `locations/${store}/task`, { task: 'store', is_default_store: true });
await ad.call('POST', `locations/${collection}/task`, {
    task: 'collection',
    collection_accepts: 'both',
});
await ad.call('POST', `locations/${workshop}/task`, { task: 'workshop' });
await ad.call('POST', `locations/${returns}/task`, { task: 'returns' });
await ad.call('POST', `locations/${nachbar}/task`, { task: 'borrower' });
await ad.call('POST', `locations/${bauhof}/task`, { task: 'borrower' });

await ad.call('POST', 'invitation-codes', {
    label: 'Dienstabend November',
    valid_days: 30,
    max_uses: 50,
});

// Einmalgerät: Atemschutzfilter F 1–F 5 im Lager
const typeId = (await ad.call('POST', 'device-types', { name: 'Atemschutzfilter' })).device_type.id;
await ad.call('POST', `device-types/${typeId}/single-use`, { single_use: true });
for (let n = 1; n <= 5; n++) {
    await ad.call('POST', 'devices', {
        identifier: `F ${n}`,
        device_type_id: typeId,
        location_id: store,
        status: 'ready',
        manufacturer: 'Dräger',
        model: 'X-plore Filter',
    });
}

// Sollbestand HLF 20: mindestens 7 Atemschutzmasken
const list = (await ad.call('GET', 'devices?limit=200')).devices;
const dev = (ident) => list.find((d) => d.identifier === ident);
const cur = async (ident) => (await ad.call('GET', `devices/${dev(ident).id}`)).device;
const hlf = byName('HLF 20');
await ad.call('POST', `locations/${hlf}/stock-rules`, {
    rules: [{ min_count: 7, device_type_ids: [dev('M1').device_type.id] }],
});

// benutzte Ausrüstung in der Sammelstelle
for (const ident of ['M10', 'M11', 'HRT 7']) {
    const d = await cur(ident);
    await ad.call('POST', `devices/${d.id}/used`, { version: d.version, comment: 'Übung' });
}

// Verleih: HRT 13/14, 16/17, 18/19 an FF Nachbarort (je ein Paar für einen Boten), HRT 12 an Bauhof, HRT 20 kommt in die Rückgabe
const lend = async (idents, to) => {
    const devices = [];
    for (const ident of idents)
        devices.push({ id: dev(ident).id, version: (await cur(ident)).version });
    await ad.call('POST', 'loans', {
        devices,
        borrower_location_id: to,
        contact: 'Ansprechpartner Beispiel',
        due_back_on: '2026-11-20',
    });
};
await lend(['HRT 13', 'HRT 14', 'HRT 16', 'HRT 17', 'HRT 18', 'HRT 19'], nachbar);
await lend(['HRT 12'], bauhof);
await lend(['HRT 20'], nachbar);
const overview = await ad.call('GET', 'loans');
const points = overview.returns ?? overview.return_points ?? [];
const rpId = points[0]?.id ?? points[0]?.location?.id;
if (rpId === undefined)
    throw new Error('Keine Rückgabestelle: ' + JSON.stringify(Object.keys(overview)));
const renewed = await ad.call('POST', `return-points/${rpId}/renew`, {});
writeFileSync(
    new URL('./state-return-link.txt', import.meta.url),
    `${ORIGIN}/firefighting/test/rueckgabe#schluessel=${renewed.key}\n`,
);

// HRT 20 per öffentlicher Rückgabe in die Rückgabe bringen
const ret = await fetch(`${ORIGIN}/firefighting/test/api/v1/returns`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        key: renewed.key,
        code: 'HRT 20',
        name: 'Bernd Bauhof',
        condition: 'used',
        comment: 'Akku leer',
    }),
});
if (!ret.ok) throw new Error('returns ' + ret.status + ' ' + (await ret.text()));

// offener Hinweis: Offline-Buchung, die älter ist als der aktuelle Stand
try {
    const d = await cur('WBK 3');
    await ad.call('POST', `devices/${d.id}/state`, {
        version: d.version,
        status: 'maintenance',
        comment: 'Linse beschlagen',
    });
    await ad.call('POST', `devices/${d.id}/state`, {
        version: d.version,
        status: 'defective',
        comment: 'Sprung im Glas',
        client_ref: crypto.randomUUID(),
        occurred_at: new Date(Date.now() - 3600_000).toISOString().replace(/\.\d+Z$/, 'Z'),
    });
} catch (e) {
    console.log('Hinweis nicht angelegt:', e.message);
}
console.log('seed.mjs ok; Rückgabe-Link in state-return-link.txt');
