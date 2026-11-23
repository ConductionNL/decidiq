<?php

/**
 * Decidiq Saved Object Lookup
 *
 * What a before-save listener reads about the object being saved: the object
 * as it will be saved, the id a stored reference holds, and one object of the
 * register that reference points at.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
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
	 * @param ObjectServiceInterface $objectService Reads the register.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
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
	public static function saved(ObjectCreatingEvent|ObjectUpdatingEvent $event): array {
		if ($event instanceof ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
		} else {
			$entity = $event->getObject();
		}

		return array_merge((new EntityPayloadReader())->payloadOf(entity: $entity), $event->getModifiedData());
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
	public static function reference(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? null));
		}

		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
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
		if ($id === '') {
			return null;
		}

		try {
			$entity = $this->objectService->find(
				id: $id,
				options: new ObjectFindOptions(register: 'decidiq', schema: $schema),
				_rbac: false
			);
		} catch (Throwable) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return (new EntityPayloadReader())->payloadOf(entity: $entity);
	}//end load()
}//end class
