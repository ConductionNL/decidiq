<?php

/**
 * The approval-flow gateway over OpenRegister's flow services.
 *
 * A thin adapter: every decision about what the flow is lives in the compiler
 * and every decision about whether a send is allowed lives in the starter.
 * This class only looks a flow up by its key, stores and publishes it when it
 * is new, and queues a run. OpenRegister's services are fetched by name, so
 * decidiq loads without them and fails loudly, by name, when they are absent.
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

use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Stores, publishes and runs compiled approval routes in OpenRegister.
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-002-sending-a-proposal-starts-one-flow-run-with-the-decision-as-its-subject
 */
class OpenRegisterApprovalFlowGateway implements ApprovalFlowGateway {
	private const FLOW_SERVICE    = 'OCA\\OpenRegister\\Service\\Flow\\FlowServiceInterface';
	private const VERSION_SERVICE = 'OCA\\OpenRegister\\Service\\Flow\\FlowVersionService';
	private const RUN_SERVICE     = 'OCA\\OpenRegister\\Service\\Flow\\FlowRunService';
	private const QUEUE_OPTIONS   = 'OCA\\OpenRegister\\Service\\Flow\\FlowRunQueueOptions';
	private const PUBLISH_OPTIONS = 'OCA\\OpenRegister\\Service\\Flow\\FlowPublishOptions';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container   Resolves OpenRegister's flow services.
	 * @param IUserSession       $userSession Who sends.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * The published flow for a compiled route, stored and published the first time it is asked for.
	 *
	 * @param array<string, mixed> $document The compiled flow; its `name` is the key.
	 *
	 * @return string The flow's uuid.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-001-a-route-template-compiles-to-a-flow-of-user-tasks
	 */
	public function publishedFlowFor(array $document): string {
		$flows = $this->service(name: self::FLOW_SERVICE);
		$name  = (string)($document['name'] ?? '');
		foreach ($flows->findAll(limit: 500) as $flow) {
			if ((string)$flow->getName() === $name) {
				return (string)$flow->getUuid();
			}
		}

		$stored = $flows->save(data: $document);
		$class  = $this->optionsClass(name: self::PUBLISH_OPTIONS);
		$this->service(name: self::VERSION_SERVICE)->publish(
			flow: $stored,
			options: new $class(publishedBy: $this->userSession->getUser()?->getUID())
		);

		return (string)$stored->getUuid();
	}//end publishedFlowFor()

	/**
	 * Queue one run of a flow with a decision as its subject.
	 *
	 * @param string $flowId   The flow.
	 * @param string $decision The decision's uuid.
	 *
	 * @return string The run's uuid.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-002-sending-a-proposal-starts-one-flow-run-with-the-decision-as-its-subject
	 */
	public function startRun(string $flowId, string $decision): string {
		$class = $this->optionsClass(name: self::QUEUE_OPTIONS);
		$options = new $class(
			subject: ['uuid' => $decision, 'register' => 'decidiq', 'schema' => 'decision'],
			trigger: 'manual',
			context: ['source' => 'decidiq-approval-route'],
			user: $this->userSession->getUser()?->getUID()
		);

		return (string)$this->service(name: self::RUN_SERVICE)->queueRun(flowId: $flowId, options: $options)->getUuid();
	}//end startRun()

	/**
	 * One of OpenRegister's flow option classes, by name.
	 *
	 * @param string $name The class.
	 *
	 * @return string The class, known to exist.
	 *
	 * @throws RuntimeException When OpenRegister does not provide it.
	 */
	private function optionsClass(string $name): string {
		if (class_exists($name) === false) {
			throw new RuntimeException(sprintf('The installed OpenRegister has no %s, so approval routes cannot run as flows.', $name));
		}

		return $name;
	}//end optionsClass()

	/**
	 * One of OpenRegister's flow services.
	 *
	 * @param string $name The service class or interface.
	 *
	 * @return object The service.
	 *
	 * @throws RuntimeException When OpenRegister does not provide it.
	 */
	private function service(string $name): object {
		if (class_exists($name) === false && interface_exists($name) === false) {
			throw new RuntimeException(sprintf('The installed OpenRegister has no %s, so approval routes cannot run as flows.', $name));
		}

		return $this->container->get($name);
	}//end service()
}//end class
