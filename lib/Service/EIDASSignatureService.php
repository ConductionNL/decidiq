<?php

/**
 * Decidiq eIDAS Signature Service
 *
 * The domain app decides who signs and when; filinq performs the signature on
 * the fleet's signing rail (hydra ADR-125). This service hands a record to
 * filinq by dispatching filinq's `DocumentSigningRequestedEvent` in process,
 * under the requesting user, and reads the signing request id back off the
 * same event. filinq reports the outcome later with `SigningConcludedEvent`,
 * which {@see \OCA\Decidiq\Listener\SigningConcludedListener} handles.
 *
 * There is no HTTP Source hop any more: the `docudesk-signing` and `eidas-qes`
 * integriq Sources were never configured and integriq ships no e-sign
 * adapter, so that path was dark. Without filinq, every method here fails
 * closed: nothing is sent, verified or marked signed.
 *
 * Retargeted onto the unified `minutes` / `decision` entities (ADR-006).
 * C5 (decision-methods) wires the "signature" decision method: when filinq
 * reports a request signed, {@see self::resolveSignatureStage()} resolves the
 * method=signature DecisionStage (outcome=adopted + decidedAt + the signed
 * document linked).
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Kernel\Contracts\SigningRoundServiceInterface;
use OCA\Decidiq\Support\FleetAppId;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Signs through filinq's signing rail.
 *
 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-2
 * @spec openspec/changes/archive/2026-06-14-decision-methods/tasks.md#4-eidas-signature-method-wiring-code
 */
class EIDASSignatureService implements IEIDASSignatureService {

	/**
	 * filinq's request event, relative to its namespace.
	 *
	 * @var string
	 */
	public const REQUEST_EVENT = 'Event\DocumentSigningRequestedEvent';

	/**
	 * filinq's provenance value object, relative to its namespace.
	 *
	 * @var string
	 */
	public const PROVENANCE = 'Event\SigningProvenance';

	/**
	 * The source app filinq echoes on its conclusion event.
	 *
	 * @var string
	 */
	public const SOURCE_APP = 'decidiq';

	/**
	 * The answer of every check filinq performs instead of decidiq.
	 *
	 * @var string
	 */
	private const FILINQ_CHECKS = 'filinq checks signatures on its signing rail; decidiq does not.';

	/**
	 * Construct the eIDAS service.
	 *
	 * @param ContainerInterface     $container       DI container (builds the request payload)
	 * @param LoggerInterface        $logger          Logger
	 * @param AuditLogService        $auditLogService Audit log dependency
	 * @param ObjectServiceInterface $objectService   The OpenRegister object service
	 * @param IEventDispatcher       $dispatcher      Dispatches filinq's request event
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-2
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly AuditLogService $auditLogService,
		private readonly ObjectServiceInterface $objectService,
		private readonly IEventDispatcher $dispatcher,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * Dispatches filinq's `DocumentSigningRequestedEvent` with the record as
	 * subject, the PDF's file id, the signatories in order and the signature
	 * level, and reads the signing request id back off the event. The id is
	 * stored on the method=signature stages that wait on this record, so the
	 * conclusion is matched on the request. Without filinq, or when filinq
	 * does not handle the request, it answers `success: false` (fail closed).
	 *
	 * @param string        $minutesId   UUID of the record to sign (minutes, meeting or decision)
	 * @param array<string> $signatories Ordered list of member (Person) UUIDs
	 * @param string        $subjectType What is signed: minutes, decision-list or motion
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-2
	 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
	 *
	 * @return array{success: bool, requestId: ?string, signingUrl: ?string, message: string}
	 */
	public function initializeSigningRequest(string $minutesId, array $signatories, string $subjectType = 'minutes'): array {
		if ($minutesId === '' || $signatories === []) {
			return $this->refused(message: 'minutesId and at least one signatory are required.');
		}

		[$requestId, $message] = $this->handToFilinq(minutesId: $minutesId, signatories: $signatories, subjectType: $subjectType);
		if ($requestId === null) {
			return $this->refused(message: $message);
		}

		$this->markStagesSent(minutesId: $minutesId, requestId: $requestId);

		$this->auditLogService->append(
			actor: 'system',
			action: 'signature',
			objectUids: [$minutesId, $requestId],
			payload: ['phase' => 'initiate', 'signer' => 'filinq', 'signatories' => array_values($signatories)]
		);

		return [
			'success' => true,
			'requestId' => $requestId,
			'signingUrl' => null,
			'message' => 'Sent to filinq for signature.',
		];

	}//end initializeSigningRequest()

