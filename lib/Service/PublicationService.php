<?php
/**
 * Decidesk Publication Service
 *
 * Orchestrates the public-publication flow: eligibility, payload construction,
 * setting the OpenRegister published-predicate (publicatiedatum), OpenCatalogi
 * routing, PublicationRecord lifecycle, and the source-object audit trail.
 * Covers publish, withdraw, and rectify.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/public-publication/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Decidesk\Exception\MissingObjectException;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateful orchestration of a publication action.
 *
 * Anonymous read of published data happens EXCLUSIVELY through OpenRegister's
 * RBAC published-predicate surface and OpenCatalogi — never through an app-local
 * route. "Publish" means setting `publicatiedatum` on the derived payload object
 * via the normal OR object API; the PublicationPayload schema's
 * `authorization.read` rule then grants the public group read access while
 * `publicatiedatum <= $now` (and no `depublicatiedatum` in the past). When
 * OpenCatalogi is absent the catalog step is skipped and the service degrades
 * gracefully with a staff-visible warning. It shares OriPublicationService's
 * graceful-degrade posture.
 *
 * @spec openspec/specs/public-publication/spec.md
 */
class PublicationService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface            $container        DI container (lazy ObjectService).
     * @param LoggerInterface               $logger           Logger.
     * @param IAppManager                   $appManager       Detects OpenCatalogi presence.
     * @param PublicationEligibilityService $eligibility      Eligibility + deny-list gates.
     * @param PublicationPayloadService     $payloadService   Allow-list payload builder.
     * @param PublicationConfigService      $configService    Per-body publication config.
     * @param OpenCatalogiPublisher         $catalogPublisher OpenCatalogi catalog routing.
     * @param AuditLogService               $auditLogService  Immutable audit trail.
     *
     * @spec openspec/specs/public-publication/spec.md
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly IAppManager $appManager,
        private readonly PublicationEligibilityService $eligibility,
        private readonly PublicationPayloadService $payloadService,
        private readonly PublicationConfigService $configService,
        private readonly OpenCatalogiPublisher $catalogPublisher,
        private readonly AuditLogService $auditLogService,
    ) {
    }//end __construct()

    /**
     * Publish an eligible governance object.
     *
     * Runs the deny-list + eligibility gates, builds the allow-list payload,
     * persists it as an immutable PublicationPayload, sets `publicatiedatum` so
     * the public-group RBAC rule makes it anonymously readable, routes into the
     * configured OpenCatalogi catalog, writes the PublicationRecord, and appends
     * a publish audit entry.
     *
     * @param string $sourceType One of decision|agenda|minutes.
     * @param string $sourceId   UUID of the source object.
     * @param string $actorId    Nextcloud UID of the publishing staff member.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return array<string,mixed> { record, warnings[] }
     */
    public function publish(string $sourceType, string $sourceId, string $actorId): array
    {
        $source = $this->eligibility->assertEligible($sourceType, $sourceId);
        $bodyId = $this->resolveBodyId(sourceType: $sourceType, source: $source);

        $version = 1;
        $payload = $this->payloadService->build($sourceType, $source, $bodyId, $version);

        // Set publicatiedatum on the payload so OR's public-group RBAC rule
        // (publicatiedatum <= $now) makes it anonymously readable through the
        // published-predicate surface. This is a normal field on a register-owned
        // object — written on the standard saveObject path, not a magic predicate.
        $payload['publicatiedatum']   = $this->now();
        $payload['depublicatiedatum'] = null;
        $payloadId = $this->persistPayload(payload: $payload);

        $warnings = [];

        // OpenCatalogi routing (create publication in the per-body target catalog).
        $catalogRef    = '';
        $targetCatalog = '';
        if ($bodyId !== null) {
            $targetCatalog = $this->configService->getForBody($bodyId)['catalog'];
        }

        if ($this->isOpenCatalogiAvailable() === true && $targetCatalog !== '') {
            $catalogRef = $this->catalogPublisher->publish($targetCatalog, $payloadId, $payload);
            if ($catalogRef === '') {
                $warnings[] = 'catalog-publish-failed';
            }
        } else {
            $warnings[] = 'opencatalogi-absent';
        }

        $record       = [
            'sourceType'              => $sourceType,
            'sourceObject'            => $sourceId,
            'sourceTitle'             => (string) ($source['title'] ?? ''),
            'governanceBody'          => ($bodyId ?? ''),
            'payloadObject'           => $payloadId,
            'payloadVersion'          => $version,
            'oriType'                 => (string) $payload['oriType'],
            'catalogPublication'      => $catalogRef,
            'targetCatalog'           => $targetCatalog,
            'status'                  => 'published',
            'catalogRetractionStatus' => 'none',
            'publishedBy'             => $actorId,
            'publishedAt'             => $this->now(),
        ];
        $recordId     = $this->persistRecord(record: $record);
        $record['id'] = $recordId;

        $this->markSourcePublished(sourceType: $sourceType, sourceId: $sourceId, source: $source, published: true);
        $this->audit(
            actorId: $actorId,
            action: 'publish',
            objectUids: [$sourceId, $payloadId],
            payload: ['sourceType' => $sourceType, 'payloadVersion' => $version]
        );

        return [
            'record'   => $record,
            'warnings' => $warnings,
        ];

    }//end publish()

    /**
     * Withdraw a publication with a mandatory reason.
     *
     * Sets `depublicatiedatum` on the payload (removing it from the public-group
     * RBAC surface), retracts the OpenCatalogi publication (surfacing + marking
     * pending on failure — never a silent success), resets the source object's
     * published state, records actor/reason/timestamp on the record and in the
     * audit trail, and soft-retains the payload.
     *
     * @param string $recordId UUID of the PublicationRecord.
     * @param string $actorId  Nextcloud UID of the withdrawing staff member.
     * @param string $reason   Mandatory withdraw reason.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @throws InvalidArgumentException When the reason is empty.
     * @throws MissingObjectException    When the record does not exist.
     *
     * @return array<string,mixed> { record, warnings[] }
     */
    public function withdraw(string $recordId, string $actorId, string $reason): array
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A withdraw reason is required.');
        }

        $record = $this->loadRecord(recordId: $recordId);

        $warnings = [];

        // Set depublicatiedatum on the payload first so the public-group RBAC
        // rule stops returning it (publicatiedatum <= $now is no longer the only
        // gate once depublicatiedatum is in the past) — the data stops being
        // anonymously readable even if the remote retraction fails.
        $this->setDepublicationDate(payloadId: (string) $record['payloadObject']);

        $catalogRetractionStatus = 'none';
        $catalogRef = (string) ($record['catalogPublication'] ?? '');
        if ($catalogRef !== '') {
            $retracted = $this->catalogPublisher->retract((string) ($record['targetCatalog'] ?? ''), $catalogRef);
            if ($retracted === true) {
                $catalogRetractionStatus = 'done';
            } else {
                // Surface the failure and mark pending — never report success.
                $catalogRetractionStatus = 'pending';
                $warnings[] = 'catalog-retraction-failed';
            }
        }

        $record['status']         = 'withdrawn';
        $record['withdrawnBy']    = $actorId;
        $record['withdrawnAt']    = $this->now();
        $record['withdrawReason'] = $reason;
        $record['catalogRetractionStatus'] = $catalogRetractionStatus;
        $this->persistRecord(record: $record, uuid: $recordId);

        $this->markSourcePublished(
            sourceType: (string) $record['sourceType'],
            sourceId: (string) $record['sourceObject'],
            source: null,
            published: false
        );
        $this->audit(
            actorId: $actorId,
            action: 'withdraw',
            objectUids: [(string) $record['sourceObject'], (string) $record['payloadObject']],
            payload: ['reason' => $reason]
        );

        return [
            'record'   => $record,
            'warnings' => $warnings,
        ];

    }//end withdraw()

    /**
     * Rectify a publication: publish a corrected new version and withdraw the
     * old one in the same operation. Published payloads are never edited in place.
     *
     * @param string $recordId UUID of the PublicationRecord to rectify.
     * @param string $actorId  Nextcloud UID of the rectifying staff member.
     * @param string $reason   Reason recorded against the withdrawn prior version.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @throws MissingObjectException When the prior record does not exist.
     *
     * @return array<string,mixed> { record, previous, warnings[] }
     */
    public function rectify(string $recordId, string $actorId, string $reason): array
    {
        $prior      = $this->loadRecord(recordId: $recordId);
        $sourceType = (string) $prior['sourceType'];
        $sourceId   = (string) $prior['sourceObject'];
        $newVersion = ((int) ($prior['payloadVersion'] ?? 1) + 1);

        // Re-validate eligibility for the corrected source state.
        $source = $this->eligibility->assertEligible($sourceType, $sourceId);
        $bodyId = $this->resolveBodyId(sourceType: $sourceType, source: $source);

        $payload = $this->payloadService->build($sourceType, $source, $bodyId, $newVersion);
        $payload['publicatiedatum']   = $this->now();
        $payload['depublicatiedatum'] = null;
        $payloadId = $this->persistPayload(payload: $payload);

        $warnings = [];

        $catalogRef    = '';
        $targetCatalog = (string) ($prior['targetCatalog'] ?? '');
        if ($this->isOpenCatalogiAvailable() === true && $targetCatalog !== '') {
            $catalogRef = $this->catalogPublisher->publish($targetCatalog, $payloadId, $payload);
            if ($catalogRef === '') {
                $warnings[] = 'catalog-publish-failed';
            }
        } else {
            $warnings[] = 'opencatalogi-absent';
        }

        $newRecord       = [
            'sourceType'              => $sourceType,
            'sourceObject'            => $sourceId,
            'sourceTitle'             => (string) ($source['title'] ?? ($prior['sourceTitle'] ?? '')),
            'governanceBody'          => ($bodyId ?? (string) ($prior['governanceBody'] ?? '')),
            'payloadObject'           => $payloadId,
            'payloadVersion'          => $newVersion,
            'oriType'                 => (string) $payload['oriType'],
            'catalogPublication'      => $catalogRef,
            'targetCatalog'           => $targetCatalog,
            'status'                  => 'published',
            'catalogRetractionStatus' => 'none',
            'rectifiesVersion'        => (int) ($prior['payloadVersion'] ?? 1),
            'publishedBy'             => $actorId,
            'publishedAt'             => $this->now(),
        ];
        $newRecordId     = $this->persistRecord(record: $newRecord);
        $newRecord['id'] = $newRecordId;

        // Withdraw the prior version in the same operation.
        $withdrawReason = $reason;
        if (trim($withdrawReason) === '') {
            $withdrawReason = 'Superseded by rectified version '.$newVersion;
        }

        $withdrawResult = $this->withdraw(recordId: $recordId, actorId: $actorId, reason: $withdrawReason);
        $warnings       = array_values(array_unique(array_merge($warnings, $withdrawResult['warnings'])));

        $this->markSourcePublished(sourceType: $sourceType, sourceId: $sourceId, source: $source, published: true);
        $rectifyDetail = [
            'rectifiesVersion' => (int) ($prior['payloadVersion'] ?? 1),
            'newVersion'       => $newVersion,
        ];
        $this->audit(actorId: $actorId, action: 'rectify', objectUids: [$sourceId, $payloadId], payload: $rectifyDetail);

        return [
            'record'   => $newRecord,
            'previous' => $withdrawResult['record'],
            'warnings' => $warnings,
        ];

    }//end rectify()

    /**
     * Set `depublicatiedatum` on a payload object so OR's public-group RBAC rule
     * stops returning it — the withdraw side of the published-predicate.
     *
     * This is a normal field write on a register-owned object via the standard
     * OR object API. There is no magic-mapper limitation: PublicationPayload is
     * a register-owned schema on the ordinary RBAC save path.
     *
     * @param string $payloadId UUID of the PublicationPayload object.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return void
     */
    private function setDepublicationDate(string $payloadId): void
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(id: $payloadId, register: 'decidesk', schema: 'publication-payload');
            if ($entity === null) {
                return;
            }

            $data = $entity->jsonSerialize();
            $data['depublicatiedatum'] = $this->now();

            $objectService->saveObject(
                object: $data,
                register: 'decidesk',
                schema: 'publication-payload',
                uuid: $payloadId,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Decidesk publication: failed to set depublicatiedatum on payload', ['exception' => $e->getMessage()]);
        }//end try

    }//end setDepublicationDate()

    /**
     * Persist a derived payload as an immutable PublicationPayload object.
     *
     * @param array<string,mixed> $payload The allow-list payload.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return string The created payload object UUID.
     */
    private function persistPayload(array $payload): string
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $saved         = $objectService->saveObject(object: $payload, register: 'decidesk', schema: 'publication-payload');

        return $this->extractId(saved: $saved);

    }//end persistPayload()

    /**
     * Persist (create or update) a PublicationRecord object.
     *
     * @param array<string,mixed> $record The record data.
     * @param string|null         $uuid   Existing UUID for an update, null to create.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return string The record object UUID.
     */
    private function persistRecord(array $record, ?string $uuid=null): string
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        if ($uuid !== null) {
            $saved = $objectService->saveObject(object: $record, register: 'decidesk', schema: 'publication-record', uuid: $uuid);
            return $uuid;
        }

        $saved = $objectService->saveObject(object: $record, register: 'decidesk', schema: 'publication-record');

        return $this->extractId(saved: $saved);

    }//end persistRecord()

    /**
     * Load a PublicationRecord by UUID.
     *
     * @param string $recordId The record UUID.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @throws MissingObjectException When the record does not exist.
     *
     * @return array<string,mixed>
     */
    private function loadRecord(string $recordId): array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $entity        = $objectService->find(id: $recordId, register: 'decidesk', schema: 'publication-record');
        if ($entity === null) {
            throw new MissingObjectException(message: 'Publication record not found: '.$recordId);
        }

        return $entity->jsonSerialize();

    }//end loadRecord()

    /**
     * Mark (or unmark) the source object's published state in the same write,
     * routed through the eligibility guard so the value is flow-owned.
     *
     * @param string                   $sourceType One of decision|agenda|minutes.
     * @param string                   $sourceId   UUID of the source object.
     * @param array<string,mixed>|null $source     Resolved source data (re-fetched when null).
     * @param bool                     $published  Whether the source becomes published.
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return void
     */
    private function markSourcePublished(string $sourceType, string $sourceId, ?array $source, bool $published): void
    {
        if ($sourceType !== 'decision') {
            // Only the Decision schema carries the isPublished/publishedAt fields.
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            if ($source === null) {
                $entity = $objectService->find(id: $sourceId, register: 'decidesk', schema: 'decision');
                if ($entity === null) {
                    return;
                }

                $source = $entity->jsonSerialize();
            }

            $publishedAt = null;
            $isPublished = 'internal';
            if ($published === true) {
                $isPublished = 'public';
                $publishedAt = $this->now();
            }

            $source['isPublished'] = $isPublished;
            $source['publishedAt'] = $publishedAt;

            $objectService->saveObject(object: $source, register: 'decidesk', schema: 'decision', uuid: $sourceId);
        } catch (\Throwable $e) {
            $this->logger->warning('Decidesk publication: failed to stamp source published state', ['exception' => $e->getMessage()]);
        }//end try

    }//end markSourcePublished()

    /**
     * Append an entry to the immutable decision audit trail.
     *
     * @param string   $actorId    Nextcloud UID of the actor.
     * @param string   $action     'publish'|'withdraw'|'rectify'.
     * @param string[] $objectUids Touched object UUIDs.
     * @param array    $payload    Structured detail.
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return void
     */
    private function audit(string $actorId, string $action, array $objectUids, array $payload): void
    {
        // The hash-chained audit trail enumerates its accepted actions; map the
        // publication actions onto the generic 'decision-transition' action and
        // carry the specific verb in the structured payload.
        $this->auditLogService->append(
            actor: $actorId,
            action: 'decision-transition',
            objectUids: $objectUids,
            payload: array_merge(['publicationAction' => $action], $payload),
        );

    }//end audit()

    /**
     * Resolve the governance body UUID for a source object.
     *
     * @param string              $sourceType One of decision|agenda|minutes.
     * @param array<string,mixed> $source     The source object data.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return string|null
     */
    private function resolveBodyId(string $sourceType, array $source): ?string
    {
        $direct = ($source['governanceBody'] ?? ($source['relations']['GovernanceBody'] ?? $source['relations']['governanceBody'] ?? null));
        if (is_array($direct) === true) {
            $direct = ($direct[0] ?? ($direct['id'] ?? null));
        }

        if (is_string($direct) === true && $direct !== '') {
            return $direct;
        }

        return null;

    }//end resolveBodyId()

    /**
     * Determine whether OpenCatalogi is installed.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return bool
     */
    private function isOpenCatalogiAvailable(): bool
    {
        return $this->appManager->isInstalled('opencatalogi');

    }//end isOpenCatalogiAvailable()

    /**
     * Extract a UUID from an ObjectService save result (object or array).
     *
     * @param mixed $saved The save result.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return string
     */
    private function extractId(mixed $saved): string
    {
        if (is_object($saved) === true) {
            if (method_exists($saved, 'getUuid') === true) {
                $uuid = $saved->getUuid();
                if (is_string($uuid) === true && $uuid !== '') {
                    return $uuid;
                }
            }

            if (method_exists($saved, 'jsonSerialize') === true) {
                $data = $saved->jsonSerialize();
                $id   = ($data['id'] ?? $data['uuid'] ?? ($data['@self']['id'] ?? null));
                if (is_string($id) === true && $id !== '') {
                    return $id;
                }
            }
        }

        if (is_array($saved) === true) {
            $id = ($saved['id'] ?? $saved['uuid'] ?? ($saved['@self']['id'] ?? null));
            if (is_string($id) === true && $id !== '') {
                return $id;
            }
        }

        return '';

    }//end extractId()

    /**
     * Current UTC timestamp in ATOM format.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return string
     */
    private function now(): string
    {
        return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

    }//end now()
}//end class
