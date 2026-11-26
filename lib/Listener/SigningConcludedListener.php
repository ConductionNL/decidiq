<?php

/**
 * Decidiq SigningConcludedListener
 *
 * filinq dispatches `SigningConcludedEvent` when a signing request another
 * app raised reaches its end (signed, declined, expired or cancelled). For a
 * request decidiq raised, this listener records the outcome on the signed
 * record and its method=signature stages: `signed` links the signed document
 * and resolves the stages through
 * {@see EIDASSignatureService::resolveSignatureStage()}; any other outcome
 * leaves the stages unresolved and records why. Requests of other apps are
 * ignored, and the record is matched on the signing request id, never on the
 * record alone (design D2).
 *
 * The event class is filinq's, read through its getters only, so decidiq
 * keeps no hard dependency on filinq.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Kernel\Contracts\SigningRoundServiceInterface;
use OCA\Decidiq\Service\EIDASSignatureService;
use OCA\Decidiq\Support\FleetAppId;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Records filinq's signing outcome for a request decidiq raised.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
 */
class SigningConcludedListener implements IEventListener {

	/**
	 * filinq's conclusion event, relative to its namespace.
	 *
	 * @var string
	 */
	public const EVENT = 'Event\SigningConcludedEvent';

	/**
	 * The outcomes filinq reports that are not a signature.
	 *
	 * @var list<string>
	 */
	private const UNSIGNED = ['declined', 'expired', 'cancelled'];

	/**
	 * Constructor.
	 *
	 * @param SigningRoundServiceInterface $rounds     Records the outcome on the signed record
	 * @param EIDASSignatureService        $signatures Resolves the signature stages
	 * @param LoggerInterface              $logger     Logger
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
	 */
	public function __construct(
		private readonly SigningRoundServiceInterface $rounds,
		private readonly EIDASSignatureService $signatures,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle filinq's conclusion event.
	 *
	 * @param Event $event The event
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		if (FleetAppId::isInstanceOf(value: $event, canonical: 'filinq', relative: self::EVENT) === false
			|| $event->getSourceApp() !== EIDASSignatureService::SOURCE_APP
		) {
			return;
		}

		$requestId = (string)$event->getSigningRequestId();
		$status    = (string)$event->getStatus();
		$subjectId = (string)($event->getSubjectId() ?? '');
		if ($requestId === '' || $subjectId === '') {
			return;
		}

		try {
			$recorded = $this->rounds->conclude(
				schema: (string)($event->getSubjectSchema() ?? ''),
				subjectId: $subjectId,
				conclusion: [
					'requestId' => $requestId,
					'status' => $status,
					'signedDocumentRef' => $event->getSignedDocumentRef(),
					'signedAt' => $event->getSignedAt(),
				]
			);
			if ($recorded['status'] === 'ignored') {
				$this->logger->info('Decidiq: a filinq signing outcome matched no record out for signature', ['requestId' => $requestId, 'reason' => $recorded['message']]);
				return;
			}

			$this->resolveStages(subjectId: $subjectId, requestId: $requestId, status: $status);
		} catch (\Throwable $e) {
			// A failure here must never surface in filinq's signing flow.
			$this->logger->error(
				'Decidiq: recording a filinq signing outcome failed',
				['requestId' => $requestId, 'status' => $status, 'exception' => $e->getMessage()]
			);
		}//end try
	}//end handle()

	/**
	 * Resolve the stages on `signed`; record the outcome on any other end.
	 *
	 * @param string $subjectId The signed record's UUID
	 * @param string $requestId filinq's signing request id
	 * @param string $status    The outcome filinq reported
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
	 *
	 * @return void
	 */
	private function resolveStages(string $subjectId, string $requestId, string $status): void {
		if ($status === 'signed') {
			$this->signatures->resolveSignatureStage(minutesId: $subjectId, signingReference: $requestId);
			return;
		}

		if (in_array($status, self::UNSIGNED, true) === true) {
			$this->signatures->recordUnsignedOutcome(signingReference: $requestId, outcome: $status);
		}
	}//end resolveStages()
}//end class
