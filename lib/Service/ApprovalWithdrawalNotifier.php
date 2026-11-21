<?php

/**
 * Approval Withdrawal Notifier
 *
 * Tells each approver whose grant went stale that it was withdrawn, which of
 * the approved properties changed, and that the step waits for them again
 * (REQ-DWP-003). An approver who is not told finds out when somebody asks why
 * the step is still open, which is the worst moment to find out.
 *
 * Fail-soft per actor, through the preference-aware dispatcher, as the voting
 * notices are: a notice that cannot be sent never undoes the withdrawal.
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Notifies the actors whose granted approvals were withdrawn.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
 */
class ApprovalWithdrawalNotifier {
	/**
	 * The preference toggle the notice is filed under: the step is the
	 * approver's task again.
	 *
	 * @var string
	 */
	public const EVENT_TYPE = 'taskAssigned';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (lazy lookup of the dispatcher).
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Notify every actor of the withdrawals once.
	 *
	 * @param array<int, array<string, mixed>> $withdrawals   The withdrawn actions, with `actor`, `step` and `withdrawnPaths`.
	 * @param string                           $subject       The subject's uuid.
	 * @param string                           $subjectSchema The subject's schema slug.
	 *
	 * @return int How many actors were notified.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
	 */
	public function notify(array $withdrawals, string $subject, string $subjectSchema): int {
		$notices = self::noticesPerActor(withdrawals: $withdrawals);
		if ($notices === []) {
			return 0;
		}

		$dispatcher = $this->dispatcher();
		if ($dispatcher === null) {
			return 0;
		}

		$notified = 0;
		foreach ($notices as $actor => $notice) {
			try {
				$dispatcher->dispatch(
					personId: $actor,
					eventType: self::EVENT_TYPE,
					title: 'Your approval was withdrawn',
					message: self::message(steps: $notice['steps'], paths: $notice['paths']),
					deepLink: self::deepLink(subject: $subject, subjectSchema: $subjectSchema)
				);
				$notified++;
			} catch (Throwable $e) {
				$this->logger->warning('Decidiq: approval withdrawal notice failed', ['actor' => $actor, 'error' => $e->getMessage()]);
			}
		}

		return $notified;
	}//end notify()

	/**
	 * The withdrawals folded per actor: the steps and the changed paths.
	 *
	 * @param array<int, array<string, mixed>> $withdrawals The withdrawn actions.
	 *
	 * @return array<string, array{steps: array<int, int>, paths: array<int, string>}> Per actor.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
	 */
	public static function noticesPerActor(array $withdrawals): array {
		$notices = [];
		foreach ($withdrawals as $withdrawal) {
			$actor = (string)($withdrawal['actor'] ?? '');
			if ($actor === '') {
				continue;
			}

			$notice = ($notices[$actor] ?? ['steps' => [], 'paths' => []]);
			$notice['steps'][] = (int)($withdrawal['step'] ?? 0);
			foreach ((array)($withdrawal['withdrawnPaths'] ?? []) as $path) {
				$notice['paths'][] = (string)$path;
			}

			$notice['steps'] = array_values(array_unique(array_filter($notice['steps'])));
			$notice['paths'] = array_values(array_unique(array_filter($notice['paths'])));
			$notices[$actor] = $notice;
		}

		return $notices;
	}//end noticesPerActor()

	/**
	 * The notice text.
	 *
	 * @param array<int, int>    $steps The reopened steps.
	 * @param array<int, string> $paths The changed properties.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
	 */
	public static function message(array $steps, array $paths): string {
		$changed = 'What you approved was changed';
		if ($paths !== []) {
			$changed .= ' (' . implode(', ', $paths) . ')';
		}

		$step = 'The step waits for your approval again.';
		if (count($steps) === 1) {
			$step = sprintf('Step %d waits for your approval again.', $steps[0]);
		}

		return $changed . '. ' . $step;
	}//end message()

	/**
	 * The app link of the subject, or '' when decidiq has no page for it.
	 *
	 * @param string $subject       The subject's uuid.
	 * @param string $subjectSchema The subject's schema slug.
	 *
	 * @return string The link.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
	 */
	public static function deepLink(string $subject, string $subjectSchema): string {
		if ($subjectSchema === 'decision' && $subject !== '') {
			return '/decisions/' . $subject;
		}

		return '';
	}//end deepLink()

	/**
	 * The preference-aware dispatcher, or null when it cannot be resolved.
	 *
	 * @return NotificationPreferenceService|null
	 */
	private function dispatcher(): ?NotificationPreferenceService {
		try {
			$service = $this->container->get(NotificationPreferenceService::class);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: approval withdrawal notices not sent', ['error' => $e->getMessage()]);
			return null;
		}

		if ($service instanceof NotificationPreferenceService === false) {
			return null;
		}

		return $service;
	}//end dispatcher()
}//end class
