<?php

/**
 * Decidiq FullExportService
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
 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ITempManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * Builds one ZIP with every decidiq record, one JSON file per schema, the
 * files of every meeting, agenda item and decision, and a manifest.json that
 * names the schemas, their counts and their relations.
 *
 * Records are read in system context: the export is the organisation's, taken
 * by an administrator, and must not depend on what that account may read.
 * Only the newest export is kept.
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
 */
class FullExportService {
	/**
	 * The app data folder the exports live in.
	 */
	public const FOLDER = 'exports';

	/**
	 * The name every export file matches.
	 */
	public const NAME_PATTERN = '/^decidiq-export-\d{8}-\d{6}\.zip$/';

	/**
	 * The schemas whose objects carry files worth exporting.
	 */
	private const FILE_SCHEMAS = ['meeting', 'agenda-item', 'decision'];

	/**
	 * Objects read per page.
	 */
	private const PAGE_SIZE = 500;

	/**
	 * JSON flags for the files in the archive.
	 */
	private const JSON_FLAGS = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param ContainerInterface     $container     DI container (OpenRegister FileService, lazily).
	 * @param IAppData               $appData       The app's data folder.
	 * @param ITempManager           $tempManager   Temporary files.
	 * @param ITimeFactory           $timeFactory   Clock.
	 * @param LoggerInterface        $logger        Logger.
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ContainerInterface $container,
		private readonly IAppData $appData,
		private readonly ITempManager $tempManager,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build the export and keep it as the only one in app data.
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
	 *
	 * @throws RuntimeException When the archive cannot be written.
	 *
	 * @return string The export's file name.
	 */
	public function build(): string {
		$now  = $this->timeFactory->getDateTime();
		$name = 'decidiq-export-' . $now->format('Ymd-His') . '.zip';
		$path = (string)$this->tempManager->getTemporaryFile('.zip');
		$zip  = new ZipArchive();
		if ($path === '' || $zip->open($path, (ZipArchive::CREATE | ZipArchive::OVERWRITE)) !== true) {
			throw new RuntimeException('The export archive could not be created.');
		}

		$manifest = [
			'app' => 'decidiq',
			'exportedAt' => $now->format(DATE_ATOM),
			'schemas' => [],
			'skipped' => [],
			'files' => 0,
			'missingFiles' => [],
		];
		foreach (SettingsService::shippedRegisterDescriptor()['components']['schemas'] as $schema) {
			$slug = (string)($schema['slug'] ?? '');
			if ($slug === '') {
				continue;
			}

			try {
				$objects = $this->readAll(slug: $slug);
			} catch (\Throwable $e) {
				$manifest['skipped'][] = ['schema' => $slug, 'reason' => $e->getMessage()];
				continue;
			}

			$zip->addFromString($slug . '.json', (string)json_encode($objects, self::JSON_FLAGS));
			$manifest['schemas'][] = [
				'slug' => $slug,
				'title' => (string)($schema['title'] ?? $slug),
				'version' => (string)($schema['version'] ?? ''),
				'file' => $slug . '.json',
				'count' => count($objects),
				'relations' => $this->relationsOf(schema: $schema),
			];
			if (in_array($slug, self::FILE_SCHEMAS, true) === true) {
				$this->addFiles(zip: $zip, slug: $slug, objects: $objects, manifest: $manifest);
			}
		}//end foreach

		$zip->addFromString('manifest.json', (string)json_encode($manifest, self::JSON_FLAGS));
		if ($zip->close() === false) {
			throw new RuntimeException('The export archive could not be written.');
		}

		$this->keepOnly(name: $name, path: $path);
		return $name;
	}//end build()

	/**
	 * The newest export, or null when there is none.
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
	 *
	 * @return array{name:string,size:int,createdAt:string}|null
	 */
	public function latest(): ?array {
		$newest = null;
		foreach ($this->folder()->getDirectoryListing() as $file) {
			if (preg_match(self::NAME_PATTERN, $file->getName()) !== 1) {
				continue;
			}

			if ($newest === null || strcmp($file->getName(), $newest->getName()) > 0) {
				$newest = $file;
			}
		}

		if ($newest === null) {
			return null;
		}

		return [
			'name' => $newest->getName(),
			'size' => (int)$newest->getSize(),
			'createdAt' => date(DATE_ATOM, $newest->getMTime()),
		];
	}//end latest()

