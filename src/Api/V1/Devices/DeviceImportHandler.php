<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Devices;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\Import\DeviceImport;
use FirefightingEquipment\Device\Import\DeviceImportFile;
use FirefightingEquipment\Device\Import\ImportFileException;
use FirefightingEquipment\Device\Import\ImportOutdatedException;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\Request;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * CSV-Import der Geräte (Admin), Datei als Base64 im JSON-Body (Feld "file"; die Route erlaubt
 * MAX_BODY_BYTES):
 *
 * - POST api/v1/devices/import/preview {file}: prüft und speichert nichts; Antwort siehe
 *   DeviceImportJson::preview(), mit "token" nur ohne Fehler.
 * - POST api/v1/devices/import {file, token, comment?}: prüft erneut und übernimmt alles in einer
 *   Transaktion; Antwort {"summary": …}. Fehler oder anderes Kennzeichen: 409 import_outdated.
 *
 * Fehler der ganzen Datei (nicht lesbar, Kopfzeile, Grenzen, abgebrochener Export): 400
 * invalid_request, ggf. mit details (z. B. {"line": 4}). Felder werden vor der Datei geprüft.
 */
final readonly class DeviceImportHandler implements RequestHandler
{
    /** Body-Grenze der Import-Routen: Datei (2 MiB) als Base64 plus JSON */
    public const int MAX_BODY_BYTES = Request::MAX_UPLOAD_BYTES;

    public function __construct(private bool $apply)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        DeviceFields::rejectUnknown($context, $this->apply ? ['file', 'token', 'comment'] : ['file']);
        $bytes = base64_decode($context->stringField('file'), true);
        if ($bytes === false) {
            throw HttpException::invalidRequest('Das Feld "file" muss die Datei als Base64 enthalten.');
        }

        try {
            $token = null;
            $comment = null;
            if ($this->apply) {
                $token = $context->stringField('token');
                if (preg_match('/^[0-9a-f]{64}\z/', $token) !== 1) {
                    throw HttpException::invalidRequest('Das Feld "token" muss das Kennzeichen aus der Vorschau enthalten.');
                }
                $comment = DeviceFields::comment($context);
            }

            $file = DeviceImportFile::parse($bytes);
            $import = new DeviceImport($context->db->get());
            if ($token === null) {
                return new JsonResponse(DeviceImportJson::preview($file, $import->preview($tenant, $file)));
            }

            $plan = $import->apply($tenant, $context->auth()->user->id, $file, $token, $comment);
        } catch (ImportFileException $e) {
            throw HttpException::invalidRequest($e->getMessage(), $e->details === [] ? null : $e->details);
        } catch (ImportOutdatedException $e) {
            throw HttpException::importOutdated(
                $e->getMessage(),
                $e->plan === null ? [] : ['summary' => DeviceImportJson::summary($e->plan)],
            );
        } catch (DeviceException $e) {
            throw DeviceErrors::http($e);
        }

        return new JsonResponse(['summary' => DeviceImportJson::summary($plan)]);
    }
}
