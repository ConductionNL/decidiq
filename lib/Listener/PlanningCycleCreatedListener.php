<?php

/**
 * Decidiq PlanningCycleCreatedListener
 *
 * A planning cycle created with a template gets its steps
 * (planning-cycle-generate-from-template, pla-12): one PlanningCycleStep per
 * template step, built by PlanningCycleGenerator. Runs once: a cycle that
 * already has steps is left alone, so a replayed event or a cycle whose
 * steps were entered by hand never gets a second set.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\Decidiq\Service\PlanningCycleGenerator;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Generates a new planning cycle's steps from its template.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps
 */
class PlanningCycleCreatedListener implements IEventListener {

	/**
	 * Schema slug of a planning cycle.
	 */
	public const SCHEMA_PLANNING_CYCLE = 'planning-cycle';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService  Reads the template, writes the steps
	 * @param PlanningCycleGenerator $generator      Builds the step payloads
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's schema slug
	 * @param LoggerInterface        $logger         Logger
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly PlanningCycleGenerator $generator,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a planning cycle create.
	 *
	 * @param Event $event The event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		try {
			$entity = $event->getObject();
			$row = $this->extractRow(entity: $entity);
			if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: self::SCHEMA_PLANNING_CYCLE, row: $row) === false) {
				return;
			}

			$cycleId = (string)$entity->getUuid();
			$year = (int)($row['year'] ?? 0);
			$template = $this->templateToGenerate(cycleId: $cycleId, templateId: trim((string)($row['template'] ?? '')), year: $year);
			if ($template === null) {
				return;
			}

			foreach ($this->generator->stepsFor(cycleId: $cycleId, year: $year, template: $template) as $step) {
				$this->objectService->saveObject(object: $step, register: 'decidiq', schema: 'planning-cycle-step');
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: generating the planning cycle steps failed', ['exception' => $e->getMessage()]);
		}//end try
	}//end handle()

	/**
	 * The template to generate from, or null when there is nothing to do: no
	 * cycle id, template or year, steps already present, or no such template.
	 *
	 * @param string $cycleId    The cycle
	 * @param string $templateId The template
	 * @param int    $year       The cycle year
	 *
	 * @return array<string, mixed>|null
	 */
	private function templateToGenerate(string $cycleId, string $templateId, int $year): ?array {
		if ($cycleId === '' || $templateId === '' || $year <= 0 || $this->hasSteps(cycleId: $cycleId) === true) {
			return null;
		}

		$template = $this->objectService->find(id: $templateId, register: 'decidiq', schema: 'planning-cycle-template');
		if ($template === null) {
			return null;
		}

		return $this->extractRow(entity: $template);
	}//end templateToGenerate()

	/**
	 * Whether the cycle already has steps.
	 *
	 * @param string $cycleId The cycle
	 *
	 * @return bool
	 */
	private function hasSteps(string $cycleId): bool {
		$rows = $this->objectService->findAll(
			config: ['filters' => ['register' => 'decidiq', 'schema' => 'planning-cycle-step', 'cycle' => $cycleId], 'limit' => 1]
		);

		return ((array)($rows['results'] ?? $rows)) !== [];
	}//end hasSteps()

	/**
	 * The object data of an entity.
	 *
	 * @param object $entity The entity
	 *
	 * @return array<string, mixed>
	 */
	private function extractRow(object $entity): array {
		$row = [];
		if (method_exists($entity, 'getObject') === true) {
			$row = (array)$entity->getObject();
		}

		if ($row === [] && method_exists($entity, 'jsonSerialize') === true) {
			$row = (array)$entity->jsonSerialize();
		}

		return $row;
	}//end extractRow()
}//end class
