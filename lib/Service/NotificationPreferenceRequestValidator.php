<?php

/**
 * Decidiq Notification Preference Request Validator
 *
 * Reads the notification-preference update request and turns it into a
 * whitelisted set of changes, or the first validation error message.
 *
 * Every category the endpoint accepts is validated here: the boolean toggles,
 * the delivery-method and reminder-time whitelists, delegation period sanity
 * (mandatory expiry, until >= from, ISO dates), governance e-mail format,
 * urgent phone shape, and the communication-language whitelist. Unknown fields
 * are ignored (field whitelisting).
 *
 * Extracted from NotificationPreferenceController, which had accumulated the
 * whole validation ruleset alongside its HTTP concerns; the controller now only
 * resolves the session user and maps an error to 422.
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
 * @spec openspec/specs/user-settings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Kernel\Contracts\NotificationPreferenceRequestValidatorInterface;
use OCP\IRequest;

/**
 * Validates and collects the notification-preference update payload.
 *
 * @spec openspec/specs/user-settings/spec.md
 */
class NotificationPreferenceRequestValidator implements NotificationPreferenceRequestValidatorInterface {
	/**
	 * Boolean notification toggles accepted by the endpoint.
	 *
	 * @var string[]
	 */
	private const TOGGLES = [
		'meetingCreated',
		'votingOpened',
		'decisionPublished',
		'taskAssigned',
		'commentMention',
		'meetingReminder',
		'agendaChanged',
	];

	/**
	 * Valid reminder-time tokens (mirrors the notification-preference schema enum).
	 *
	 * @var string[]
	 */
	private const REMINDER_TIMES = ['1h', '4h', '24h', '48h', '1w'];

