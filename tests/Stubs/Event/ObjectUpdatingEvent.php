<?php

/**
 * Test stub for OCA\OpenRegister\Event\ObjectUpdatingEvent.
 *
 * Mirrors the real event at openregister lib/Event/ObjectUpdatingEvent.php:
 * constructed with the new object and the old one, read with getNewObject()
 * and getOldObject() (there is no getObject() on the real class), and able to
 * reject the update through stopPropagation() plus setErrors().
 *
 * This file is NOT scanned by PHPCS.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Stub implementation of ObjectUpdatingEvent for unit testing.
 */
class ObjectUpdatingEvent extends Event {
	private bool $propagationStopped = false;

	/**
	 * @var array<string, mixed>
	 */
	private array $errors = [];

	/**
	 * @var array<string, mixed>
	 */
	private array $modifiedData = [];

	public function __construct(
		private ObjectEntity $newObject,
		private ?ObjectEntity $oldObject = null,
	) {
		parent::__construct();
	}

	public function getNewObject(): ObjectEntity {
		return $this->newObject;
	}

	public function getOldObject(): ?ObjectEntity {
		return $this->oldObject;
	}

	public function isPropagationStopped(): bool {
		return $this->propagationStopped;
	}

	public function stopPropagation(): void {
		$this->propagationStopped = true;
	}

	/**
	 * @param array<string, mixed> $errors
	 */
	public function setErrors(array $errors): void {
		$this->errors = $errors;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getErrors(): array {
		return $this->errors;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function setModifiedData(array $data): void {
		$this->modifiedData = $data;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getModifiedData(): array {
		return $this->modifiedData;
	}
}
