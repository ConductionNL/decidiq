<?php

/**
 * Test stub for OCA\OpenRegister\Event\ObjectCreatingEvent.
 *
 * Defines the class in the correct namespace so that unit tests can exercise
 * SubmissionDeadlineListener without requiring the OpenRegister app to be
 * installed as a Composer dependency. Mirrors the real event's hook-rejection
 * surface (StoppableEventInterface semantics: stopPropagation + errors).
 *
 * This file is loaded by tests/bootstrap-unit.php and is NOT scanned by PHPCS.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Stub implementation of ObjectCreatingEvent for unit testing.
 */
class ObjectCreatingEvent extends Event {
	private ?ObjectEntity $object;

	private bool $propagationStopped = false;

	/**
	 * @var array<string, mixed>
	 */
	private array $errors = [];

	public function __construct(?ObjectEntity $object = null) {
		parent::__construct();
		$this->object = $object;
	}

	public function getObject(): ?ObjectEntity {
		return $this->object;
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
}
