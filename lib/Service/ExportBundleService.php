<?php

/**
 * Decidiq ExportBundleService
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
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\ExportBundleException;
use OCA\Decidiq\Support\FleetAppId;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/**
 * Exports a selection of motions or decisions, or every one matching the
 * list's filter, with their attachments: one ZIP of the documents as they
 * are, or one bookmarked PDF merged by filinq.
 *
 * Every decision is read as the person who asked (OpenRegister applies its
 * read rules), and filinq reads every attachment as that person too, so an
 * export never holds a document its requester could not open.
 *
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
 */
class ExportBundleService {
	/**
	 * The most decisions one export may hold.
	 */
	public const MAX_DECISIONS = 500;

	/**
	 * The folder in the requester's Files the exports land in.
	 */
	public const FOLDER = 'Decidiq exports';

	/**
	 * The lists that may be exported, by the name the file carries.
	 */
	private const LISTS = ['Motions', 'Decisions'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister objects, read as the caller.
	 * @param ContainerInterface     $container     Resolves filinq's merge.
	 * @param ExportBundleWriter     $writer        Writes the ZIP or hands the PDF to filinq.
	 * @param IRootFolder            $rootFolder    The requester's Files.
	 * @param IUserSession           $userSession   The requester.
	 * @param ITimeFactory           $time          The date in the file name.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ContainerInterface $container,
		private readonly ExportBundleWriter $writer,
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Whether one PDF can be made here: filinq's merge service is installed.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
	 *
	 * @return bool
	 */
	public function pdfAvailable(): bool {
		return FleetAppId::getService($this->container, 'filinq', 'Service\DocumentMergeService') !== null;
	}//end pdfAvailable()

	/**
	 * Build an export.
	 *
	 * @param string              $format `pdf` or `zip`.
	 * @param string              $list   `Motions` or `Decisions`, the file's name.
	 * @param list<string>        $ids    The selected decisions; empty means "all matching the filter".
	 * @param array<string,mixed> $filter The list's filter.
	 * @param string              $search The list's search text.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
	 *
	 * @throws ExportBundleException When the export is refused; the status says why.
	 *
	 * @return array{status:string,format:string,name:string,path:string,fileId:int|null}
	 */
	public function export(string $format, string $list, array $ids, array $filter, string $search): array {
		if (in_array($format, ['pdf', 'zip'], true) === false) {
			throw new ExportBundleException(message: 'Choose one PDF or a ZIP.', status: Http::STATUS_BAD_REQUEST);
		}

		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			throw new ExportBundleException(message: 'Sign in to export.', status: Http::STATUS_UNAUTHORIZED);
		}

		if (in_array($list, self::LISTS, true) === false) {
			$list = 'Decisions';
		}

		$merger = null;
		if ($format === 'pdf') {
			$merger = FleetAppId::getService($this->container, 'filinq', 'Service\DocumentMergeService');
			if ($merger === null) {
				throw new ExportBundleException(
					message: 'One PDF needs the filinq app, which is not installed. Export a ZIP instead.',
					status: Http::STATUS_SERVICE_UNAVAILABLE
				);
			}
		}

		$decisions = $this->decisions(ids: $ids, filter: $filter, search: $search);
		if ($decisions === []) {
			throw new ExportBundleException(message: 'Nothing to export: select rows or widen the filter.', status: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		if (count($decisions) > self::MAX_DECISIONS) {
			throw new ExportBundleException(
				message: sprintf('This export would hold more than %d decisions. Narrow the filter and try again.', self::MAX_DECISIONS),
				status: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$folder = $this->exportsFolder(uid: $uid);
		$name   = $list . ' ' . $this->time->getDateTime()->format('Y-m-d');

		if ($merger === null) {
			return $this->writer->zip(folder: $folder, name: $name . '.zip', decisions: $decisions);
		}

		return $this->writer->mergedPdf(merger: $merger, folder: $folder, name: $name . '.pdf', decisions: $decisions, uid: $uid);
	}//end export()

	/**
	 * The decisions to export, in list order, read as the caller.
	 *
	 * Asks for one more than the limit, so "too many" is known without
	 * reading the whole register.
	 *
	 * @param list<string>        $ids    The selection, or empty.
	 * @param array<string,mixed> $filter The list's filter.
	 * @param string              $search The list's search text.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
	 *
	 * @return list<array<string,mixed>>
	 */
	private function decisions(array $ids, array $filter, string $search): array {
		$filters = self::listFilters(filter: $filter);

		$config = ['filters' => $filters, 'limit' => (self::MAX_DECISIONS + 1)];
		$ids    = array_values(array_filter(array_map('strval', $ids), static fn (string $id): bool => $id !== ''));
		if ($ids !== []) {
			$config = ['filters' => ['register' => 'decidiq', 'schema' => 'decision'], 'ids' => $ids, 'limit' => (self::MAX_DECISIONS + 1)];
		} else if (trim($search) !== '') {
			$config['search'] = trim($search);
		}

		$rows = $this->objectService->findAll(config: $config);

		$decisions = [];
		foreach (($rows['results'] ?? $rows) as $row) {
			$data = $row;
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$data = $row->jsonSerialize();
			}

			if (is_array($data) === true) {
				$decisions[] = $data;
			}
		}

		if ($ids === []) {
			return $decisions;
		}

		// A selection keeps the order the clerk saw.
		$position = array_flip($ids);
		usort(
			$decisions,
			static fn (array $a, array $b): int => ($position[self::idOf(decision: $a)] ?? PHP_INT_MAX)
				<=> ($position[self::idOf(decision: $b)] ?? PHP_INT_MAX)
		);

		return $decisions;
	}//end decisions()

	/**
	 * A decision's id.
	 *
	 * @param array<string,mixed> $decision The decision.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
	 *
	 * @return string
	 */
	private static function idOf(array $decision): string {
		return (string)($decision['id'] ?? $decision['@self']['id'] ?? $decision['uuid'] ?? '');
	}//end idOf()

	/**
	 * The list's filter as OpenRegister filters, without the list's own keys.
	 *
	 * @param array<string,mixed> $filter The list's filter.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
	 *
	 * @return array<string,mixed>
	 */
	private static function listFilters(array $filter): array {
		$filters = ['register' => 'decidiq', 'schema' => 'decision'];
		foreach ($filter as $key => $value) {
			$key = (string)$key;
			if ($key === '' || $key[0] === '_' || in_array($key, ['register', 'schema', 'action'], true) === true) {
				continue;
			}

			$filters[$key] = $value;
		}

		return $filters;
	}//end listFilters()

	/**
	 * The exports folder in the requester's Files, created when missing.
	 *
	 * @param string $uid The requester.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
	 *
	 * @return Folder
	 */
	private function exportsFolder(string $uid): Folder {
		$userFolder = $this->rootFolder->getUserFolder($uid);
		if ($userFolder->nodeExists(self::FOLDER) === false) {
			return $userFolder->newFolder(self::FOLDER);
		}

		$folder = $userFolder->get(self::FOLDER);
		if (($folder instanceof Folder) === false) {
			throw new ExportBundleException(
				message: '"' . self::FOLDER . '" in your Files is not a folder. Rename it and try again.',
				status: Http::STATUS_CONFLICT
			);
		}

		return $folder;
	}//end exportsFolder()

}//end class