	/**
	 * Dispatch filinq's request event for the record and read the answer.
	 *
	 * @param string        $minutesId   The record's UUID
	 * @param array<string> $signatories Ordered member UUIDs
	 * @param string        $subjectType minutes, decision-list or motion
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-2
	 *
	 * @return array{0: ?string, 1: string} The request id (null when nothing was sent) and why not.
	 */
	private function handToFilinq(string $minutesId, array $signatories, string $subjectType): array {
		$eventClass      = $this->filinqClass(relative: self::REQUEST_EVENT);
		$provenanceClass = $this->filinqClass(relative: self::PROVENANCE);
		if ($eventClass === null || $provenanceClass === null) {
			return [null, 'filinq is not installed, so nothing was sent for signature.'];
		}

		try {
			$event = $this->requestEvent(
				classes: [$eventClass, $provenanceClass],
				subject: ['id' => $minutesId, 'type' => $subjectType],
				signatories: $signatories
			);
			$this->dispatcher->dispatchTyped($event);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: the signing request could not be handed to filinq',
				['minutesId' => $minutesId, 'exception' => $e->getMessage()]
			);
			return [null, 'Failed to initialize signing request: ' . $e->getMessage()];
		}

		return $this->answerOf(event: $event);
	}//end handToFilinq()

	/**
	 * What filinq wrote back on the request event: the request id, or why
	 * nothing was sent.
	 *
	 * @param object $event filinq's DocumentSigningRequestedEvent, after dispatch
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-2
	 *
	 * @return array{0: ?string, 1: string}
	 */
	private function answerOf(object $event): array {
		$requestId = (string)$event->getSigningRequestId();
		if ($event->isHandled() !== true || $requestId === '') {
			return [null, (string)($event->getRefusal() ?? 'filinq did not take the signing request, so nothing was sent for signature.')];
		}

		return [$requestId, ''];
	}//end answerOf()

	/**
	 * Build filinq's request event: the record as subject, its PDF, the
	 * signers in order and the signature level.
	 *
	 * @param array{0: string, 1: string}          $classes     filinq's event and provenance classes
	 * @param array{id: string, type: string}      $subject     The record's UUID and subject type
	 * @param array<string>                        $signatories Ordered member UUIDs
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-2
	 *
	 * @return object filinq's DocumentSigningRequestedEvent
	 */
	private function requestEvent(array $classes, array $subject, array $signatories): object {
		[$eventClass, $provenanceClass] = $classes;

		$payload = $this->container->get(FilinqSigningRequest::class)->payload(
			subjectType: $subject['type'],
			subjectId: $subject['id'],
			signatories: array_values(array_map('strval', $signatories))
		);

		return new $eventClass(
			provenance: new $provenanceClass(
				sourceApp: self::SOURCE_APP,
				subjectRegister: 'decidiq',
				subjectSchema: (SigningRoundServiceInterface::SUBJECTS[$subject['type']] ?? $subject['type']),
				subjectId: $subject['id'],
				externalReference: $subject['type'] . '/' . $subject['id'],
				correlationId: $subject['id'],
			),
			subjectLabel: (string)$payload['documentName'],
			documentReference: (string)$payload['documentFileId'],
			signers: $payload['signers'],
			signatureLevel: (string)$payload['signatureLevel'],
			signingMode: (string)$payload['signingMode'],
		);
	}//end requestEvent()

	/**
	 * The loadable class of filinq's, under any namespace filinq has shipped
	 * under, or null when filinq is not there.
	 *
	 * @param string $relative The class name relative to filinq's namespace.
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-2
	 *
	 * @return string|null
	 */
	protected function filinqClass(string $relative): ?string {
		return FleetAppId::resolveClass(canonical: 'filinq', relative: $relative);
	}//end filinqClass()

	/**
	 * The fail-closed answer of initializeSigningRequest().
	 *
	 * @param string $message Why nothing was sent.
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-2
	 *
	 * @return array{success: bool, requestId: ?string, signingUrl: ?string, message: string}
	 */
	private function refused(string $message): array {
		return [
			'success' => false,
			'requestId' => null,
			'signingUrl' => null,
			'message' => $message,
		];
	}//end refused()

	/**
	 * Name the signing request on every method=signature stage that waits on
	 * this record. A failed write is logged; the request itself stands.
	 *
	 * @param string $minutesId The signed record's UUID
	 * @param string $requestId filinq's signing request id
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-2
	 *
	 * @return void
	 */
	private function markStagesSent(string $minutesId, string $requestId): void {
		try {
			$stages = $this->signatureStages(filters: ['signedDocument' => $minutesId]);
			foreach ($stages as $stage) {
				$current = $this->toObjectArray(entity: $stage);
				$stageId = (string)($current['id'] ?? ($current['uuid'] ?? ''));
				if ($stageId === '') {
					continue;
				}

				$this->saveStage(stage: array_merge($current, ['signingReference' => $requestId, 'signingOutcome' => null]), stageId: $stageId);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq: could not name the signing request on the signature stage',
				['minutesId' => $minutesId, 'requestId' => $requestId, 'exception' => $e->getMessage()]
			);
		}//end try
	}//end markStagesSent()

	/**
	 * {@inheritDoc}
	 *
	 * The outcome comes back through filinq's `SigningConcludedEvent`; there is no
	 * signing service to poll, so a request out for signature reads as pending.
	 *
	 * @param string $requestId The signing request id
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-4
	 *
	 * @return array{status: string, document: ?string, fileName: ?string, message: string}
	 */
	public function fetchSigningResult(string $requestId): array {
		if ($requestId === '') {
			return ['status' => 'pending', 'document' => null, 'fileName' => null, 'message' => 'requestId is required.'];
		}

		return [
			'status' => 'pending',
			'document' => null,
			'fileName' => null,
			'message' => 'filinq reports the outcome when the signing concludes.',
		];
	}//end fetchSigningResult()

	/**
	 * {@inheritDoc}
	 *
	 * Signatures are verified by filinq, which places them; decidiq has nothing to verify
	 * against, so it never answers valid (fail closed).
	 *
	 * @param string $requestId UUID of the signing request
	 * @param string $signature Base-64 encoded signature blob
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-4
	 *
	 * @return array{valid: bool, certificateThumbprint: ?string, timestamp: ?string, message: string}
	 */
	public function verifySignature(string $requestId, string $signature): array {
		$message = self::FILINQ_CHECKS;
		if ($requestId === '' || $signature === '') {
			$message = 'requestId and signature are required.';
		}

		return [
			'valid' => false,
			'certificateThumbprint' => null,
			'timestamp' => null,
			'message' => $message,
		];
	}//end verifySignature()

	/**
	 * {@inheritDoc}
	 *
	 * Minutes are finalised when filinq reports the signing request signed
	 * ({@see \OCA\Decidiq\Listener\SigningConcludedListener}); a list of
	 * signatures sent by a client is never taken as proof (fail closed).
	 *
	 * @param string                    $minutesId     UUID of the BoardMinutes record
	 * @param array<int, array<string>> $signatureList List of {signer, signature, timestamp} tuples
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-4
	 *
	 * @return array{success: bool, pdfArchiveReference: ?string, hashSha256: ?string, message: string}
	 */
	public function finalizeMinutes(string $minutesId, array $signatureList): array {
		$message = 'Minutes are finalised when filinq reports the signing concluded.';
		if ($minutesId === '' || $signatureList === []) {
			$message = 'minutesId and at least one signature are required.';
		}

		return [
			'success' => false,
			'pdfArchiveReference' => null,
			'hashSha256' => null,
			'message' => $message,
		];
	}//end finalizeMinutes()

	/**
	 * {@inheritDoc}
	 *
	 * Certificates are checked by filinq, which places the signatures; decidiq
	 * never answers a chain valid on its own (fail closed).
	 *
	 * @param string $certThumbprint SHA-256 thumbprint of the cert
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-4
	 *
	 * @return array{valid: bool, issuer: ?string, trustListLevel: ?string, message: string}
	 */
	public function validateCertificateChain(string $certThumbprint): array {
		$message = self::FILINQ_CHECKS;
		if ($certThumbprint === '') {
			$message = 'certificateThumbprint is required.';
		}

		return [
			'valid' => false,
			'issuer' => null,
			'trustListLevel' => null,
			'message' => $message,
		];
	}//end validateCertificateChain()

	/**
	 * Resolve the method=signature DecisionStages of a signed record: link the
	 * signed document, set outcome=adopted and stamp decidedAt (C5 D5).
	 *
	 * With a signing reference, the stages are the ones that wait on that
	 * filinq request, so a record sent for signature twice resolves only the
	 * round that was signed. Without one, the stages are found by the record
	 * they sign (`signedDocument`). A failed lookup or write is logged; it
	 * never surfaces as an error.
	 *
	 * @param string      $minutesId        UUID of the signed record
	 * @param string|null $signingReference filinq's signing request id
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
	 * @spec openspec/changes/archive/2026-06-14-decision-methods/tasks.md#4-eidas-signature-method-wiring-code
	 *
	 * @return void
	 */
	public function resolveSignatureStage(string $minutesId, ?string $signingReference = null): void {
		try {
			$decidedAt = gmdate('Y-m-d\TH:i:s\Z');
			foreach ($this->signatureStages(filters: $this->stageFilters(minutesId: $minutesId, signingReference: $signingReference)) as $stage) {
				$this->decideStage(stage: $stage, signing: ['minutesId' => $minutesId, 'signingReference' => $signingReference, 'decidedAt' => $decidedAt]);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq: failed to resolve the method=signature DecisionStage',
				['minutesId' => $minutesId, 'exception' => $e->getMessage()]
			);
		}//end try

	}//end resolveSignatureStage()

	/**
	 * Which signature stages a resolution is for: the ones waiting on the
	 * signing request when it is known, else the ones signing the record.
	 *
	 * @param string      $minutesId        UUID of the signed record
	 * @param string|null $signingReference filinq's signing request id
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
	 *
	 * @return array<string, string>
	 */
	private function stageFilters(string $minutesId, ?string $signingReference): array {
		if ((string)$signingReference === '') {
			return ['signedDocument' => $minutesId];
		}

		return ['signingReference' => (string)$signingReference];
	}//end stageFilters()

	/**
	 * Record on the stages of a signing request that it ended without a
	 * signature. The stages stay unresolved.
	 *
	 * @param string $signingReference filinq's signing request id
	 * @param string $outcome          declined, expired or cancelled
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
	 *
	 * @return int The number of stages that now carry the outcome.
	 */
	public function recordUnsignedOutcome(string $signingReference, string $outcome): int {
		$recorded = 0;
		try {
			foreach ($this->signatureStages(filters: ['signingReference' => $signingReference]) as $stage) {
				$current = $this->toObjectArray(entity: $stage);
				$stageId = (string)($current['id'] ?? ($current['uuid'] ?? ''));
				if ($stageId === '') {
					continue;
				}

				$this->saveStage(stage: array_merge($current, ['signingOutcome' => $outcome]), stageId: $stageId);
				$this->auditLogService->append(
					actor: 'system',
					action: 'signature',
					objectUids: [$signingReference, $stageId],
					payload: ['phase' => 'not-signed', 'outcome' => $outcome, 'stageId' => $stageId]
				);
				$recorded++;
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq: could not record the signing outcome on the signature stage',
				['signingReference' => $signingReference, 'outcome' => $outcome, 'exception' => $e->getMessage()]
			);
		}//end try

		return $recorded;
	}//end recordUnsignedOutcome()

	/**
	 * The method=signature stages matching the given filters.
	 *
	 * Read and written in system context: filinq concludes a request in the
	 * last signer's session (or a job), and that signer need not be allowed
	 * to edit the stage. The stage is found by the request filinq names.
	 *
	 * @param array<string, string> $filters Extra filters on the stage.
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
	 *
	 * @return array<int, mixed>
	 */
	private function signatureStages(array $filters): array {
		$found = $this->objectService->findAll(
			[
				'filters' => array_merge(
					[
						'register' => 'decidiq',
						'schema' => 'decision-stage',
						'method' => 'signature',
					],
					$filters
				),
			],
			_rbac: false,
			_multitenancy: false
		);

		return array_values((array)($found['results'] ?? $found));
	}//end signatureStages()

	/**
	 * Mark one signature stage adopted and decided, and audit it.
	 *
	 * @param mixed                $stage   The DecisionStage as found.
	 * @param array<string, mixed> $signing minutesId, signingReference (or null) and decidedAt.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-14-decidesk-contract-decision-hub/tasks.md#phase-3
	 */
	private function decideStage(mixed $stage, array $signing): void {
		if (is_object($stage) === false) {
			return;
		}

		$current = $this->toObjectArray(entity: $stage);

		$stageId = (string)($current['id'] ?? ($current['uuid'] ?? ''));
		if ($stageId === '') {
			return;
		}

		$patch = [
			'outcome' => 'adopted',
			'decidedAt' => $signing['decidedAt'],
			'status' => 'decided',
		];
		if ($signing['signingReference'] !== null) {
			$patch['signingReference'] = $signing['signingReference'];
			$patch['signingOutcome']   = 'signed';
		}

		$this->saveStage(stage: array_merge($current, $patch), stageId: $stageId);

		$this->auditLogService->append(
			actor: 'system',
			action: 'signature',
			objectUids: [$signing['minutesId'], $stageId],
			payload: [
				'phase' => 'resolve-signature-stage',
				'stageId' => $stageId,
				'decidedAt' => $signing['decidedAt'],
			]
		);
	}//end decideStage()

	/**
	 * Save a DecisionStage.
	 *
	 * @param array<string, mixed> $stage   The stage data.
	 * @param string               $stageId The stage UUID.
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
	 *
	 * @return void
	 */
	private function saveStage(array $stage, string $stageId): void {
		$this->objectService->saveObject(
			object: $stage,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: 'decidiq', schema: 'decision-stage'),
				uuid: $stageId
			),
			_rbac: false,
			_multitenancy: false
		);
	}//end saveStage()

	/**
	 * Read an ObjectService entity as a plain array, preferring getObject()
	 * when the entity exposes it.
	 *
	 * @param object $entity Entity returned by ObjectService
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-3.1
	 *
	 * @return array<string, mixed>
	 */
	private function toObjectArray(object $entity): array {
		if (method_exists($entity, 'getObject') === true) {
			return (array)$entity->getObject();
		}

		return (array)$entity->jsonSerialize();
	}//end toObjectArray()
}//end class