	/**
	 * Valid governance communication languages (mirrors the schema enum).
	 *
	 * @var string[]
	 */
	private const COMMUNICATION_LANGUAGES = ['nl', 'en', 'de', 'fr', 'es', 'it'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	public function __construct(
		private readonly IRequest $request,
	) {

	}//end __construct()

	/**
	 * Collect every accepted field into $changes, stopping at the first error.
	 *
	 * @param array<string, mixed> $changes Accumulated validated changes (by reference)
	 *
	 * @return string|null The first error message, or null when the payload is valid
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	public function collect(array &$changes): ?string {
		$this->collectToggles(changes: $changes);

		$error = $this->validateDeliveryMethod(changes: $changes);
		$error = ($error ?? $this->validateReminderTimes(changes: $changes));
		$error = ($error ?? $this->validateDelegation(changes: $changes));

		return ($error ?? $this->validateCommunication(changes: $changes));
	}//end collect()

	/**
	 * Collect the boolean notification toggles.
	 *
	 * @param array<string, mixed> $changes Accumulated validated changes (by reference)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function collectToggles(array &$changes): void {
		foreach (self::TOGGLES as $key) {
			$value = $this->request->getParam($key);
			if ($value !== null) {
				$changes[$key] = (bool)$value;
			}
		}

	}//end collectToggles()

	/**
	 * Validate + collect the deliveryMethod field.
	 *
	 * @param array<string, mixed> $changes Accumulated validated changes (by reference)
	 *
	 * @return string|null An error message, or null when valid/absent
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function validateDeliveryMethod(array &$changes): ?string {
		$deliveryMethod = $this->request->getParam('deliveryMethod');
		if ($deliveryMethod === null) {
			return null;
		}

		if (in_array((string)$deliveryMethod, ['in-app', 'email', 'both'], true) === false) {
			return 'Invalid deliveryMethod. Expected one of: in-app, email, both.';
		}

		$changes['deliveryMethod'] = (string)$deliveryMethod;
		return null;
	}//end validateDeliveryMethod()

	/**
	 * Validate + collect the reminderTimes field.
	 *
	 * @param array<string, mixed> $changes Accumulated validated changes (by reference)
	 *
	 * @return string|null An error message, or null when valid/absent
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function validateReminderTimes(array &$changes): ?string {
		$reminderTimes = $this->request->getParam('reminderTimes');
		if ($reminderTimes === null) {
			return null;
		}

		if ($this->isNonEmptyArray(value: $reminderTimes) === false) {
			return 'Invalid reminderTimes. Expected a non-empty array of: ' . implode(', ', self::REMINDER_TIMES) . '.';
		}

		if ($this->allKnownReminderTimes(times: $reminderTimes) === false) {
			return 'Invalid reminderTimes entry. Expected one of: ' . implode(', ', self::REMINDER_TIMES) . '.';
		}

		$changes['reminderTimes'] = array_values(array_unique($reminderTimes));
		return null;
	}//end validateReminderTimes()

	/**
	 * Whether a raw request value is a non-empty array.
	 *
	 * @param mixed $value Raw request value
	 *
	 * @return bool
	 *
	 * @phpstan-assert-if-true non-empty-array<mixed> $value
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function isNonEmptyArray(mixed $value): bool {
		return is_array($value) === true && $value !== [];
	}//end isNonEmptyArray()

	/**
	 * Whether every entry is a known reminder-time token.
	 *
	 * @param array<mixed> $times Raw reminderTimes entries
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function allKnownReminderTimes(array $times): bool {
		foreach ($times as $time) {
			if (is_string($time) === false || in_array($time, self::REMINDER_TIMES, true) === false) {
				return false;
			}
		}

		return true;
	}//end allKnownReminderTimes()

	/**
	 * Validate + collect the delegation fields (delegate, delegationFrom, delegationUntil).
	 *
	 * An empty-string delegate clears the delegation (and its period). A set
	 * delegate requires an expiry date — the spec mandates automatic expiry,
	 * so an unbounded delegation is rejected.
	 *
	 * @param array<string, mixed> $changes Accumulated validated changes (by reference)
	 *
	 * @return string|null An error message, or null when valid/absent
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function validateDelegation(array &$changes): ?string {
		$request = $this->delegationRequest();
		if ($request->isAbsent() === true) {
			return null;
		}

		if ($request->clears() === true) {
			$this->clearDelegation(changes: $changes);
			return null;
		}

		return $this->collectDelegation(changes: $changes, request: $request);
	}//end validateDelegation()

	/**
	 * Read the three raw delegation fields from the request.
	 *
	 * @return DelegationRequest
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function delegationRequest(): DelegationRequest {
		return new DelegationRequest(
			delegate: $this->request->getParam('delegate'),
			from: $this->request->getParam('delegationFrom'),
			until: $this->request->getParam('delegationUntil'),
		);
	}//end delegationRequest()

	/**
	 * Clear the delegation entirely: delegate and both period bounds.
	 *
	 * @param array<string, mixed> $changes Accumulated validated changes (by reference)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function clearDelegation(array &$changes): void {
		$changes['delegate'] = null;
		$changes['delegationFrom'] = null;
		$changes['delegationUntil'] = null;
	}//end clearDelegation()

	/**
	 * Validate a set delegation and collect its delegate and bounds.
	 *
	 * @param array<string, mixed> $changes Accumulated validated changes (by reference)
	 * @param DelegationRequest    $request The raw delegation fields
	 *
	 * @return string|null An error message, or null when valid
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function collectDelegation(array &$changes, DelegationRequest $request): ?string {
		$error = $this->delegationError(request: $request);
		if ($error !== null) {
			return $error;
		}

		if ($request->delegate !== null) {
			$changes['delegate'] = $request->delegate;
		}

		$this->collectDelegationBound(changes: $changes, field: 'delegationFrom', value: $request->from);
		$this->collectDelegationBound(changes: $changes, field: 'delegationUntil', value: $request->until);

		return null;
	}//end collectDelegation()

	/**
	 * The first error of a set delegation: its period first, then its delegate.
	 *
	 * @param DelegationRequest $request The raw delegation fields
	 *
	 * @return string|null An error message, or null when valid
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function delegationError(DelegationRequest $request): ?string {
		$error = $this->validateDelegationPeriod(from: $request->from, until: $request->until);
		if ($error !== null || $request->delegate === null) {
			return $error;
		}

		return $this->validateDelegate(delegate: $request->delegate, until: $request->until);
	}//end delegationError()

	/**
	 * Validate the shape and ordering of the delegation period bounds.
	 *
	 * @param mixed $from Raw delegationFrom request value
	 * @param mixed $until Raw delegationUntil request value
	 *
	 * @return string|null An error message, or null when valid/absent
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function validateDelegationPeriod(mixed $from, mixed $until): ?string {
		$bounds = [
			'delegationFrom' => $this->periodBound(value: $from),
			'delegationUntil' => $this->periodBound(value: $until),
		];

		$error = $this->periodShapeError(bounds: $bounds);
		if ($error !== null) {
			return $error;
		}

		if ($this->periodIsReversed(bounds: $bounds) === true) {
			return 'Invalid delegation period: delegationUntil must not be before delegationFrom.';
		}

		return null;
	}//end validateDelegationPeriod()

	/**
	 * The error for the first period bound that is not an ISO date.
	 *
	 * @param array<string, string|null> $bounds Normalised bounds by field
	 *
	 * @return string|null An error message, or null when both are absent or ISO dates
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function periodShapeError(array $bounds): ?string {
		foreach ($bounds as $field => $value) {
			if ($value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
				return "Invalid {$field}. Expected an ISO date (YYYY-MM-DD).";
			}
		}

		return null;
	}//end periodShapeError()

	/**
	 * Whether both bounds are set and the end lies before the start.
	 *
	 * @param array<string, string|null> $bounds Normalised bounds by field
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function periodIsReversed(array $bounds): bool {
		$start = $bounds['delegationFrom'];
		$end = $bounds['delegationUntil'];

		return $start !== null && $end !== null && $end < $start;
	}//end periodIsReversed()

	/**
	 * Normalise one raw period bound: absent/empty becomes null, anything else a string.
	 *
	 * @param mixed $value Raw request value
	 *
	 * @return string|null The bound as a string, or null when absent/empty
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function periodBound(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}

		return (string)$value;
	}//end periodBound()

	/**
	 * Validate the delegate user id and its mandatory expiry.
	 *
	 * The spec mandates automatic expiry, so an unbounded delegation is rejected.
	 *
	 * @param mixed $delegate Raw delegate request value (known to be non-empty)
	 * @param mixed $until Raw delegationUntil request value
	 *
	 * @return string|null An error message, or null when valid
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function validateDelegate(mixed $delegate, mixed $until): ?string {
		if ($this->isUserId(value: $delegate) === false) {
			return 'Invalid delegate. Expected a Nextcloud user id.';
		}

		if ($until === null || (string)$until === '') {
			return 'A delegation requires an expiry date (delegationUntil) — delegations expire automatically.';
		}

		return null;
	}//end validateDelegate()

	/**
	 * Whether a raw value has the shape of a Nextcloud user id.
	 *
	 * @param mixed $value Raw request value
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function isUserId(mixed $value): bool {
		return is_string($value) === true && preg_match('/^[a-zA-Z0-9_.@\- ]{1,64}$/', $value) === 1;
	}//end isUserId()

	/**
	 * Record one delegation period bound, mapping an empty string onto an explicit null.
	 *
	 * Normalisation is shared with periodBound(): by the time this runs the
	 * bound has already been accepted by validateDelegationPeriod(), so it is
	 * either absent, empty (clear), or a valid ISO date.
	 *
	 * @param array<string, mixed> $changes Accumulated validated changes (by reference)
	 * @param string $field Change key to write
	 * @param mixed $value Raw request value; null leaves $changes untouched
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function collectDelegationBound(array &$changes, string $field, mixed $value): void {
		if ($value === null) {
			return;
		}

		$changes[$field] = $this->periodBound(value: $value);

	}//end collectDelegationBound()

	/**
	 * Validate + collect the communication fields (governanceEmail, urgentPhone, communicationLanguage).
	 *
	 * Empty strings clear the override back to the Nextcloud account default.
	 *
	 * @param array<string, mixed> $changes Accumulated validated changes (by reference)
	 *
	 * @return string|null An error message, or null when valid/absent
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function validateCommunication(array &$changes): ?string {
		$fields = [
			$this->governanceEmailField(),
			$this->urgentPhoneField(),
			$this->communicationLanguageField(),
		];
		foreach ($fields as $field) {
			$error = $this->collectClearableField(changes: $changes, field: $field);
			if ($error !== null) {
				return $error;
			}
		}

		return null;
	}//end validateCommunication()

	/**
	 * The governanceEmail override: a valid e-mail address.
	 *
	 * @return ClearableField
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function governanceEmailField(): ClearableField {
		return new ClearableField(
			param: 'governanceEmail',
			isValid: static fn ($value): bool => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
			errorMsg: 'Invalid governanceEmail. Expected a valid e-mail address.'
		);
	}//end governanceEmailField()

	/**
	 * The urgentPhone override: digits, +, -, (), spaces.
	 *
	 * @return ClearableField
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function urgentPhoneField(): ClearableField {
		return new ClearableField(
			param: 'urgentPhone',
			isValid: static fn ($value): bool => preg_match('/^[0-9+()\/\- ]{4,32}$/', $value) === 1,
			errorMsg: 'Invalid urgentPhone. Expected a phone number (digits, +, -, (), spaces).'
		);
	}//end urgentPhoneField()

	/**
	 * The communicationLanguage override: one of the supported languages.
	 *
	 * @return ClearableField
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function communicationLanguageField(): ClearableField {
		return new ClearableField(
			param: 'communicationLanguage',
			isValid: static fn ($value): bool => in_array($value, self::COMMUNICATION_LANGUAGES, true),
			errorMsg: 'Invalid communicationLanguage. Expected one of: ' . implode(', ', self::COMMUNICATION_LANGUAGES) . '.'
		);
	}//end communicationLanguageField()

	/**
	 * Read one optional request parameter that may also be cleared.
	 *
	 * Absent (null) leaves $changes untouched; an empty string records an explicit
	 * null so the stored override falls back to the Nextcloud account default; any
	 * other value must be a string that satisfies the field's check.
	 *
	 * @param array<string, mixed> $changes Accumulated validated changes (by reference)
	 * @param ClearableField       $field   The parameter, its check and its error message
	 *
	 * @return string|null An error message, or null when valid/absent
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function collectClearableField(array &$changes, ClearableField $field): ?string {
		$raw = $this->request->getParam($field->param);
		if ($raw === null) {
			return null;
		}

		if ((string)$raw === '') {
			$changes[$field->param] = null;
			return null;
		}

		if ($field->accepts(raw: $raw) === false) {
			return $field->errorMsg;
		}

		$changes[$field->param] = $raw;

		return null;
	}//end collectClearableField()
}//end class
