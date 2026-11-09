<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Loans;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Api\V1\Devices\DeviceBulkHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceFields;
use FirefightingEquipment\Device\Booking\BookingOutcome;
use FirefightingEquipment\Device\DeviceDate;
use FirefightingEquipment\Device\DeviceEventRecorder;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DeviceStateChange;
use FirefightingEquipment\Device\DeviceText;
use FirefightingEquipment\Device\Loan\LoanRepository;
use FirefightingEquipment\Device\LocationRepository;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Notification\DeviceLogNotifier;

/**
 * POST api/v1/loans (Bereich `loans` Ändern, Phase 2, P2.5): verleiht Ausrüstung. Body {devices: [{id,
 * version}], borrower_location_id, contact?, due_back_on? (JJJJ-MM-TT), comment?}, höchstens
 * DeviceBulkHandler::MAX_DEVICES Einträge. Der Leihnehmer ist ein aktiver Standort mit der Aufgabe
 * „Ausgeliehen an“. Je Ausrüstung eine eigene Transaktion: Buchung auf den Standort des Leihnehmers
 * (Status bleibt, Verlauf „Umgebucht“) und der Eintrag des Verleihs gemeinsam; Teilerfolg erlaubt.
 * Schon verliehene, ausgesonderte und veraltete Einträge sind Fehler dieses Eintrags. Antwort wie die
 * Sammelaktionen der Liste (results, summary) plus `borrower`; danach eine Sammelmail „Log“.
 */
final readonly class LoanLendHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $user = $context->auth()->user;
        $pdo = $context->db->get();
        $recorder = new DeviceEventRecorder();
        $devices = new DeviceRepository($pdo, $context->visibility(), $recorder, $context->channel());
        $loans = new LoanRepository($pdo);

        $entries = [];
        $seen = [];
        try {
            foreach (DeviceFields::bulkEntries($context, DeviceBulkHandler::MAX_DEVICES, ['borrower_location_id', 'contact', 'due_back_on']) as $index => $entry) {
                try {
                    DeviceFields::rejectUnknown($entry, ['id', 'version']);
                    $id = DeviceFields::deviceId($entry);
                    $entries[] = [$id, DeviceFields::version($entry)];
                } catch (HttpException | DeviceException $e) {
                    throw HttpException::invalidRequest(sprintf('Eintrag %d in "devices": %s', $index + 1, $e->getMessage()));
                }
                if (isset($seen[$id])) {
                    throw HttpException::invalidRequest(sprintf('Die Ausrüstung #%d steht mehrfach in "devices".', $id));
                }
                $seen[$id] = true;
            }
            $borrowerId = $context->intField('borrower_location_id', 1, DeviceFields::MAX_ID);
            $contact = DeviceText::contact($context->optionalStringField('contact'));
            $dueValue = $context->optionalStringField('due_back_on');
            $due = $dueValue === null ? null : DeviceDate::fromString($dueValue, 'Das Datum der Rückgabe');
            $comment = DeviceFields::comment($context);
            $borrower = $loans->borrower($tenant, $borrowerId);
        } catch (DeviceException $e) {
            throw DeviceErrors::http($e);
        }

        $change = new DeviceStateChange(
            $borrower['id'],
            null,
            true,
            $loans->lending($tenant, $user->id, $borrower['id'], $contact, $due?->value, $comment),
        );

        $results = [];
        $changed = [];
        $counts = ['applied' => 0, 'unchanged' => 0, 'errors' => 0];
        foreach ($entries as [$id, $version]) {
            $recorder->reset();
            try {
                $result = $devices->book($tenant, $user->id, $id, $version, $change, $comment);
            } catch (HttpException | DeviceException $e) {
                $error = $e instanceof HttpException ? $e : DeviceErrors::http($e);
                $results[] = DeviceBulkHandler::result($id, 'error', false, $devices->find($tenant, $id), $error);
                ++$counts['errors'];
                continue;
            }
            if ($result->outcome !== BookingOutcome::Applied) {
                // Steht schon beim Leihnehmer, aber ohne Verleih: nichts gebucht, also auch nichts verliehen.
                $error = HttpException::ruleViolation(sprintf('%s steht schon bei „%s“.', $result->device->identifier->value, $borrower['name']));
                $results[] = DeviceBulkHandler::result($id, 'error', false, $result->device, $error);
                ++$counts['errors'];
                continue;
            }
            if ($recorder->ids() !== []) {
                $changed[] = [$result->device, $recorder->ids(), $recorder->before()];
            }
            $results[] = DeviceBulkHandler::result($id, 'applied', false, $result->device, null);
            ++$counts['applied'];
        }

        if ($changed !== []) {
            DeviceLogNotifier::fromContext($context)->bulkChanged($tenant, $user, $changed);
        }

        return new JsonResponse(['results' => $results, 'summary' => ['total' => count($results), ...$counts], 'borrower' => $borrower]);
    }
}
