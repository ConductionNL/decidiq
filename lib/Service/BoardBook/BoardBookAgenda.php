<?php

/**
 * Decidiq Board Book Agenda
 *
 * Reads what a meeting pack is compiled from: the meeting, its governance
 * body's name, its agenda items in orderNumber order with their attachments,
 * and the packs compiled before. Every read goes through OpenRegister, so a
 * caller without read access to the meeting finds nothing.
 *
 * @category Service
 * @package  OCA\Decidiq\Service\BoardBook
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-003-meetingpack-version-tracking
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service\BoardBook;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The meeting, its agenda and its earlier packs, as plain arrays.
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-003-meetingpack-version-tracking
 */
class BoardBookAgenda {

	/**
	 * The register every board book object lives in.
	 *
	 * @var string
	 */
	public const REGISTER = 'decidiq';

	/**
	 * The MeetingPack schema slug.
	 *
	 * @var string
	 */
	public const PACK_SCHEMA = 'meeting-pack';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's objects.
	 * @param ContainerInterface     $container     Lazy-loads OpenRegister's FileService.
	 * @param LoggerInterface        $logger        The logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The meeting, or null when it does not exist or the caller may not read it.
	 *
	 * @param string $meetingId The meeting uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-005-access-control-and-confidential-item-exclusion
	 */
	public function meeting(string $meetingId): ?array {
		$meeting = $this->one(id: $meetingId, schema: 'meeting');
		if ($meeting === null) {
			return null;
		}

		$meeting['id'] = $meetingId;

		return $meeting;
	}//end meeting()

	/**
	 * The name of the meeting's governance body; empty when it has none or it cannot be read.
	 *
	 * @param array<string, mixed> $meeting The meeting.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack
	 */
	public function bodyName(array $meeting): string {
		$bodyId = $this->idOf(value: ($meeting['governanceBody'] ?? null));
		if ($bodyId === '') {
			return '';
		}

		return (string)(($this->one(id: $bodyId, schema: 'governance-body') ?? [])['name'] ?? '');
	}//end bodyName()

	/**
	 * The meeting's agenda items in orderNumber order.
	 *
	 * @param string $meetingId The meeting uuid.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack
	 */
	public function items(string $meetingId): array {
		$items = $this->linkedTo(schema: 'agenda-item', meetingId: $meetingId);
		usort($items, static fn (array $a, array $b): int => ((int)($a['orderNumber'] ?? 0)) <=> ((int)($b['orderNumber'] ?? 0)));

		return $items;
	}//end items()

	/**
	 * The packs compiled for the meeting, newest version first.
	 *
	 * @param string $meetingId The meeting uuid.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-003-meetingpack-version-tracking
	 */
	public function packs(string $meetingId): array {
		$packs = $this->linkedTo(schema: self::PACK_SCHEMA, meetingId: $meetingId);
		usort($packs, static fn (array $a, array $b): int => ((int)($b['version'] ?? 0)) <=> ((int)($a['version'] ?? 0)));

		return $packs;
	}//end packs()

	/**
	 * The attachments of each agenda item, as rows and as the file nodes they were read from.
	 *
	 * @param array<int, array<string, mixed>> $items The agenda items.
	 *
	 * @return array{rows: array<string, array<int, array<string, mixed>>>, nodes: array<string, array<int, object>>}
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-004-pack-outdated-indicator
	 */
	public function attachments(array $items): array {
		$rows = [];
		$nodes = [];
		foreach ($items as $item) {
			$itemId = (string)($item['id'] ?? '');
			$rows[$itemId] = [];
			$nodes[$itemId] = [];
			foreach ($this->filesOf(itemId: $itemId) as $node) {
				$rows[$itemId][] = $this->fileRow(node: $node);
				$nodes[$itemId][] = $node;
			}
		}

		return ['rows' => $rows, 'nodes' => $nodes];
	}//end attachments()

