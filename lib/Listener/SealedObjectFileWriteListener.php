<?php

/**
 * Refuses a change to a file that belongs to a sealed object.
 *
 * A governing-document version's consolidated text is a file in the
 * version's own folder. The save guard keeps the version's fields frozen once
 * it is in effect, but a file is written through Nextcloud Files, not through
 * an object save, so the guard never sees it. This listener answers
 * Nextcloud's before-write file events: a file created, overwritten, renamed
 * or removed in the folder of an object in a sealed state is refused, naming
 * that state, and the stored file stays as it was.
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
 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-003-version-immutability-once-effective
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Decidiq\Service\GoverningDocumentConsolidationService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Folder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The file write path's check against the owning object's sealed state.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-003-version-immutability-once-effective
 */
class SealedObjectFileWriteListener implements IEventListener {

	/**
	 * The schemas whose files are sealed, each with the states that seal them.
	 *
	 * @var array<string, list<string>>
	 */
	public const SEALED = [
		GoverningDocumentConsolidationService::VERSION_SCHEMA => GoverningDocumentConsolidationService::SEALED_STATES,
	];

	/**
	 * How many folders up from the file the owning object's folder is looked for.
	 *
	 * @var int
	 */
	private const MAX_DEPTH = 4;

	/**
	 * A canonical uuid, the name OpenRegister gives an object's folder.
	 *
	 * @var string
	 */
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * The refusal when the owning object could not be read.
	 *
	 * @var string
	 */
	private const UNCHECKABLE = 'The file was not changed: the record it belongs to could not be checked. Try again in a moment.';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface          $objectService  Reads the owning object.
	 * @param ListenerSchemaResolverInterface $schemaResolver Names the owning object's schema.
	 * @param LoggerInterface                 $logger         The log.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Abort the file operation when a node it touches belongs to a sealed object.
	 *
	 * @param Event $event A before-write file event.
	 *
	 * @throws \OCP\Exceptions\AbortedEventException On a refused rename or removal.
	 * @throws \OCP\HintException                     On a refused create or overwrite.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#scenario-editing-a-sealed-version-refused
	 */
	public function handle(Event $event): void {
		$files = new FileWriteEvent();
		foreach ($files->nodesOf(event: $event) as $node) {
			$refusal = $this->refusalFor(node: $node);
			if ($refusal !== null) {
				throw $files->refusal(event: $event, refusal: $refusal);
			}
		}
	}//end handle()

	/**
	 * Why a change to this node is refused, or null when it may go ahead.
	 *
	 * The nearest ancestor folder named by a uuid is the candidate owner. A
	 * uuid that is no decidiq object, or an object of a schema that does not
	 * seal, lets the write through; an owner that cannot be read refuses it.
	 *
	 * @param Node $node The file or folder being changed.
	 *
	 * @return string|null The refusal.
	 *
	 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-003-version-immutability-once-effective
	 */
	public function refusalFor(Node $node): ?string {
		$folder = self::ownerFolderOf(node: $node);
		if ($folder === null) {
			return null;
		}

		try {
			$found = $this->objectService->find(
				id: $folder,
				options: new ObjectFindOptions(register: 'decidiq'),
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException) {
			return null;
		} catch (Throwable $e) {
			$this->logger->warning(
				message: 'Decidiq: a file write was refused, its owning record could not be read',
				context: ['folder' => $folder, 'error' => $e->getMessage()]
			);
			return self::UNCHECKABLE;
		}

		if ($found === null) {
			return null;
		}

		$row = (array)$found->getObject();
		return self::sealedRefusal(slug: $this->schemaResolver->schemaSlug(entity: $found, row: $row), row: $row);
	}//end refusalFor()

	/**
	 * The refusal for an owner of this schema in this state, or null when it is not sealed.
	 *
	 * @param string               $slug The owner's schema slug.
	 * @param array<string, mixed> $row  The owner.
	 *
	 * @return string|null The refusal, naming the sealed state.
	 *
	 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#scenario-editing-a-sealed-version-refused
	 */
	public static function sealedRefusal(string $slug, array $row): ?string {
		$status = ($row['status'] ?? null);
		if (isset(self::SEALED[$slug]) === false || in_array($status, self::SEALED[$slug], true) === false) {
			return null;
		}

		return sprintf(
			'The file was not changed: the record it belongs to is %s, and the files of a sealed record cannot be added, '
			. 'replaced, renamed or removed. Make the correction as a new version.',
			(string)$status
		);
	}//end sealedRefusal()

	/**
	 * The name of the nearest folder above the node that is named by a uuid.
	 *
	 * A folder being renamed or removed is its own candidate, so the owner's
	 * folder itself cannot be moved away from under the seal.
	 *
	 * @param Node $node The file or folder being changed.
	 *
	 * @return string|null The uuid, or null when there is none within reach.
	 *
	 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-003-version-immutability-once-effective
	 */
	public static function ownerFolderOf(Node $node): ?string {
		$current = $node;
		if ($node instanceof Folder === false) {
			$current = self::parentOf(node: $node);
		}

		for ($depth = 0; $current !== null && $depth < self::MAX_DEPTH; $depth++) {
			$name = self::nameOf(node: $current);
			if (preg_match(self::UUID, $name) === 1) {
				return strtolower($name);
			}

			$current = self::parentOf(node: $current);
		}

		return null;
	}//end ownerFolderOf()

	/**
	 * The node's parent, or null at the top or when it cannot be read.
	 *
	 * @param Node $node The node.
	 *
	 * @return Node|null
	 */
	private static function parentOf(Node $node): ?Node {
		try {
			$parent = $node->getParent();
		} catch (Throwable) {
			return null;
		}

		if ($parent === $node) {
			return null;
		}

		return $parent;
	}//end parentOf()

	/**
	 * The node's name, or '' when it cannot be read.
	 *
	 * @param Node $node The node.
	 *
	 * @return string
	 */
	private static function nameOf(Node $node): string {
		try {
			return $node->getName();
		} catch (Throwable) {
			return '';
		}
	}//end nameOf()
}//end class
