<?php

/**
 * Decidiq SigningConcludedListener
 *
 * filinq dispatches `SigningConcludedEvent` when a signing request another
 * app raised reaches its end (signed, declined, expired or cancelled). For a
 * request decidiq raised, this listener hands the outcome to
 * {@see SigningConclusion}, which records it on the signed record and its
 * method=signature stages. Requests of other apps are ignored.
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

use OCA\Decidiq\Service\EIDASSignatureService;
use OCA\Decidiq\Service\SigningConclusion;
use OCA\Decidiq\Support\FleetAppId;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Hands filinq's signing outcome for a request decidiq raised to SigningConclusion.
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
	 * Constructor.
	 *
	 * @param SigningConclusion $conclusion Records the outcome
	 * @param LoggerInterface   $logger     Logger
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
	 */
	public function __construct(
		private readonly SigningConclusion $conclusion,
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
			|| $this->read(event: $event, getter: 'getSourceApp') !== EIDASSignatureService::SOURCE_APP
		) {
			return;
		}

		$conclusion = [
			'requestId' => $this->read(event: $event, getter: 'getSigningRequestId'),
			'status' => $this->read(event: $event, getter: 'getStatus'),
			'signedDocumentRef' => $this->read(event: $event, getter: 'getSignedDocumentRef'),
			'signedAt' => $this->read(event: $event, getter: 'getSignedAt'),
		];

		try {
			$recorded = $this->conclusion->record(
				schema: $this->read(event: $event, getter: 'getSubjectSchema'),
				subjectId: $this->read(event: $event, getter: 'getSubjectId'),
				conclusion: $conclusion
			);
			if ($recorded === 'ignored') {
				$this->logger->info('Decidiq: a filinq signing outcome matched no record out for signature', $conclusion);
			}
		} catch (\Throwable $e) {
			// A failure here must never surface in filinq's signing flow.
			$this->logger->error(
				'Decidiq: recording a filinq signing outcome failed',
				$conclusion + ['exception' => $e->getMessage()]
			);
		}//end try
	}//end handle()

	/**
	 * One string field of filinq's event, '' when it has no such getter.
	 *
	 * @param Event  $event  filinq's event
	 * @param string $getter The getter's name
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
	 *
	 * @return string
	 */
	private function read(Event $event, string $getter): string {
		if (method_exists($event, $getter) === false) {
			return '';
		}

		return (string)call_user_func([$event, $getter]);
	}//end read()
}//end class
