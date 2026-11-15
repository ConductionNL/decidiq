<?php

/**
 * Decidiq Publication Service
 *
 * Orchestrates the public-publication flow: eligibility, payload construction,
 * setting the OpenRegister published-predicate (publicationDate), OpenCatalogi
 * routing, PublicationRecord lifecycle, and the source-object audit trail.
 * Covers publish, withdraw, and rectify.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
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
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Kernel\Contracts\PublicationServiceInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Stateful orchestration of a publication action.
 *
 * Anonymous read of published data happens EXCLUSIVELY through OpenRegister's
 * RBAC published-predicate surface and OpenCatalogi — never through an app-local
 * route. "Publish" means setting `publicationDate` on the derived payload object
 * via the normal OR object API; the PublicationPayload schema's
 * `authorization.read` rule then grants the public group read access while
 * `publicationDate <= $now` (and no `depublicationDate` in the past). When
 * OpenCatalogi is absent the catalog step is skipped and the service degrades
 * gracefully with a staff-visible warning. It shares OriPublicationService's
 * graceful-degrade posture.
 *
 * @spec openspec/specs/public-publication/spec.md
 */
class PublicationService implements PublicationServiceInterface {

	/**
	 * OpenRegister persistence gateway for the publication flow.
	 *
	 * @var PublicationRepository
	 */
	private readonly PublicationRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger, handed to the repository.
	 * @param IAppManager $appManager Detects OpenCatalogi presence.
	 * @param PublicationEligibilityService $eligibility Eligibility + deny-list gates.
	 * @param PublicationPayloadService $payloadService Allow-list payload builder.
	 * @param PublicationConfigService $configService Per-body publication config.
	 * @param OpenCatalogiPublisher $catalogPublisher OpenCatalogi catalog routing.
	 * @param AuditLogService $auditLogService Immutable audit trail.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service, handed to the repository.
	 * @param PublicationEventRecorder $eventRecorder Records the publication for subscribers
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	public function __construct(
		LoggerInterface $logger,
		private readonly IAppManager $appManager,
		private readonly PublicationEligibilityService $eligibility,
		private readonly PublicationPayloadService $payloadService,
		private readonly PublicationConfigService $configService,
		private readonly OpenCatalogiPublisher $catalogPublisher,
		private readonly AuditLogService $auditLogService,
		ObjectServiceInterface $objectService,
		private readonly PublicationEventRecorder $eventRecorder,
	) {
		$this->repository = new PublicationRepository(logger: $logger, objectService: $objectService);

	}//end __construct()

	/**
	 * Publish an eligible governance object.
	 *
	 * Runs the deny-list + eligibility gates, builds the allow-list payload,
	 * persists it as an immutable PublicationPayload, sets `publicationDate` so
	 * the public-group RBAC rule makes it anonymously readable, routes into the
	 * configured OpenCatalogi catalog, writes the PublicationRecord, and appends
	 * a publish audit entry.
	 *
	 * @param string $sourceType One of decision|agenda|minutes.
	 * @param string $sourceId UUID of the source object.
	 * @param string $actorId Nextcloud UID of the publishing staff member.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return array<string,mixed> Keys: `record` (the PublicationRecord) and `warnings` (string[]).
	 */
	public function publish(string $sourceType, string $sourceId, string $actorId): array {
		$run = new PublicationRun(sourceType: $sourceType, sourceId: $sourceId);
		$run->actorId = $actorId;
		$this->loadEligibleSource(run: $run);
		$this->persistPublicPayload(run: $run);
		$run->targetCatalog = $this->targetCatalogOf(bodyId: $run->bodyId);
		$this->routeToCatalog(run: $run);
		$record = $this->persistPublicationRecord(run: $run, prior: []);
		$this->markPublished(run: $run);
		$this->audit(
			actorId: $actorId,
			action: 'publish',
			objectUids: [$sourceId, $run->payloadId],
			payload: ['sourceType' => $sourceType, 'payloadVersion' => $run->version]
		);
		$this->eventRecorder->published(sourceType: $sourceType, sourceId: $sourceId, source: $run->source, bodyId: $run->bodyId);

		return [
			'record' => $record,
			'warnings' => $run->warnings,
		];

	}//end publish()

