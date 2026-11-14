<?php

/**
 * The outcome of one member pathway action.
 *
 * @category Service
 * @package  OCA\Decidiq\Service\Pathway
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service\Pathway;

/**
 * Done, refused before anything changed, failed while writing (and rolled
 * back where it could be), or the record does not exist. Only `done` means
 * the step was completed.
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
 */
final class PathwayResult {

	/**
	 * Constructor.
	 *
	 * @param string              $outcome done, refused, failed or not-found.
	 * @param string              $message What happened, for the secretariat.
	 * @param array<string,mixed> $details What was written or skipped.
	 */
	private function __construct(
		public readonly string $outcome,
		public readonly string $message,
		public readonly array $details,
	) {
	}//end __construct()

	/**
	 * The step was completed.
	 *
	 * @param string              $message What happened.
	 * @param array<string,mixed> $details What was written.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	public static function done(string $message, array $details=[]): self {
		return new self(outcome: 'done', message: $message, details: $details);
	}//end done()

	/**
	 * Nothing was changed: a precondition does not hold.
	 *
	 * @param string $message Why.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	public static function refused(string $message): self {
		return new self(outcome: 'refused', message: $message, details: []);
	}//end refused()

	/**
	 * A write did not hold; the step stays open.
	 *
	 * @param string $message What did not hold.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	public static function failed(string $message): self {
		return new self(outcome: 'failed', message: $message, details: []);
	}//end failed()

	/**
	 * The record does not exist.
	 *
	 * @param string $message Which record.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	public static function notFound(string $message): self {
		return new self(outcome: 'not-found', message: $message, details: []);
	}//end notFound()

	/**
	 * Whether the step was completed.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	public function isDone(): bool {
		return ($this->outcome === 'done');
	}//end isDone()

	/**
	 * The HTTP status that answers this outcome.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	public function httpStatus(): int {
		return match ($this->outcome) {
			'done' => 200,
			'not-found' => 404,
			'failed' => 502,
			default => 422,
		};
	}//end httpStatus()

	/**
	 * The response body.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	public function toArray(): array {
		return ['outcome' => $this->outcome, 'message' => $this->message] + $this->details;
	}//end toArray()

}//end class