	/**
	 * Save a new MeetingPack object.
	 *
	 * @param array<string, mixed> $pack The pack.
	 *
	 * @return array<string, mixed> The pack as saved.
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-003-meetingpack-version-tracking
	 */
	public function savePack(array $pack): array {
		$saved = $this->objectService->saveObject(object: $pack, register: self::REGISTER, schema: self::PACK_SCHEMA);

		return ($this->rowOf(value: $saved) ?? $pack);
	}//end savePack()

	/**
	 * One object, or null when it cannot be read.
	 *
	 * @param string $id     The uuid.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function one(string $id, string $schema): ?array {
		try {
			$entity = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: board book lookup failed', ['schema' => $schema, 'id' => $id, 'exception' => $e->getMessage()]);
			return null;
		}

		if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
			return (array)$entity->getObject();
		}

		return $this->rowOf(value: $entity);
	}//end one()

	/**
	 * The objects of a schema linked to the meeting.
	 *
	 * @param string $schema    The schema slug.
	 * @param string $meetingId The meeting uuid.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function linkedTo(string $schema, string $meetingId): array {
		try {
			$query = ['register' => self::REGISTER, 'schema' => $schema, 'filters' => ['meeting' => $meetingId], 'limit' => 500];
			$found = (array)$this->objectService->findAll($query);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: board book lookup failed', ['schema' => $schema, 'meetingId' => $meetingId, 'exception' => $e->getMessage()]);
			return [];
		}

		$rows = [];
		foreach ($found as $value) {
			$row = $this->rowOf(value: $value);
			if ($row !== null && $this->idOf(value: ($row['meeting'] ?? null)) === $meetingId) {
				$row['id'] = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
				$rows[] = $row;
			}
		}

		return $rows;
	}//end linkedTo()

	/**
	 * The file nodes attached to an agenda item; none when they cannot be read.
	 *
	 * @param string $itemId The agenda item uuid.
	 *
	 * @return array<int, object>
	 */
	private function filesOf(string $itemId): array {
		if ($itemId === '') {
			return [];
		}

		try {
			$nodes = (array)$this->container->get('OCA\OpenRegister\Service\FileService')->getFiles($itemId);
		} catch (Throwable $e) {
			$this->logger->debug('Decidiq: board book attachments unreadable', ['itemId' => $itemId, 'error' => $e->getMessage()]);
			return [];
		}

		return array_values(array_filter($nodes, static fn (mixed $node): bool => is_object($node) === true && method_exists($node, 'getName') === true));
	}//end filesOf()

	/**
	 * One file node as an attachment row.
	 *
	 * @param object $node The file node.
	 *
	 * @return array{id: string, name: string, etag: string, mime: string, size: int}
	 */
	private function fileRow(object $node): array {
		return [
			'id' => (string)$this->call(node: $node, method: 'getId'),
			'name' => (string)$node->getName(),
			'etag' => (string)$this->call(node: $node, method: 'getEtag'),
			'mime' => (string)$this->call(node: $node, method: 'getMimetype'),
			'size' => (int)$this->call(node: $node, method: 'getSize'),
		];
	}//end fileRow()

	/**
	 * A getter's answer, or the empty string when the node lacks it or it throws.
	 *
	 * @param object $node   The file node.
	 * @param string $method The getter.
	 *
	 * @return mixed
	 */
	private function call(object $node, string $method): mixed {
		if (method_exists($node, $method) === false) {
			return '';
		}

		try {
			return ($node->{$method}() ?? '');
		} catch (Throwable) {
			return '';
		}
	}//end call()

	/**
	 * A relation value's id: the value itself, or the `id` of a related object.
	 *
	 * @param mixed $value The relation value.
	 *
	 * @return string
	 */
	private function idOf(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? '');
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end idOf()

	/**
	 * A found value as a plain array, or null.
	 *
	 * @param mixed $value An array or a serialisable entity.
	 *
	 * @return array<string, mixed>|null
	 */
	private function rowOf(mixed $value): ?array {
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$value = $value->jsonSerialize();
		}

		if (is_array($value) === false) {
			return null;
		}

		return $value;
	}//end rowOf()
}//end class
