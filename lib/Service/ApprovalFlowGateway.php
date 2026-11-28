<?php

/**
 * The two things decidiq asks of OpenRegister's flow engine for an approval route.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-002-sending-a-proposal-starts-one-flow-run-with-the-decision-as-its-subject
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use RuntimeException;

/**
 * Stores a compiled route and starts runs of it.
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-002-sending-a-proposal-starts-one-flow-run-with-the-decision-as-its-subject
 */
interface ApprovalFlowGateway {
	/**
	 * The published flow for a compiled route, stored and published the first time it is asked for.
	 *
	 * @param array<string, mixed> $document The compiled flow; its `name` is the key.
	 *
	 * @return string The flow's uuid.
	 *
	 * @throws RuntimeException When OpenRegister refuses the flow.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-001-a-route-template-compiles-to-a-flow-of-user-tasks
	 */
	public function publishedFlowFor(array $document): string;

	/**
	 * Queue one run of a flow with a decision as its subject.
	 *
	 * @param string $flowId   The flow.
	 * @param string $decision The decision's uuid.
	 *
	 * @return string The run's uuid.
	 *
	 * @throws RuntimeException When OpenRegister refuses the run.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-002-sending-a-proposal-starts-one-flow-run-with-the-decision-as-its-subject
	 */
	public function startRun(string $flowId, string $decision): string;
}//end interface
