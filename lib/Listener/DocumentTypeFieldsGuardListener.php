<?php

/**
 * Refuses document details that leave a required field of their type empty,
 * or that describe a file another record already describes.
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
 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-004-a-required-field-is-enforced-on-save
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * A `digital-document` record is saved only when every required field of its
 * document type holds a value, and only one record describes a file.
 *
 * The rule lives on another object (the type), which a schema `required`
 * cannot express; the form checks it too, this guard makes it hold for the
 * API. A type that cannot be read refuses the save: a required rule that is
 * skipped when its type is unreadable is no rule.
 *
 * @template-implements IEventListener<Event>
 */
class DocumentTypeFieldsGuardListener implements IEventListener {

	/**
	 * The register every document record lives in.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The schema slug of a document record.
	 *
	 * @var string
	 */
	private const SCHEMA = 'digital-document';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param LoggerInterface        $logger        The logger.
	 *
	 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-004-a-required-field-is-enforced-on-save
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse a document record that breaks its type's required fields or
	 * duplicates another record's file.
	 *
	 * @param Event $event The event.
	 *
	 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-004-a-required-field-is-enforced-on-save
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$entity = $event instanceof ObjectUpdatingEvent ? $event->getNewObject() : $event->getObject();
		$row    = $this->row(entity: $entity);
		if ($this->isDocument(row: $row) === false) {
			return;
		}

		$message = $this->refusal(row: $row);
		if ($message === null) {
			return;
		}

		$event->setErrors(['message' => $message]);
		$event->stopPropagation();
	}//end handle()

	/**
	 * Why the record may not be saved, or null when it may.
	 *
	 * @param array<string, mixed> $row The record.
	 *
	 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-004-a-required-field-is-enforced-on-save
	 *
	 * @return string|null
	 */
	public function refusal(array $row): ?string {
		$duplicate = $this->duplicateOf(row: $row);
		if ($duplicate !== null) {
			return $duplicate;
		}

		$typeId = trim((string)($row['type'] ?? ''));
		if ($typeId === '') {
			return null;
		}

		try {
			$type = $this->objectService->find(id: $typeId, register: self::REGISTER, schema: 'document-type', _rbac: false);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: the document type of a document record could not be read', ['type' => $typeId, 'error' => $e->getMessage()]);
			return 'The details were not saved: the document type could not be read. Try again in a moment.';
		}

		if ($type === null) {
			return 'The details were not saved: the document type does not exist.';
		}

		$values  = (array)($row['typeFields'] ?? []);
		$missing = [];
		foreach ((array)(($type->jsonSerialize())['fields'] ?? []) as $field) {
			if (is_array($field) === false || ($field['required'] ?? false) !== true) {
				continue;
			}

			$key = (string)($field['key'] ?? '');
			if ($key !== '' && $this->isEmpty(value: ($values[$key] ?? null)) === true) {
				$missing[] = (string)($field['label'] ?? $key);
			}
		}

		if ($missing === []) {
			return null;
		}

		return 'The details were not saved: fill in ' . implode(', ', $missing) . '.';
	}//end refusal()

	/**
	 * A refusal when another record already describes this file.
	 *
	 * @param array<string, mixed> $row The record.
	 *
	 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-002-a-document-record-links-a-file-to-its-type-and-values
	 *
	 * @return string|null
	 */
	private function duplicateOf(array $row): ?string {
		$fileId = ($row['fileId'] ?? null);
		if (is_int($fileId) === false && (is_string($fileId) === false || ctype_digit($fileId) === false)) {
			return null;
		}

		$own = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
		try {
			$found = $this->objectService->findAll(['filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA, 'fileId' => (int)$fileId]]);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: document records could not be read', ['fileId' => $fileId, 'error' => $e->getMessage()]);
			return 'The details were not saved: the existing details could not be checked. Try again in a moment.';
		}

		foreach ($found as $other) {
			$data = (array)$other->jsonSerialize();
			$id   = (string)($data['id'] ?? ($data['@self']['id'] ?? ''));
			if ($id !== $own) {
				return 'This file already has details. Open them to change them.';
			}
		}

		return null;
	}//end duplicateOf()

	/**
	 * Whether a value counts as empty for a required field.
	 *
	 * @param mixed $value The value.
	 *
	 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-004-a-required-field-is-enforced-on-save
	 *
	 * @return bool
	 */
	private function isEmpty(mixed $value): bool {
		// The same rule as missingRequiredTypeFields() in the form: absent,
		// null or an empty text. A false tick is an answer.
		if ($value === null || $value === []) {
			return true;
		}

		return is_string($value) === true && trim($value) === '';
	}//end isEmpty()

	/**
	 * The record an entity carries.
	 *
	 * @param mixed $entity The entity.
	 *
	 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-004-a-required-field-is-enforced-on-save
	 *
	 * @return array<string, mixed>
	 */
	private function row(mixed $entity): array {
		if (is_object($entity) === false) {
			return [];
		}

		$row = [];
		if (method_exists($entity, 'getObject') === true) {
			$row = (array)$entity->getObject();
		}

		if (method_exists($entity, 'getUuid') === true && isset($row['id']) === false) {
			$row['id'] = (string)$entity->getUuid();
		}

		return $row;
	}//end row()

	/**
	 * Whether the record is a document record. The subscription already
	 * narrows to `digital-document`; without it (OpenRegister's subscription
	 * missing) only a record that links a file is judged.
	 *
	 * @param array<string, mixed> $row The record.
	 *
	 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-004-a-required-field-is-enforced-on-save
	 *
	 * @return bool
	 */
	private function isDocument(array $row): bool {
		foreach (['_schemaSlug', '_schema', 'schema'] as $key) {
			if (is_string($row[$key] ?? null) === true && $row[$key] !== '') {
				return strtolower($row[$key]) === self::SCHEMA;
			}
		}

		return array_key_exists('fileId', $row) === true;
	}//end isDocument()
}//end class
