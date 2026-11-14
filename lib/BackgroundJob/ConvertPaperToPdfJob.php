<?php

/**
 * Converts one Office paper to PDF through filinq and records the outcome on
 * its meeting or agenda item.
 *
 * @category BackgroundJob
 * @package  OCA\Decidiq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-002-a-failed-or-impossible-conversion-is-visible-and-the-original-stays
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\BackgroundJob;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Decidiq\Support\FleetAppId;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Asks filinq's PdfConversionService for a PDF of the paper, which filinq
 * writes beside the original, and records the pair (or the failure) in the
 * object's `paperRenditions`. The original is never touched. Without filinq
 * nothing is written and one info line is logged.
 *
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-002-a-failed-or-impossible-conversion-is-visible-and-the-original-stays
 */
class ConvertPaperToPdfJob extends QueuedJob {

	/**
	 * The failure text shown when filinq tried every backend.
	 *
	 * @var string
	 */
	public const NO_BACKEND = 'No backend could convert this file';

	/**
	 * The failure text shown when the conversion broke off otherwise.
	 *
	 * @var string
	 */
	public const BROKE_OFF = 'The conversion stopped before a PDF was made';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory           $time          The time factory.
	 * @param ContainerInterface     $container     The container, to reach filinq.
	 * @param IRootFolder            $rootFolder    The root folder.
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param LoggerInterface        $logger        The logger.
	 *
	 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-002-a-failed-or-impossible-conversion-is-visible-and-the-original-stays
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ContainerInterface $container,
		private readonly IRootFolder $rootFolder,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Run the conversion for the queued paper.
	 *
	 * @param mixed $argument `{fileId, objectId, schema}`.
	 *
	 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-002-a-failed-or-impossible-conversion-is-visible-and-the-original-stays
	 *
	 * @return void
	 */
	protected function run($argument): void {
		if (is_array($argument) === false) {
			return;
		}

		$this->convert(
			fileId: (int)($argument['fileId'] ?? 0),
			objectId: (string)($argument['objectId'] ?? ''),
			schema: (string)($argument['schema'] ?? '')
		);
	}//end run()

	/**
	 * Convert one paper and record the outcome.
	 *
	 * @param int    $fileId   The Nextcloud file id of the Office paper.
	 * @param string $objectId The meeting or agenda item uuid.
	 * @param string $schema   `meeting` or `agenda-item`.
	 *
	 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-002-a-failed-or-impossible-conversion-is-visible-and-the-original-stays
	 *
	 * @return void
	 */
	public function convert(int $fileId, string $objectId, string $schema): void {
		if ($fileId <= 0 || $objectId === '' || in_array($schema, ['meeting', 'agenda-item'], true) === false) {
			return;
		}

		$source = $this->rootFolder->getFirstNodeById($fileId);
		if ($source instanceof File === false) {
			return;
		}

		$converter = FleetAppId::getService($this->container, 'filinq', 'Service\PdfConversionService');
		if ($converter === null) {
			$this->logger->info('Decidiq: filinq is not installed, the Office paper stays as it is', ['fileId' => $fileId]);
			return;
		}

		if ($this->hasNewerPdf(source: $source) === true) {
			return;
		}

		$entry = $this->attempt(converter: $converter, source: $source, fileId: $fileId);
		$this->record(objectId: $objectId, schema: $schema, entry: $entry);
	}//end convert()

	/**
	 * Ask filinq for the PDF and describe the outcome as a rendition entry.
	 *
	 * @param object $converter filinq's PdfConversionService.
	 * @param File   $source    The Office paper.
	 * @param int    $fileId    The paper's file id.
	 *
	 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-002-a-failed-or-impossible-conversion-is-visible-and-the-original-stays
	 *
	 * @return array<string, mixed>
	 */
	private function attempt(object $converter, File $source, int $fileId): array {
		$now   = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$entry = ['sourceFileId' => $fileId, 'sourceName' => $source->getName()];
		try {
			$result = $converter->convertToPdfReporting($source);
			$pdf    = ($result['file'] ?? null);
			if (is_object($pdf) === false || method_exists($pdf, 'getId') === false) {
				throw new RuntimeException('filinq returned no file');
			}

			$entry['pdfFileId']   = (int)$pdf->getId();
			$entry['backend']     = (string)($result['backend'] ?? '');
			$entry['convertedAt'] = $now;
		} catch (Throwable $e) {
			$entry['failedAt'] = $now;
			$entry['failure']  = self::BROKE_OFF;
			if (FleetAppId::isInstanceOf($e, 'filinq', 'Exception\ConversionFailedException') === true) {
				$entry['failure'] = self::NO_BACKEND;
			}

			$this->logger->warning('Decidiq: an Office paper was not converted to PDF', ['fileId' => $fileId, 'error' => $e->getMessage()]);
		}

		return $entry;
	}//end attempt()

	/**
	 * Whether a PDF with the paper's base name sits beside it and is at least
	 * as new, so a rewrite of an unchanged paper does not convert again.
	 *
	 * @param File $source The Office paper.
	 *
	 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-001-an-office-paper-added-to-a-meeting-or-agenda-item-is-converted-to-pdf
	 *
	 * @return bool
	 */
	private function hasNewerPdf(File $source): bool {
		$pdfName = pathinfo($source->getName(), PATHINFO_FILENAME) . '.pdf';
		try {
			$parent = $source->getParent();
			if ($parent->nodeExists($pdfName) === false) {
				return false;
			}

			return $parent->get($pdfName)->getMTime() >= $source->getMTime();
		} catch (Throwable) {
			return false;
		}
	}//end hasNewerPdf()

	/**
	 * Write the outcome into the object's `paperRenditions`, replacing an
	 * earlier entry for the same paper.
	 *
	 * @param string               $objectId The object uuid.
	 * @param string               $schema   The object's schema slug.
	 * @param array<string, mixed> $entry    The rendition entry.
	 *
	 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-002-a-failed-or-impossible-conversion-is-visible-and-the-original-stays
	 *
	 * @return void
	 */
	private function record(string $objectId, string $schema, array $entry): void {
		try {
			$object = $this->objectService->find(id: $objectId, register: 'decidiq', schema: $schema, _rbac: false, _multitenancy: false);
			if ($object === null) {
				return;
			}

			$data       = $object->getObject();
			$renditions = [];
			foreach ((array)($data['paperRenditions'] ?? []) as $existing) {
				if (is_array($existing) === true && (int)($existing['sourceFileId'] ?? 0) !== $entry['sourceFileId']) {
					$renditions[] = $existing;
				}
			}

			$renditions[]            = $entry;
			$data['paperRenditions'] = $renditions;
			$this->objectService->saveObject(object: $data, register: 'decidiq', schema: $schema, uuid: $objectId, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: the PDF of an Office paper could not be recorded', ['objectId' => $objectId, 'error' => $e->getMessage()]);
		}//end try
	}//end record()
}//end class
