<?php
/**
 * Decidesk Email Link Controller
 *
 * REST controller for linking Nextcloud Mail messages to governance objects.
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
 * @spec openspec/changes/p4-collaboration/tasks.md#task-6.2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\EmailLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for EmailLink endpoints.
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-6.2
 */
class EmailLinkController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request          HTTP request
     * @param EmailLinkService $emailLinkService Email link service
     * @param IUserSession     $userSession      Current user session
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-6.2
     */
    public function __construct(
        IRequest $request,
        private readonly EmailLinkService $emailLinkService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Link an email to a decision or agenda item.
     *
     * POST /api/email-links
     *
     * Body: { emailUid, mailboxId, subject, from, to, receivedAt, linkedTo, extractedText }
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-6.2
     */
    public function create(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $payload = [
            'emailUid'      => $this->request->getParam('emailUid'),
            'mailboxId'     => $this->request->getParam('mailboxId'),
            'subject'       => (string) $this->request->getParam('subject', ''),
            'from'          => $this->request->getParam('from'),
            'to'            => $this->request->getParam('to', []),
            'receivedAt'    => $this->request->getParam('receivedAt'),
            'linkedTo'      => $this->request->getParam('linkedTo'),
            'extractedText' => $this->request->getParam('extractedText'),
        ];

        try {
            $link = $this->emailLinkService->linkEmailToDecision($payload);
            return new JSONResponse($link, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

    }//end create()

    /**
     * Find linked emails by target reference.
     *
     * GET /api/email-links?linkedTo={register}:{schema}:{uuid}
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-6.2
     */
    public function index(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $target = (string) $this->request->getParam('linkedTo', '');
        if ($target === '') {
            return new JSONResponse(
                ['message' => 'Missing required query parameter: linkedTo.'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $links = $this->emailLinkService->findLinkedEmails($target);
        return new JSONResponse(['emailLinks' => $links]);

    }//end index()
}//end class
