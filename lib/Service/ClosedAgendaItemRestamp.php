<?php

/**
 * Re-saves the non-public agenda items that do not yet carry their meeting's body.
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
 * @spec openspec/changes/agenda-item-confidential-papers/tasks.md#task-1-fields-and-read-rules
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Listener\EntityPayloadReader;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * A non-public item saved before the read rules existed has no governance body, so its
 * body's members would not pass its read rule. Saving it again lets the confidentiality
 * listener stamp the body from the meeting and hand the body and readers to its papers.
 *
 * @spec openspec/changes/agenda-item-confidential-papers/tasks.md#task-1-fields-and-read-rules
 */
class ClosedAgendaItemRestamp {

	/**
	 * The most items handled in one run.
	 *
	 * @var integer
	 */
	private const LIMIT = 5000;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads and re-saves the items.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Re-save every non-public item that has a meeting and no governance body.
	 *
	 * @return int How many items were saved.
	 *
	 * @spec openspec/changes/agenda-item-confidential-papers/tasks.md#task-1-fields-and-read-rules
	 */
	public function restamp(): int {
		$items = $this->objectService->findAll(
			config: ['filters' => ['register' => 'decidiq', 'schema' => 'agenda-item', 'public' => false], 'limit' => self::LIMIT],
			_rbac: false,
			_multitenancy: false
		);

		$saved = 0;
		foreach ($items as $item) {
			$data = (new EntityPayloadReader())->payloadOf(entity: $item);
			if (($data['public'] ?? true) !== false || empty($data['meeting']) === true || empty($data['governanceBody']) === false) {
				continue;
			}

			$this->objectService->saveObject(
				object: $data,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: 'decidiq', schema: 'agenda-item'),
					uuid: (string)($item->getUuid() ?? ($data['id'] ?? ''))
				),
				_rbac: false,
				_multitenancy: false
			);
			$saved++;
		}

		return $saved;
	}//end restamp()
}//end class
