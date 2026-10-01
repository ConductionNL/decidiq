<?php

/**
 * Decidiq Destruction Certificate Renderer
 *
 * Renders OpenRegister's verklaring van vernietiging for a dossier: the
 * certificate is fetched from OpenRegister for the dossier's destruction
 * list, written out field by field as OpenRegister stored it (decidiq names
 * no field of its own and derives nothing), with the records OpenRegister
 * skipped beside it, and filed with the meeting: a PDF through filinq, or
 * markdown without it (the minutes document pattern). The dossier keeps a
 * reference to the filed copy. The dossier schema is a keep category and the
 * dossier never sits on a destruction list itself, so the copy is kept.
 *
 * @category Service
 * @package  OCA\Decidiq\Service\Records
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-006-vernietigingsverklaring-rendering
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service\Records;

use DateTimeImmutable;
use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\DossierRefusedException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\MeetingFolderService;
use OCA\Decidiq\Support\FilinqPdf;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IL10N;

/**
 * Render and file OpenRegister's destruction certificate for a dossier.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-006-vernietigingsverklaring-rendering
 */
class DestructionCertificateRenderer {
	/**
	 * The meeting subfolder the certificate is filed in.
	 */
	private const SUBFOLDER = 'Archive';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object facade
	 * @param OpenRegisterArchive    $archive       OpenRegister's certificates
	 * @param ArchivistGuard         $guard         Archivist or administrator
	 * @param FilinqPdf              $pdf           PDF through filinq, when installed
	 * @param MeetingFolderService   $folders       Files the copy with the meeting
	 * @param IL10N                  $l10n          Translations
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-006-vernietigingsverklaring-rendering
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly OpenRegisterArchive $archive,
		private readonly ArchivistGuard $guard,
		private readonly FilinqPdf $pdf,
		private readonly MeetingFolderService $folders,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Render the certificate of the dossier's destruction list and keep it.
	 *
	 * @param string $dossierId The dossier
	 *
	 * @return array<string, mixed> The dossier, with its id
	 *
	 * @throws MissingObjectException  When the dossier does not exist
	 * @throws AccessDeniedException   When the caller is not an archivist or administrator
	 * @throws DossierRefusedException When OpenRegister holds no certificate for it
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-006-vernietigingsverklaring-rendering
	 */
	public function render(string $dossierId): array {
		$this->guard->requireArchivist();
		$dossier = $this->read(schema: 'archival-dossier', id: $dossierId);
		if ($dossier === null) {
			throw new MissingObjectException(message: 'Dossier not found.');
		}

		$listUuid = $dossier['destructionList'] ?? null;
		$certificate = null;
		if (is_string($listUuid) === true && $listUuid !== '') {
			$certificate = $this->certificate(listUuid: $listUuid);
		}

		if ($certificate === null) {
			throw new DossierRefusedException(
				message: $this->l10n->t('OpenRegister has no destruction certificate for this dossier yet.'),
				reason: DossierRefusedException::CERTIFICATE_MISSING
			);
		}

		$skipped = $this->skipped(list: (array)$this->archive->destructionList(uuid: (string)$listUuid));
		$markdown = $this->markdown(certificate: $certificate, skipped: $skipped);
		$filed = $this->file(meetingId: (string)($dossier['meeting'] ?? ''), listUuid: (string)$listUuid, markdown: $markdown);

		$dossier['destructionCertificate'] = [
			'certificate' => (string)($certificate['uuid'] ?? ''),
			'destructionList' => (string)$listUuid,
			'renderedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			'format' => $filed['format'],
			'path' => $filed['path'],
			'skipped' => $skipped,
		];

		$saved = $this->objectService->saveObject(
			object: $dossier,
			register: 'decidiq',
			schema: 'archival-dossier',
			uuid: $dossierId,
			_rbac: false,
			_multitenancy: false
		);
		$stored = $saved->jsonSerialize();
		unset($stored['@self']);
		return ['id' => $dossierId] + $stored;
	}//end render()

	/**
	 * OpenRegister's certificate for the list.
	 *
	 * @param string $listUuid The destruction list
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws DossierRefusedException When OpenRegister has no destruction-list register
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-006-vernietigingsverklaring-rendering
	 */
	private function certificate(string $listUuid): ?array {
		$found = $this->archive->certificates(listUuid: $listUuid);
		if ($found['configured'] === false) {
			throw new DossierRefusedException(
				message: $this->l10n->t('OpenRegister has no register for destruction lists. Set one up in the OpenRegister settings.'),
				reason: DossierRefusedException::DESTRUCTION_UNAVAILABLE
			);
		}

		foreach ($found['results'] as $certificate) {
			if (($certificate['destructionListUuid'] ?? null) === $listUuid) {
				return $certificate;
			}
		}

		return null;
	}//end certificate()

