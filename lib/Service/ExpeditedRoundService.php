<?php

/**
 * Expedited Round Service
 *
 * Opens the expedited written round of an urgent decision: a VotingRound on
 * the decision's decisive stage whose votingDeadline lies the requested number
 * of hours after opening, within the body's urgencyPolicy.responseDeadlineHours
 * bounds. Everything after opening (votes, outcome, the deadline reminder) is
 * the existing written-resolution path, unchanged.
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
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-003-expedited-variant-b--written-round-with-an-hours-based-response-deadline
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateInterval;
use DateTimeImmutable;
use OCA\Decidiq\Kernel\Contracts\ProcessTemplateServiceInterface;
use OCA\Decidiq\Lifecycle\UrgencyTriggerGuard;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Opens an expedited written round within the configured hour bounds.
 *
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-003-expedited-variant-b--written-round-with-an-hours-based-response-deadline
 */
class ExpeditedRoundService {

	/**
	 * Stage states that can no longer take a round.
	 *
	 * @var list<string>
	 */
	private const TERMINAL_STAGE_STATES = ['decided', 'skipped'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface              $container   Resolves OpenRegister's object service.
	 * @param DecisionContextResolver         $context     The decision's meeting, body and chair.
	 * @param ProcessTemplateServiceInterface $templates   The body's urgency policy.
	 * @param UrgencyTriggerGuard             $guard       Who may open the round.
	 * @param ITimeFactory                    $timeFactory The opening time.
	 * @param LoggerInterface                 $logger      Logs a failed save.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly DecisionContextResolver $context,
		private readonly ProcessTemplateServiceInterface $templates,
		private readonly UrgencyTriggerGuard $guard,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Open the expedited written round.
	 *
	 * @param string $decisionId The urgent decision.
	 * @param string $userId     The acting user.
	 * @param mixed  $hours      The response deadline in hours, as requested.
	 *
	 * @return array{status: int, data: array<string, mixed>} The HTTP status and the body to answer with.
	 *
	 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-003-expedited-variant-b--written-round-with-an-hours-based-response-deadline
	 */
	public function open(string $decisionId, string $userId, mixed $hours): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable) {
			return self::envelope(status: Http::STATUS_SERVICE_UNAVAILABLE, message: 'OpenRegister is not available.');
		}

		$decision = $this->context->loadDecision(objectService: $objectService, decisionId: $decisionId);
		if ($decision === null) {
			return self::envelope(status: Http::STATUS_NOT_FOUND, message: sprintf('Decision "%s" not found.', $decisionId));
		}

		$meeting = $this->context->resolveLinkedMeeting(objectService: $objectService, decision: $decision);
		$bodyId  = $this->context->resolveGovernanceBodyId(decision: $decision, meeting: $meeting);
		$policy  = $this->templates->resolveUrgencyPolicyForBody(governanceBodyId: $bodyId);
		if ($policy === null) {
			return self::envelope(status: Http::STATUS_UNPROCESSABLE_ENTITY, message: 'The urgent procedure is not configured for this body.');
		}

		$allowed = $this->guard->mayTrigger(
			policy: $policy,
			meetingId: $this->context->resolveRelationId(value: $this->context->readMeetingReference(decision: $decision)),
			chairUserId: $this->context->resolveChairUserId(objectService: $objectService, meeting: $meeting),
			userId: $userId
		);
		if ($allowed === false) {
			return self::envelope(status: Http::STATUS_FORBIDDEN, message: 'Only the chair or a role the urgency policy allows may open the expedited round.');
		}

		if (($decision['isUrgent'] ?? false) !== true) {
			return self::envelope(status: Http::STATUS_UNPROCESSABLE_ENTITY, message: 'Only an urgent decision has an expedited round.');
		}

		$bounds = self::bounds(policy: $policy);
		if (is_int($hours) === false && (is_string($hours) === false || preg_match('/^\d+$/', $hours) !== 1)) {
			return self::envelope(status: Http::STATUS_UNPROCESSABLE_ENTITY, message: self::boundsMessage(bounds: $bounds));
		}

		$hours = (int)$hours;
		if ($hours < $bounds['min'] || $hours > $bounds['max']) {
			return self::envelope(status: Http::STATUS_UNPROCESSABLE_ENTITY, message: self::boundsMessage(bounds: $bounds));
		}

		$stage = $this->decisiveStage(objectService: $objectService, decisionId: $decisionId);
		if ($stage === null) {
			return self::envelope(status: Http::STATUS_UNPROCESSABLE_ENTITY, message: 'The decision has no open decisive stage to hold the round.');
		}

		$opened = DateTimeImmutable::createFromInterface($this->timeFactory->getDateTime());
		try {
			$round = (array)$objectService->saveObject(object: [
				'votingMethod'   => 'for-against-abstain',
				'isSecret'       => false,
				'openedAt'       => $opened->format(DATE_ATOM),
				'votingDeadline' => $opened->add(new DateInterval('PT' . $hours . 'H'))->format(DATE_ATOM),
				'decisionStage'  => UrgentRatificationService::idOf(row: $stage),
			], register: 'decidiq', schema: 'voting-round')->jsonSerialize();

			$linked = $stage;
			unset($linked['@self']);
			$linked['method']      = 'vote';
			$linked['votingRound'] = UrgentRatificationService::idOf(row: $round);
			$objectService->saveObject(object: $linked, register: 'decidiq', schema: 'decision-stage', uuid: UrgentRatificationService::idOf(row: $stage));
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: opening an expedited round failed', ['decision' => $decisionId, 'reason' => $e->getMessage()]);
			return self::envelope(status: Http::STATUS_INTERNAL_SERVER_ERROR, message: 'The expedited round could not be opened.');
		}

		return ['status' => Http::STATUS_CREATED, 'data' => $round];
	}//end open()

	/**
	 * The decisive stage the round belongs to: the first one not yet decided or skipped.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $decisionId    The decision.
	 *
	 * @return array<string, mixed>|null
	 */
	private function decisiveStage(object $objectService, string $decisionId): ?array {
		$result = $objectService->findAll(['filters' => ['register' => 'decidiq', 'schema' => 'decision-stage', 'decision' => $decisionId]]);
		if (is_array($result) === true && isset($result['results']) === true) {
			$result = $result['results'];
		}

		$found = null;
		foreach ((array)$result as $item) {
			$stage = $item;
			if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
				$stage = $item->jsonSerialize();
			}

			$stage = (array)$stage;
			if (($stage['stageType'] ?? '') !== 'decisive' || in_array((string)($stage['status'] ?? 'pending'), self::TERMINAL_STAGE_STATES, true) === true) {
				continue;
			}

			if ($found === null || (int)($stage['sequence'] ?? 0) < (int)($found['sequence'] ?? 0)) {
				$found = $stage;
			}
		}

		return $found;
	}//end decisiveStage()

	/**
	 * The policy's response-deadline bounds.
	 *
	 * @param array<string, mixed> $policy The urgency policy (validated on save).
	 *
	 * @return array{min: int, max: int}
	 */
	private static function bounds(array $policy): array {
		$bounds = (array)($policy['responseDeadlineHours'] ?? []);
		return ['min' => (int)($bounds['min'] ?? 0), 'max' => (int)($bounds['max'] ?? 0)];
	}//end bounds()

	/**
	 * The refusal that names the bounds.
	 *
	 * @param array{min: int, max: int} $bounds The bounds.
	 *
	 * @return string
	 */
	private static function boundsMessage(array $bounds): string {
		return sprintf('responseDeadlineHours must be a whole number of hours between %d and %d.', $bounds['min'], $bounds['max']);
	}//end boundsMessage()

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