	/**
	 * Open an export by name.
	 *
	 * @param string $name The export's file name.
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
	 *
	 * @throws NotFoundException When the name is not an export or it is gone.
	 *
	 * @return ISimpleFile
	 */
	public function open(string $name): ISimpleFile {
		if (preg_match(self::NAME_PATTERN, $name) !== 1) {
			throw new NotFoundException('No such export.');
		}

		return $this->folder()->getFile($name);
	}//end open()

	/**
	 * Every object of one schema, page by page, in system context.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
	 *
	 * @return list<array<string,mixed>>
	 */
	private function readAll(string $slug): array {
		$objects = [];
		$offset  = 0;
		do {
			$page = $this->objectService->findAll(
				config: [
					'filters' => ['register' => 'decidiq', 'schema' => $slug],
					'limit' => self::PAGE_SIZE,
					'offset' => $offset,
				],
				_rbac: false,
				_multitenancy: false
			);
			$rows = ($page['results'] ?? $page);
			foreach ($rows as $row) {
				$data = $row;
				if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
					$data = $row->jsonSerialize();
				}

				if (is_array($data) === true) {
					$objects[] = $data;
				}
			}

			$offset += self::PAGE_SIZE;
		} while (count($rows) === self::PAGE_SIZE);

		return $objects;
	}//end readAll()

	/**
	 * The properties of a schema that point at another object.
	 *
	 * @param array<string,mixed> $schema The schema definition.
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
	 *
	 * @return array<string,string> Property name => target schema ("object" when not named).
	 */
	private function relationsOf(array $schema): array {
		$relations = [];
		foreach ((array)($schema['properties'] ?? []) as $property => $definition) {
			if (is_array($definition) === false) {
				continue;
			}

			$target = ($definition['$ref'] ?? ($definition['items']['$ref'] ?? null));
			$format = ($definition['format'] ?? ($definition['items']['format'] ?? null));
			if (is_string($target) === true && $target !== '') {
				$relations[(string)$property] = basename($target);
				continue;
			}

			if ($format === 'uuid') {
				$relations[(string)$property] = 'object';
			}
		}

		return $relations;
	}//end relationsOf()

	/**
	 * Add the files of each object to the archive under files/<schema>/<id>/.
	 *
	 * @param ZipArchive                $zip      The archive.
	 * @param string                    $slug     The schema slug.
	 * @param list<array<string,mixed>> $objects  The schema's objects.
	 * @param array<string,mixed>       $manifest The manifest, counts updated in place.
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
	 *
	 * @return void
	 */
	private function addFiles(ZipArchive $zip, string $slug, array $objects, array &$manifest): void {
		try {
			$fileService = $this->container->get('OCA\OpenRegister\Service\FileService');
		} catch (\Throwable $e) {
			$manifest['missingFiles'][] = ['schema' => $slug, 'reason' => 'OpenRegister FileService unavailable'];
			return;
		}

		foreach ($objects as $object) {
			$objectId = (string)($object['id'] ?? ($object['@self']['id'] ?? ''));
			if ($objectId === '') {
				continue;
			}

			try {
				foreach ((array)$fileService->getFiles($objectId) as $node) {
					$entry = 'files/' . $slug . '/' . $objectId . '/' . basename((string)$node->getName());
					$zip->addFromString($entry, (string)$node->getContent());
					$manifest['files']++;
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Decidiq export: files of an object could not be read', ['object' => $objectId, 'exception' => $e->getMessage()]);
				$manifest['missingFiles'][] = ['schema' => $slug, 'object' => $objectId, 'reason' => $e->getMessage()];
			}
		}
	}//end addFiles()

	/**
	 * Store the archive, then remove every older export.
	 *
	 * @param string $name The new export's name.
	 * @param string $path The archive on local disk.
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
	 *
	 * @return void
	 */
	private function keepOnly(string $name, string $path): void {
		$stream = fopen($path, 'r');
		if ($stream === false) {
			throw new RuntimeException('The export archive could not be read back.');
		}

		$folder = $this->folder();
		$folder->newFile($name, $stream);
		if (is_resource($stream) === true) {
			fclose($stream);
		}

		// Only after the new export is stored: a failed write keeps the old one.
		foreach ($folder->getDirectoryListing() as $old) {
			if ($old->getName() !== $name) {
				$old->delete();
			}
		}
	}//end keepOnly()

	/**
	 * The exports folder, created on first use.
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
	 *
	 * @return ISimpleFolder
	 */
	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException $e) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}//end folder()
}//end class