	/**
	 * Check the source is eligible and read its governance body.
	 *
	 * @param PublicationRun $run The publication.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function loadEligibleSource(PublicationRun $run): void {
		$run->source = $this->eligibility->assertEligible($run->sourceType, $run->sourceId);
		$run->bodyId = $this->resolveBodyId(source: $run->source);
	}//end loadEligibleSource()

	/**
	 * Build the allow-list payload and persist it as public from now.
	 *
	 * @param PublicationRun $run The publication.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function persistPublicPayload(PublicationRun $run): void {
		$payload = $this->payloadService->build($run->sourceType, $run->source, $run->bodyId, $run->version);
		$run->papers = ($payload['_publishedPapers'] ?? []);
		unset($payload['_publishedPapers']);

		// Set publicationDate on the payload so OR's public-group RBAC rule
		// (publicationDate <= $now) makes it anonymously readable through the
		// published-predicate surface. This is a normal field on a register-owned
		// object — written on the standard saveObject path, not a magic predicate.
		$run->publishedAt = $this->now();
		$payload['publicationDate'] = $run->publishedAt;
		// No depublicationDate key: the schema types it as a date-time string that
		// is not nullable, so an explicit null fails the register's validation.
		// Absent means not withdrawn; withdraw() sets it.
		unset($payload['depublicationDate']);
		$run->payload = $payload;
		$run->payloadId = $this->repository->persistPayload(payload: $payload);
	}//end persistPublicPayload()

	/**
	 * The OpenCatalogi catalog configured for a body, or '' without a body.
	 *
	 * @param string|null $bodyId The governance body.
	 *
	 * @return string The catalog.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function targetCatalogOf(?string $bodyId): string {
		if ($bodyId === null) {
			return '';
		}

		return $this->configService->getForBody($bodyId)['catalog'];
	}//end targetCatalogOf()

	/**
	 * Create the publication in the target catalog, or warn why it was not.
	 *
	 * @param PublicationRun $run The publication.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function routeToCatalog(PublicationRun $run): void {
		$catalogAvailable = ($this->isOpenCatalogiAvailable() === true && $run->targetCatalog !== '');
		if ($catalogAvailable === false) {
			$run->warnings[] = 'opencatalogi-absent';
			return;
		}

		$run->catalogRef = $this->catalogPublisher->publish($run->targetCatalog, $run->payloadId, $run->payload);
		if ($run->catalogRef === '') {
			$run->warnings[] = 'catalog-publish-failed';
		}
	}//end routeToCatalog()

	/**
	 * Write the PublicationRecord of a run.
	 *
	 * @param PublicationRun       $run   The publication.
	 * @param array<string, mixed> $prior The record a rectification replaces, or [].
	 *
	 * @return array<string, mixed> The record, with its id.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function persistPublicationRecord(PublicationRun $run, array $prior): array {
		$record = $this->publicationRecord(run: $run, prior: $prior);
		$recordId = $this->repository->persistRecord(record: $record);
		$record['id'] = $recordId;
		return $record;
	}//end persistPublicationRecord()

	/**
	 * The PublicationRecord of a run; a rectification falls back to the prior
	 * record's title and body and names the version it rectifies.
	 *
	 * @param PublicationRun       $run   The publication.
	 * @param array<string, mixed> $prior The record a rectification replaces, or [].
	 *
	 * @return array<string, mixed> The record.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function publicationRecord(PublicationRun $run, array $prior): array {
		return [
			'sourceType' => $run->sourceType,
			'sourceObject' => $run->sourceId,
			'sourceTitle' => (string)($run->source['title'] ?? ($prior['sourceTitle'] ?? '')),
			'governanceBody' => ($run->bodyId ?? (string)($prior['governanceBody'] ?? '')),
			'payloadObject' => $run->payloadId,
			'payloadVersion' => $run->version,
			'oriType' => (string)$run->payload['oriType'],
			'catalogPublication' => $run->catalogRef,
			'targetCatalog' => $run->targetCatalog,
			'status' => 'published',
			'catalogRetractionStatus' => 'none',
		] + $this->rectifiedVersion(prior: $prior) + [
			'publishedBy' => $run->actorId,
			'publishedAt' => $run->publishedAt,
			'publishedPapers' => $run->papers,
		];
	}//end publicationRecord()

	/**
	 * The `rectifiesVersion` field of a rectification, none for a first publication.
	 *
	 * @param array<string, mixed> $prior The record a rectification replaces, or [].
	 *
	 * @return array<string, int> The field, or [].
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function rectifiedVersion(array $prior): array {
		if ($prior === []) {
			return [];
		}

		return ['rectifiesVersion' => (int)($prior['payloadVersion'] ?? 1)];
	}//end rectifiedVersion()

	/**
	 * Mark the run's source object as published at the run's moment.
	 *
	 * @param PublicationRun $run The publication.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function markPublished(PublicationRun $run): void {
		$this->repository->markSourcePublished(
			sourceType: $run->sourceType,
			sourceId: $run->sourceId,
			source: $run->source,
			publishedAt: $run->publishedAt
		);
	}//end markPublished()

	/**
	 * Withdraw a publication with a mandatory reason.
	 *
	 * Sets `depublicationDate` on the payload (removing it from the public-group
	 * RBAC surface), retracts the OpenCatalogi publication (surfacing + marking
	 * pending on failure — never a silent success), resets the source object's
	 * published state, records actor/reason/timestamp on the record and in the
	 * audit trail, and soft-retains the payload.
	 *
	 * @param string $recordId UUID of the PublicationRecord.
	 * @param string $actorId Nextcloud UID of the withdrawing staff member.
	 * @param string $reason Mandatory withdraw reason.
	 * @param array<int,mixed> $keepPapers Papers a rectified version still publishes.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-pps-001-public-papers-are-published-with-the-agenda
	 *
	 * @throws InvalidArgumentException When the reason is empty.
	 * @throws MissingObjectException When the record does not exist.
	 *
	 * @return array<string,mixed> Keys: `record` (the PublicationRecord) and `warnings` (string[]).
	 */
	public function withdraw(string $recordId, string $actorId, string $reason, array $keepPapers=[]): array {
		if (trim($reason) === '') {
			throw new InvalidArgumentException('A withdraw reason is required.');
		}

		$record = $this->repository->loadRecord(recordId: $recordId);

		$this->depublishPayload(record: $record);
		$retractionStatus = $this->retractFromCatalog(catalogRef: (string)($record['catalogPublication'] ?? ''));
		// The papers this publication made public go offline with it.
		$papersLeftOnline = $this->payloadService->withdrawPapers(
			refs: (array)($record['publishedPapers'] ?? []),
			keep: $keepPapers
		);
		$warnings = $this->withdrawalWarnings(retractionStatus: $retractionStatus, papersLeftOnline: $papersLeftOnline);

		$record['status'] = 'withdrawn';
		$record['withdrawnBy'] = $actorId;
		$record['withdrawnAt'] = $this->now();
		$record['withdrawReason'] = $reason;
		$record['catalogRetractionStatus'] = $retractionStatus;
		$this->repository->persistRecord(record: $record, uuid: $recordId);

		$this->markUnpublished(record: $record);
		$this->audit(
			actorId: $actorId,
			action: 'withdraw',
			objectUids: [(string)$record['sourceObject'], (string)$record['payloadObject']],
			payload: ['reason' => $reason]
		);

		return [
			'record' => $record,
			'warnings' => $warnings,
		];

	}//end withdraw()

