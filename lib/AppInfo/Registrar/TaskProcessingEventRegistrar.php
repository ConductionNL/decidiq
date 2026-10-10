<?php

/**
 * Decidiq TaskProcessing Event Registrar
 *
 * Registers the listener that lands the answer of a paper summary's
 * TaskProcessing task on the summary (agenda-ai-paper-summaries, REQ-APS-004).
 *
 * @category AppInfo
 * @package  OCA\Decidiq\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-004-the-ai-result-lands-as-a-draft-and-long-papers-are-summarised-in-parts
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\AppInfo\Registrar;

use OCA\Decidiq\Listener\PaperSummaryTaskListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\TaskProcessing\Events\TaskFailedEvent;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;

/**
 * Wires the TaskProcessing success and failure events to the paper summary listener.
 *
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-004-the-ai-result-lands-as-a-draft-and-long-papers-are-summarised-in-parts
 */
final class TaskProcessingEventRegistrar {
	/**
	 * Register both task outcome events.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-004-the-ai-result-lands-as-a-draft-and-long-papers-are-summarised-in-parts
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(event: TaskSuccessfulEvent::class, listener: PaperSummaryTaskListener::class);
		$context->registerEventListener(event: TaskFailedEvent::class, listener: PaperSummaryTaskListener::class);
	}//end register()
}//end class
