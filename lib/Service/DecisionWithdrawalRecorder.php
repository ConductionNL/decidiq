<?php

/**
 * Decision Withdrawal Recorder
 *
 * Loads a decision, has DecisionWithdrawalService append the withdrawal to it,
 * and stores the result. The rules (who may be named as the withdrawing actor,
 * that the outcome stays readable, that a decision is withdrawn once) stay in
 * DecisionWithdrawalService; this class is the load and the save around it, so
 * the withdrawal is reachable through decision#withdraw.
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use InvalidArgumentException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Withdraws a stored decision: load, append the withdrawal, save.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-005)
 */
class DecisionWithdrawalRecorder {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface        $container   Resolves OpenRegister's object service.
	 * @param LoggerInterface           $logger      Logs a failed save.
	 * @param DecisionWithdrawalService $withdrawals The withdrawal rules.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly DecisionWithdrawalService $withdrawals = new DecisionWithdrawalService(),
	) {
	}//end __construct()

	/**
	 * Withdraw a decision.
	 *
	 * @param string $decisionId  The decision.
	 * @param string $withdrawnBy Who withdraws it: bestuursorgaan or belanghebbende.
	 * @param string $reason      Why.
	 *
	 * @return array{status: int, data: array<string, mixed>} The HTTP status and the body to answer with.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-005)
	 */
	public function withdraw(string $decisionId, string $withdrawnBy, string $reason): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable) {
			return self::envelope(status: Http::STATUS_SERVICE_UNAVAILABLE, message: 'OpenRegister is not available.');
		}

		$objectService->setRegister('decidiq');
		$objectService->setSchema('decision');

		try {
			$entity = $objectService->find(id: $decisionId);
		} catch (DoesNotExistException) {
			$entity = null;
		}

		if ($entity === null) {
			return self::envelope(status: Http::STATUS_NOT_FOUND, message: sprintf('Decision "%s" not found.', $decisionId));
		}

		try {
			$withdrawn = $this->withdrawals->withdraw(decision: (array)$entity->getObject(), withdrawnBy: $withdrawnBy, reason: $reason);
		} catch (InvalidArgumentException $e) {
			return self::envelope(status: Http::STATUS_UNPROCESSABLE_ENTITY, message: $e->getMessage());
		}

		try {
			$saved = $objectService->saveObject(object: $withdrawn, register: 'decidiq', schema: 'decision', uuid: $decisionId);
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: withdrawing a decision failed', ['decision' => $decisionId, 'reason' => $e->getMessage()]);
			return self::envelope(status: Http::STATUS_INTERNAL_SERVER_ERROR, message: 'The withdrawal could not be saved.');
		}

		return ['status' => Http::STATUS_OK, 'data' => (array)$saved->getObject()];
	}//end withdraw()

	/**
	 * A refusal with its message.
	 *
	 * @param int    $status  The HTTP status.
	 * @param string $message The message.
	 *
	 * @return array{status: int, data: array<string, mixed>}
	 */
	private static function envelope(int $status, string $message): array {
		return ['status' => $status, 'data' => ['message' => $message]];
	}//end envelope()
}//end class