	/**
	 * Take a record's payload off the public surface from now.
	 *
	 * Set depublicationDate on the payload first so the public-group RBAC
	 * rule stops returning it (publicationDate <= $now is no longer the only
	 * gate once depublicationDate is in the past) — the data stops being
	 * anonymously readable even if the remote retraction fails.
	 *
	 * @param array<string, mixed> $record The PublicationRecord.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function depublishPayload(array $record): void {
		$this->repository->setDepublicationDate(
			payloadId: (string)$record['payloadObject'],
			timestamp: $this->now()
		);
	}//end depublishPayload()

	/**
	 * What a withdrawal reports: a retraction the catalog did not confirm
	 * (never reported as success), then papers still online.
	 *
	 * @param string $retractionStatus none|done|pending.
	 * @param int    $papersLeftOnline Papers that could not be taken offline.
	 *
	 * @return list<string> The warnings.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function withdrawalWarnings(string $retractionStatus, int $papersLeftOnline): array {
		$warnings = [];
		if ($retractionStatus === 'pending') {
			$warnings[] = 'catalog-retraction-failed';
		}

		if ($papersLeftOnline > 0) {
			$warnings[] = 'papers-still-public';
		}

		return $warnings;
	}//end withdrawalWarnings()

	/**
	 * Retract a catalog publication: none without one, done when the catalog
	 * confirms, pending when it does not.
	 *
	 * @param string $catalogRef The catalog publication, or ''.
	 *
	 * @return string none|done|pending.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function retractFromCatalog(string $catalogRef): string {
		if ($catalogRef === '') {
			return 'none';
		}

		if ($this->catalogPublisher->retract($catalogRef) !== true) {
			return 'pending';
		}

		return 'done';
	}//end retractFromCatalog()

	/**
	 * Reset the published state of a withdrawn record's source object.
	 *
	 * @param array<string, mixed> $record The withdrawn PublicationRecord.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function markUnpublished(array $record): void {
		$this->repository->markSourcePublished(
			sourceType: (string)$record['sourceType'],
			sourceId: (string)$record['sourceObject'],
			source: null,
			publishedAt: null
		);
	}//end markUnpublished()

	/**
	 * Rectify a publication: publish a corrected new version and withdraw the
	 * old one in the same operation. Published payloads are never edited in place.
	 *
	 * @param string $recordId UUID of the PublicationRecord to rectify.
	 * @param string $actorId Nextcloud UID of the rectifying staff member.
	 * @param string $reason Reason recorded against the withdrawn prior version.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @throws MissingObjectException When the prior record does not exist.
	 *
	 * @return array<string,mixed> Keys: `record` (new), `previous` (withdrawn) and `warnings` (string[]).
	 */
	public function rectify(string $recordId, string $actorId, string $reason): array {
		$prior = $this->repository->loadRecord(recordId: $recordId);
		$run = new PublicationRun(sourceType: (string)$prior['sourceType'], sourceId: (string)$prior['sourceObject']);
		$run->actorId = $actorId;
		$run->version = ((int)($prior['payloadVersion'] ?? 1) + 1);

		// Re-validate eligibility for the corrected source state.
		$this->loadEligibleSource(run: $run);
		$this->persistPublicPayload(run: $run);
		$run->targetCatalog = (string)($prior['targetCatalog'] ?? '');
		$this->routeToCatalog(run: $run);
		$newRecord = $this->persistPublicationRecord(run: $run, prior: $prior);

		// Withdraw the prior version in the same operation.
		$withdrawResult = $this->withdraw(
			recordId: $recordId,
			actorId: $actorId,
			reason: $this->supersededReason(reason: $reason, run: $run),
			keepPapers: $run->papers
		);
		$warnings = array_values(array_unique(array_merge($run->warnings, $withdrawResult['warnings'])));

		$this->markPublished(run: $run);
		$rectifyDetail = [
			'rectifiesVersion' => (int)($prior['payloadVersion'] ?? 1),
			'newVersion' => $run->version,
		];
		$this->audit(actorId: $actorId, action: 'rectify', objectUids: [$run->sourceId, $run->payloadId], payload: $rectifyDetail);

		return [
			'record' => $newRecord,
			'previous' => $withdrawResult['record'],
			'warnings' => $warnings,
		];

	}//end rectify()

