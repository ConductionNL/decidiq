<?php

/**
 * Decidiq Saved Object Lookup
 *
 * The reads a before-save listener makes: the object as it will be saved,
 * the id one of its references holds, and the register object it points at.
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface as ObjectService;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use Throwable;

/**
 * Reads the object being saved and the objects it refers to.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
 */
final class SavedObjectLookup {

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads the register.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The object as it will be saved: the new object on an update, with the
	 * fields the save changes laid over it.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The before-save event.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
	 */
	public function saved(ObjectCreatingEvent|ObjectUpdatingEvent $event): array {
		$reader = new EntityPayloadReader();
		if ($event instanceof ObjectUpdatingEvent) {
			return array_merge($reader->payloadOf(entity: $event->getNewObject()), $event->getModifiedData());
		}

		return array_merge($reader->payloadOf(entity: $event->getObject()), $event->getModifiedData());
	}//end saved()

	/**
	 * The id a reference holds: a uuid or slug string, or an expanded object.
	 *
	 * @param mixed $value The stored reference.
	 *
	 * @return string '' when it holds none.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
	 */
	public function reference(mixed $value): string {
		$held = $value;
		if (is_array($value) === true) {
			$held = ($value['id'] ?? ($value['uuid'] ?? ''));
		}

		return match (is_string($held)) {
			true    => trim($held),
			default => '',
		};
	}//end reference()

	/**
	 * One object of the register by id, read as the system, or null when it
	 * is not there or cannot be read.
	 *
	 * @param string $id     The uuid or slug; '' reads nothing.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
	 */
	public function load(string $id, string $schema): ?array {
		$found = null;
		if ($id !== '') {
			try {
				$found = $this->objectService->find(id: $id, options: new ObjectFindOptions(register: 'decidiq', schema: $schema), _rbac: false);
			} catch (Throwable) {
				// An object that cannot be read counts as not there.
				$found = null;
			}
		}

		if ($found === null) {
			return null;
		}

		return (new EntityPayloadReader())->payloadOf(entity: $found);
	}//end load()
}//end class
