<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Decidesk Base Controller
 *
 * Abstract base controller providing shared permission helpers for all Decidesk controllers.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Abstract base controller providing shared permission helpers.
 *
 * Subclasses must set $this->userSession and $this->groupManager in their own constructors
 * before any permission checks are invoked.
 */
abstract class DecideskController extends Controller
{

    /**
     * The user session for resolving the authenticated user.
     *
     * @var IUserSession
     */
    protected IUserSession $userSession;

    /**
     * The group manager for resolving group membership.
     *
     * @var IGroupManager
     */
    protected IGroupManager $groupManager;

    /**
     * Constructor.
     *
     * @param IRequest $request The request object
     *
     * @return void
     */
    public function __construct(IRequest $request)
    {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Check whether the current user has chair or admin privileges.
     *
     * @return bool True when the user is an admin or a member of decidesk-chair
     */
    protected function isChairOrAdmin(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        $uid = $user->getUID();

        return $this->groupManager->isAdmin($uid)
            || $this->groupManager->isInGroup($uid, 'decidesk-chair');
    }//end isChairOrAdmin()
}//end class