	/**
	 * The reason recorded against the version a rectification withdraws.
	 *
	 * @param string         $reason The reason the staff member gave, maybe blank.
	 * @param PublicationRun $run    The rectifying publication.
	 *
	 * @return string The reason, or the superseded note when it was blank.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function supersededReason(string $reason, PublicationRun $run): string {
		if (trim($reason) === '') {
			return 'Superseded by rectified version ' . $run->version;
		}

		return $reason;
	}//end supersededReason()

	/**
	 * Append an entry to the immutable decision audit trail.
	 *
	 * @param string $actorId Nextcloud UID of the actor.
	 * @param string $action 'publish'|'withdraw'|'rectify'.
	 * @param string[] $objectUids Touched object UUIDs.
	 * @param array $payload Structured detail.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	private function audit(string $actorId, string $action, array $objectUids, array $payload): void {
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
	 * @param array<string,mixed> $source The source object data.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return string|null
	 */
	private function resolveBodyId(array $source): ?string {
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
	private function isOpenCatalogiAvailable(): bool {
		return $this->appManager->isEnabledForAnyone('opencatalogi');
	}//end isOpenCatalogiAvailable()

	/**
	 * Current UTC timestamp in ATOM format.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return string
	 */
	private function now(): string {
		return (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);
	}//end now()
}//end class