	/**
	 * The counts OpenRegister keeps on an executed list of what it skipped.
	 *
	 * @param array<string, mixed> $list The destruction list
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-006-vernietigingsverklaring-rendering
	 */
	private function skipped(array $list): array {
		$skipped = [];
		foreach ($list as $key => $value) {
			if (str_starts_with((string)$key, 'skipped') === true && is_int($value) === true) {
				$skipped[(string)$key] = $value;
			}
		}

		return $skipped;
	}//end skipped()

	/**
	 * The certificate as markdown: every field as OpenRegister stored it,
	 * then what it skipped.
	 *
	 * @param array<string, mixed> $certificate The certificate
	 * @param array<string, int>   $skipped     The skipped counts
	 *
	 * @return string
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-006-vernietigingsverklaring-rendering
	 */
	private function markdown(array $certificate, array $skipped): string {
		$lines = ['# ' . $this->l10n->t('Destruction certificate (verklaring van vernietiging)'), ''];
		foreach ($certificate as $field => $value) {
			$lines[] = '- **' . $field . '**: ' . $this->value(value: $value);
		}

		$lines[] = '';
		$lines[] = '## ' . $this->l10n->t('Records OpenRegister skipped');
		$lines[] = '';
		foreach ($skipped as $field => $count) {
			$lines[] = '- **' . $field . '**: ' . $count;
		}

		if ($skipped === []) {
			$lines[] = '- ' . $this->l10n->t('OpenRegister reported no skipped records.');
		}

		return implode("\n", $lines) . "\n";
	}//end markdown()

	/**
	 * One certificate value as text, nested values as JSON.
	 *
	 * @param mixed $value The value
	 *
	 * @return string
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-006-vernietigingsverklaring-rendering
	 */
	private function value(mixed $value): string {
		if (is_bool($value) === true) {
			return $value === true ? 'true' : 'false';
		}

		if (is_array($value) === true && array_is_list($value) === true && array_filter($value, 'is_scalar') === $value) {
			return implode(', ', array_map('strval', $value));
		}

		if (is_scalar($value) === true || $value === null) {
			return (string)$value;
		}

		return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}//end value()

	/**
	 * File the rendering with the meeting: a PDF when filinq makes one,
	 * markdown otherwise.
	 *
	 * @param string $meetingId The dossier's meeting
	 * @param string $listUuid  The destruction list, for the file name
	 * @param string $markdown  The rendering
	 *
	 * @return array{format: string, path: string|null}
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-006-vernietigingsverklaring-rendering
	 */
	private function file(string $meetingId, string $listUuid, string $markdown): array {
		$meeting = ($this->read(schema: 'meeting', id: $meetingId) ?? ['id' => $meetingId]);
		$base = 'vernietigingsverklaring-' . $listUuid;
		$title = $this->l10n->t('Destruction certificate (verklaring van vernietiging)');

		$html = '<pre>' . htmlspecialchars($markdown, ENT_QUOTES) . '</pre>';
		$pdf = $this->pdf->fromHtml(html: $html, title: $title, context: 'the destruction certificate');
		if ($pdf !== null) {
			$path = $this->folders->writeMeetingFile(meeting: $meeting, subfolder: self::SUBFOLDER, fileName: $base . '.pdf', content: $pdf);
			if ($path !== null) {
				return ['format' => 'pdf', 'path' => $path];
			}
		}

		return [
			'format' => 'markdown',
			'path' => $this->folders->writeMeetingFile(meeting: $meeting, subfolder: self::SUBFOLDER, fileName: $base . '.md', content: $markdown),
		];
	}//end file()

	/**
	 * Read one object in system context.
	 *
	 * @param string $schema The schema slug
	 * @param string $id     The uuid
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-006-vernietigingsverklaring-rendering
	 */
	private function read(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$entity = $this->objectService->find(id: $id, register: 'decidiq', schema: $schema, _rbac: false, _multitenancy: false);
		if ($entity === null) {
			return null;
		}

		$data = $entity->jsonSerialize();
		unset($data['id'], $data['@self']);
		return $data;
	}//end read()
}//end class
