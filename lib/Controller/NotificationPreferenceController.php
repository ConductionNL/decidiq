<?php
/**
 * Decidesk Notification Preference Controller
 *
 * REST controller for the current user's NotificationPreference object.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-7.2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\NotificationPreferenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for own NotificationPreference (read/update).
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-7.2
 */
class NotificationPreferenceController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                      $request           HTTP request
     * @param NotificationPreferenceService $preferenceService Preference service
     * @param IUserSession                  $userSession       Current user session
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-7.2
     */
    public function __construct(
        IRequest $request,
        private readonly NotificationPreferenceService $preferenceService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

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
     * Read own notification preferences.
     *
     * GET /api/notification-preference
     *
     * Per-USER scoped: the person is derived exclusively from the session —
     * the request can never name another user (no IDOR surface). The response
     * is defaults-merged and includes `accountEmail` so the UI can show the
     * Nextcloud default for governance communications.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/user-settings/spec.md
     */
    public function show(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $pref = $this->preferenceService->getPreferenceWithDefaults($user->getUID());
        $pref['accountEmail'] = $user->getEMailAddress();

        return new JSONResponse($pref);

    }//end show()

    /**
     * Update own notification preferences.
     *
     * PUT /api/notification-preference
     *
     * Per-USER scoped (session user only). Validates every new preference
     * category: reminder-time whitelist, delegation period sanity (mandatory
     * expiry, until >= from, ISO dates), governance e-mail format, urgent
     * phone shape, and the communication-language whitelist. Unknown fields
     * are ignored (field whitelisting), invalid values are rejected with 422.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/user-settings/spec.md
     */
    public function update(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $changes = [];
        $toggles = ['meetingCreated', 'votingOpened', 'decisionPublished', 'taskAssigned', 'commentMention', 'meetingReminder'];
        foreach ($toggles as $key) {
            $value = $this->request->getParam($key);
            if ($value !== null) {
                $changes[$key] = (bool) $value;
            }
        }

        $error = $this->validateDeliveryMethod(changes: $changes);
        $error = ($error ?? $this->validateReminderTimes(changes: $changes));
        $error = ($error ?? $this->validateDelegation(changes: $changes));
        $error = ($error ?? $this->validateCommunication(changes: $changes));
        if ($error !== null) {
            return new JSONResponse(['message' => $error], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        $pref = $this->preferenceService->updatePreference($user->getUID(), $changes);
        $pref['accountEmail'] = $user->getEMailAddress();
        return new JSONResponse($pref);

    }//end update()

    /**
     * Validate + collect the deliveryMethod field.
     *
     * @param array<string, mixed> $changes Accumulated validated changes (by reference)
     *
     * @return string|null An error message, or null when valid/absent
     *
     * @spec openspec/specs/user-settings/spec.md
     */
    private function validateDeliveryMethod(array &$changes): ?string
    {
        $deliveryMethod = $this->request->getParam('deliveryMethod');
        if ($deliveryMethod === null) {
            return null;
        }

        if (in_array((string) $deliveryMethod, ['in-app', 'email', 'both'], true) === false) {
            return 'Invalid deliveryMethod. Expected one of: in-app, email, both.';
        }

        $changes['deliveryMethod'] = (string) $deliveryMethod;
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
    private function validateReminderTimes(array &$changes): ?string
    {
        $reminderTimes = $this->request->getParam('reminderTimes');
        if ($reminderTimes === null) {
            return null;
        }

        if (is_array($reminderTimes) === false || $reminderTimes === []) {
            return 'Invalid reminderTimes. Expected a non-empty array of: '.implode(', ', self::REMINDER_TIMES).'.';
        }

        $clean = [];
        foreach ($reminderTimes as $time) {
            if (is_string($time) === false || in_array($time, self::REMINDER_TIMES, true) === false) {
                return 'Invalid reminderTimes entry. Expected one of: '.implode(', ', self::REMINDER_TIMES).'.';
            }

            $clean[] = $time;
        }

        $changes['reminderTimes'] = array_values(array_unique($clean));
        return null;

    }//end validateReminderTimes()

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
    private function validateDelegation(array &$changes): ?string
    {
        $delegate = $this->request->getParam('delegate');
        $from     = $this->request->getParam('delegationFrom');
        $until    = $this->request->getParam('delegationUntil');
        if ($delegate === null && $from === null && $until === null) {
            return null;
        }

        if ($delegate !== null && (string) $delegate === '') {
            // Clear the delegation entirely.
            $changes['delegate']        = null;
            $changes['delegationFrom']  = null;
            $changes['delegationUntil'] = null;
            return null;
        }

        foreach (['delegationFrom' => $from, 'delegationUntil' => $until] as $field => $value) {
            if ($value !== null && $value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) !== 1) {
                return "Invalid {$field}. Expected an ISO date (YYYY-MM-DD).";
            }
        }

        if ($delegate !== null) {
            if (is_string($delegate) === false || preg_match('/^[a-zA-Z0-9_.@\- ]{1,64}$/', $delegate) !== 1) {
                return 'Invalid delegate. Expected a Nextcloud user id.';
            }

            if ($until === null || (string) $until === '') {
                return 'A delegation requires an expiry date (delegationUntil) — delegations expire automatically.';
            }

            $changes['delegate'] = $delegate;
        }

        if ($from !== null && $until !== null && $from !== '' && $until !== '' && (string) $until < (string) $from) {
            return 'Invalid delegation period: delegationUntil must not be before delegationFrom.';
        }

        if ($from !== null) {
            $changes['delegationFrom'] = (string) $from;
            if ($changes['delegationFrom'] === '') {
                $changes['delegationFrom'] = null;
            }
        }

        if ($until !== null) {
            $changes['delegationUntil'] = (string) $until;
            if ($changes['delegationUntil'] === '') {
                $changes['delegationUntil'] = null;
            }
        }

        return null;

    }//end validateDelegation()

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
    private function validateCommunication(array &$changes): ?string
    {
        $email = $this->request->getParam('governanceEmail');
        if ($email !== null) {
            if ((string) $email === '') {
                $changes['governanceEmail'] = null;
            } else if (is_string($email) === false || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return 'Invalid governanceEmail. Expected a valid e-mail address.';
            } else {
                $changes['governanceEmail'] = $email;
            }
        }

        $phone = $this->request->getParam('urgentPhone');
        if ($phone !== null) {
            if ((string) $phone === '') {
                $changes['urgentPhone'] = null;
            } else if (is_string($phone) === false || preg_match('/^[0-9+()\/\- ]{4,32}$/', $phone) !== 1) {
                return 'Invalid urgentPhone. Expected a phone number (digits, +, -, (), spaces).';
            } else {
                $changes['urgentPhone'] = $phone;
            }
        }

        $language = $this->request->getParam('communicationLanguage');
        if ($language !== null) {
            if ((string) $language === '') {
                $changes['communicationLanguage'] = null;
            } else if (is_string($language) === false || in_array($language, self::COMMUNICATION_LANGUAGES, true) === false) {
                return 'Invalid communicationLanguage. Expected one of: '.implode(', ', self::COMMUNICATION_LANGUAGES).'.';
            } else {
                $changes['communicationLanguage'] = $language;
            }
        }

        return null;

    }//end validateCommunication()
}//end class
