<?php
namespace Drinks\Controller;

use Drinks\Controller\Traits\JsonResponseTrait;
use Drinks\Controller\Traits\MoneyTransferTrait;
use Drinks\Controller\Traits\OrderResponseTrait;
use Drinks\Controller\Traits\SessionUserTrait;
use Drinks\Controller\Traits\TeamEventEndpointsTrait;
use Drinks\Controller\Traits\TeamEventTrait;
use Drinks\Manager\DrinkManager;
use Drinks\Manager\DrinkOrderManager;
use Zend\Crypt\Password\Bcrypt;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\Session\Container;
use Zend\View\Model\ViewModel;

/**
 * Theke (bar tablet): login by Theken-ID, ordering, and the Kostenübersicht of team accounts.
 */
class SimpleLoginController extends AbstractActionController
{
    use JsonResponseTrait;
    use SessionUserTrait;
    use MoneyTransferTrait;
    use OrderResponseTrait;
    use TeamEventTrait;
    use TeamEventEndpointsTrait;

    /**
     * Number of hours to look back for recent orders
     */
    const RECENT_ORDERS_CUTOFF_HOURS = 2;

    /**
     * "Keep logged in" duration for the quick-login buttons
     */
    const KEEP_LOGGED_IN_HOURS = 4;

    const TEAM_SPIELTAG_NEW_OPTION = '__new__';

    public function loginAction()
    {
        $request = $this->getRequest();
        $error = null;
        $db = $this->service('Zend\Db\Adapter\Adapter');
        $this->service('Zend\Session\SessionManager')->start();
        $quickLoginSession = new Container('SimpleLoginQuick');

        if ($request->isPost() && trim((string)$request->getPost('quick_login_token', '')) !== '') {
            // Quick login: the token maps to a user only within this browser session and is single-use.
            // It never re-arms "keep logged in"; the user must still have an active, unexpired flag.
            $token = trim((string)$request->getPost('quick_login_token'));
            $tokens = is_array($quickLoginSession->tokens) ? $quickLoginSession->tokens : [];
            $quickUserId = isset($tokens[$token]) ? (int)$tokens[$token] : 0;
            unset($tokens[$token]);
            $quickLoginSession->tokens = $tokens;
            if ($quickUserId > 0) {
                $row = $db->query(
                    'SELECT user_id FROM drink_aliases WHERE user_id = ? AND enabled = 1 AND keep_logged_in = 1 AND keep_logged_in_expires > ?',
                    [$quickUserId, (new \DateTime())->format('Y-m-d H:i:s')]
                )->current();
                if ($row && $row['user_id']) {
                    $quickLoginSession->tokens = [];
                    return $this->loginAs($row['user_id']);
                }
            }
            $error = 'Schnell-Login ist abgelaufen. Bitte mit Theken-ID einloggen.';
        } elseif ($request->isPost()) {
            $alias = trim($request->getPost('alias'));
            if ($alias === '') {
                $error = 'Bitte geben Sie eine Theken-ID ein.';
            } else {
                $row = $db->query('SELECT user_id, enabled FROM drink_aliases WHERE alias = ?', [$alias])->current();
                if (!$row || !$row['user_id']) {
                    $error = 'Theken-ID nicht gefunden.';
                } elseif ((int)$row['enabled'] !== 1) {
                    $error = 'Benutzer gesperrt.';
                } else {
                    if ($request->getPost('keep_logged_in', false)) {
                        $this->setKeepLoggedIn($row['user_id'], true);
                    }
                    return $this->loginAs($row['user_id']);
                }
            }
        }

        $recentOrders = [];
        try {
            $cutoff = (new \DateTime('-' . self::RECENT_ORDERS_CUTOFF_HOURS . ' hours'))->format('Y-m-d H:i:s');
            $recentOrders = $db->query(
                'SELECT o.order_time, o.user_id, u.alias, d.name AS drink_name, o.quantity, o.deleted FROM drink_orders o JOIN bs_users u ON o.user_id = u.uid JOIN drinks d ON o.drink_id = d.id WHERE o.deleted = false AND o.order_time >= ? ORDER BY o.order_time DESC',
                [$cutoff]
            )->toArray();
        } catch (\Exception $e) {
            // Leave $recentOrders empty on error
        }

        // Users with active "keep logged in" sessions. The page only gets opaque per-session
        // tokens, never the Theken-ID (which is the login credential).
        $quickLoginUsers = [];
        $quickLoginTokens = [];
        try {
            $quickLoginRows = $db->query(
                'SELECT da.user_id, u.alias AS display_name, da.is_team
                 FROM drink_aliases da
                 LEFT JOIN bs_users u ON da.user_id = u.uid
                 WHERE da.keep_logged_in = 1 AND da.keep_logged_in_expires > ? AND da.enabled = 1
                 ORDER BY da.is_team DESC, u.alias ASC',
                [(new \DateTime())->format('Y-m-d H:i:s')]
            )->toArray();
            foreach ($quickLoginRows as $quickLoginRow) {
                $token = bin2hex(random_bytes(16));
                $quickLoginTokens[$token] = (int)$quickLoginRow['user_id'];
                $displayName = trim((string)$quickLoginRow['display_name']);
                $quickLoginUsers[] = [
                    'token' => $token,
                    'label' => $displayName !== '' ? $displayName : (!empty($quickLoginRow['is_team']) ? 'Mannschaft' : 'Benutzer'),
                    'is_team' => !empty($quickLoginRow['is_team']),
                ];
            }
        } catch (\Exception $e) {}
        $quickLoginSession->tokens = $quickLoginTokens;

        $viewModel = new ViewModel([
            'error' => $error,
            'recentOrders' => $recentOrders,
            'recentOrdersCutoffHours' => self::RECENT_ORDERS_CUTOFF_HOURS,
            'partyMode' => $this->getDrinkManager()->getPartyMode(@$this->getServiceLocator()),
            'quickLoginUsers' => $quickLoginUsers,
        ]);
        $viewModel->setTerminal(true);
        $viewModel->setTemplate('simple-login/login');
        return $viewModel;
    }

    private function loginAs($userId)
    {
        $this->getSimpleLoginSession()->user_id = $userId;
        return $this->redirect()->toRoute('user/simple-order');
    }

    /**
     * Switch the quick-login button ("keep logged in") of a user on for KEEP_LOGGED_IN_HOURS, or off.
     */
    private function setKeepLoggedIn($userId, $enabled)
    {
        try {
            $this->service('Zend\Db\Adapter\Adapter')->query(
                'UPDATE drink_aliases SET keep_logged_in = ?, keep_logged_in_expires = ? WHERE user_id = ?',
                [
                    $enabled ? 1 : 0,
                    $enabled ? (new \DateTime('+' . self::KEEP_LOGGED_IN_HOURS . ' hours'))->format('Y-m-d H:i:s') : null,
                    $userId,
                ]
            );
        } catch (\Exception $e) {
            error_log('simple-login keep_logged_in: ' . $e->getMessage());
        }
    }

    private function sendNoCacheHeaders()
    {
        $headers = $this->getResponse()->getHeaders();
        $headers->addHeaderLine('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $headers->addHeaderLine('Pragma', 'no-cache');
    }

    public function orderAction()
    {
        $this->sendNoCacheHeaders();
        $session = $this->getSimpleLoginSession();
        if (empty($session->user_id)) {
            return $this->redirect()->toRoute('user/simple-login');
        }
        $userId = (int)$session->user_id;
        $serviceManager = @$this->getServiceLocator();
        $drinkManager = $this->getDrinkManager();
        $userManager = $this->service('User\Manager\UserManager');
        $db = $this->service('Zend\Db\Adapter\Adapter');

        $row = $db->query('SELECT thekenadmin, is_team, keep_logged_in, keep_logged_in_expires FROM drink_aliases WHERE user_id = ?', [$userId])->current();
        $isTeamAccount = $row && !empty($row['is_team']);
        $keepLoggedInActive = false;
        if ($row && !empty($row['keep_logged_in']) && !empty($row['keep_logged_in_expires'])) {
            $keepLoggedInActive = new \DateTime() < new \DateTime($row['keep_logged_in_expires']);
            if (!$keepLoggedInActive) {
                $this->setKeepLoggedIn($userId, false);
            }
        }

        $currentTeamEventLabel = '';
        $availableTeamEventLabels = [];
        $currentTeamEventId = 0;
        if ($isTeamAccount) {
            list($currentTeamEventLabel, $availableTeamEventLabels) = $this->resolveSessionTeamEventSelection($userId, $session);
            $currentTeamEventId = isset($session->current_teamevent_id) ? (int)$session->current_teamevent_id : 0;
        }

        $drinkHistory = [];
        foreach ($this->service('Drinks\Manager\DrinkDepositManager')->getByUser($userId) as $deposit) {
            $drinkHistory[] = [
                'type' => 'deposit',
                'amount' => $deposit['amount'],
                'created_at' => $deposit['deposit_time'],
                'datetime' => $deposit['deposit_time'],
                'id' => $deposit['id'],
                'comment' => isset($deposit['comment']) ? trim((string)$deposit['comment']) : '',
                'teamevent_id' => isset($deposit['teamevent_id']) ? (int)$deposit['teamevent_id'] : 0,
            ];
        }
        foreach ($this->service('Drinks\Manager\DrinkOrderManager')->getByUser($userId) as $order) {
            $drinkId = isset($order['drink_id']) ? (int)$order['drink_id'] : null;
            $comment = isset($order['comment']) ? trim((string)$order['comment']) : '';
            // Sonstiges / transfers without comment show the drink name
            if (DrinkManager::isCustomPriceDrink($drinkId) && $comment === '') {
                $comment = $order['name'];
            }
            $drinkHistory[] = [
                'type' => 'order',
                'drink_id' => $drinkId,
                'name' => $order['name'],
                'quantity' => $order['quantity'],
                'price' => $order['price'],
                'total' => $order['quantity'] * $order['price'],
                'created_at' => $order['order_time'],
                'datetime' => $order['order_time'],
                'id' => $order['id'],
                'deleted' => $order['deleted'],
                'comment' => $comment,
                'teamevent_id' => isset($order['teamevent_id']) ? (int)$order['teamevent_id'] : 0,
            ];
        }
        if ($isTeamAccount && $currentTeamEventLabel !== '') {
            // Team accounts only see the selected Spieltag
            $drinkHistory = array_values(array_filter($drinkHistory, function ($entry) use ($currentTeamEventId, $currentTeamEventLabel) {
                if ($currentTeamEventId > 0 && $entry['teamevent_id'] > 0) {
                    return $entry['teamevent_id'] === $currentTeamEventId;
                }
                // Legacy entries without teamevent_id carry the Spieltag label as comment
                return $entry['teamevent_id'] === 0 && $entry['comment'] !== '' && $entry['comment'] === $currentTeamEventLabel;
            }));
        }
        usort($drinkHistory, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        $teamLeadRow = $db->query('SELECT COUNT(*) AS cnt FROM drinks_teamevents WHERE team_admin_user_id = ?', [$userId])->current();

        $viewModel = new ViewModel([
            'drinks' => $drinkManager->getAll($userId),
            'drinkHistory' => $drinkHistory,
            'userName' => $userManager->get($userId)->get('alias'),
            'currentBalance' => $drinkManager->calculateUserDrinkBalance($userId, $serviceManager),
            'pendingPaypalAmount' => $drinkManager->getPendingPaypalAmount($userId),
            'minimumAccountBalance' => $drinkManager->getMinimumAccountBalance($serviceManager),
            'error' => null,
            'success' => false,
            'drinkOrderCancelWindow' => DrinkOrderManager::CANCEL_WINDOW_SECONDS,
            'drinkCategories' => $this->service('Drinks\Manager\DrinkCategoryManager')->getAll(),
            'drinkStats' => [],
            'simpleOrderMode' => true,
            'thekenadmin' => $row && !empty($row['thekenadmin']),
            'isTeamAccount' => $isTeamAccount,
            'isTeamLead' => $teamLeadRow && (int)$teamLeadRow['cnt'] > 0,
            'isTeamMemberOfAnyEvent' => $drinkManager->isTeamEventMember($userId),
            'currentSpieltag' => $currentTeamEventLabel,
            'availableSpieltage' => $availableTeamEventLabels,
            'partyMode' => $drinkManager->getPartyMode($serviceManager),
            'moneyRecipients' => $drinkManager->getMoneyRecipients($userManager, $userId),
            'keepLoggedInActive' => $keepLoggedInActive,
        ]);
        $viewModel->setTerminal(true);
        $viewModel->setTemplate('simple-login/order');
        return $viewModel;
    }

    public function submitOrderAction()
    {
        $session = $this->getSimpleLoginSession();
        if (empty($session->user_id)) {
            return $this->jsonError(401, 'Not authenticated.');
        }
        $userId = (int)$session->user_id;

        // The order form carries the "keep logged in" checkbox
        $this->setKeepLoggedIn($userId, (bool)$this->params()->fromPost('keep_logged_in', false));

        $drinkManager = $this->getDrinkManager();
        $drinkCounts = $this->params()->fromPost('drink_counts', []);
        // No drinks: only the checkbox was updated
        if (empty($drinkCounts)) {
            return $this->jsonResponse(['success' => true, 'balance' => $drinkManager->calculateUserDrinkBalance($userId, @$this->getServiceLocator())]);
        }

        // Team accounts book on the selected open Spieltag (stored as teamevent_id only)
        $teamEventId = null;
        if ($drinkManager->isTeamAccount($userId)) {
            $selectedTeamEventLabel = $this->normalizeTeamEventLabel(isset($session->current_spieltag) ? $session->current_spieltag : '');
            if ($selectedTeamEventLabel === '') {
                list($selectedTeamEventLabel) = $this->resolveSessionTeamEventSelection($userId, $session);
            }
            $event = $selectedTeamEventLabel !== '' ? $this->getTeamEventByLabel($userId, $selectedTeamEventLabel) : null;
            if ($event && $this->isTeamEventClosedRow($event)) {
                return $this->jsonError(400, 'Der ausgewählte Spieltag ist bereits abgeschlossen. Bitte wählen Sie einen offenen Spieltag aus.');
            }
            if (!$event) {
                return $this->jsonError(400, 'Kein gültiger offener Spieltag ausgewählt.');
            }
            $teamEventId = (int)$event['id'];
            $session->current_teamevent_id = $teamEventId;
        }

        $result = $drinkManager->addOrdersAndNotify(
            $this->service('User\Manager\UserManager')->get($userId),
            $drinkCounts,
            [$this, 't'],
            @$this->getServiceLocator(),
            (int)$this->params()->fromPost('is_auto_order', 0),
            null,
            $teamEventId
        );
        return $this->orderResultResponse($result);
    }

    public function dropOrderAction()
    {
        $userId = $this->getSimpleLoginUserId();
        if (!$userId) {
            return $this->getResponse()->setStatusCode(403);
        }
        return $this->dropOrderResponse($this->service('User\Manager\UserManager')->get($userId));
    }

    /**
     * Money transfer from the Theke (password required) or from a main-site session.
     */
    public function sendMoneyAction()
    {
        if ($error = $this->rejectNonPost()) {
            return $error;
        }

        $senderUserId = $this->getSimpleLoginUserId();
        if ($senderUserId > 0) {
            // The Theke tablet is shared: confirm the transfer with the account password
            $password = (string)$this->params()->fromPost('password', '');
            if ($password === '') {
                return $this->jsonError(400, 'Bitte Passwort eingeben.');
            }
            $senderUser = $this->service('User\Manager\UserManager')->get($senderUserId);
            if (!$senderUser) {
                return $this->jsonError(404, 'Nutzer nicht gefunden.');
            }
            $bcrypt = new Bcrypt();
            $bcrypt->setCost(6);
            if (!$bcrypt->verify($password, $senderUser->need('pw'))) {
                return $this->jsonError(403, 'Passwort ist falsch.');
            }
        } else {
            $sessionUser = $this->getSessionUser();
            if (!$sessionUser) {
                return $this->jsonError(401, 'Not authenticated.');
            }
            $senderUserId = (int)$sessionUser->need('uid');
        }

        return $this->moneyTransferFromPost($senderUserId);
    }

    /**
     * GET: Spieltag selection of the logged-in team account. POST spieltag (label, id or
     * '__new__' with new_spieltag, is_medenspiel, member_user_ids): select or create one.
     */
    public function spieltagAction()
    {
        $this->sendNoCacheHeaders();
        $session = $this->getSimpleLoginSession();
        if (empty($session->user_id)) {
            return $this->jsonError(401, 'Not authenticated.');
        }
        $teamAdminUserId = (int)$session->user_id;
        if (!$this->getDrinkManager()->isTeamAccount($teamAdminUserId)) {
            return $this->jsonError(403, 'Kein Team-Account.');
        }

        if ($this->getRequest()->isPost()) {
            $selected = $this->normalizeTeamEventLabel($this->params()->fromPost('spieltag', ''));
            $isNewTeamEventRequest = ($selected === self::TEAM_SPIELTAG_NEW_OPTION || $selected === '');
            if ($isNewTeamEventRequest) {
                $selected = $this->normalizeTeamEventLabel($this->params()->fromPost('new_spieltag', ''));
            } elseif (ctype_digit($selected)) {
                // Accept numeric event IDs and normalize them to the event label.
                $eventById = $this->getTeamEventById($teamAdminUserId, (int)$selected);
                $selected = ($eventById && isset($eventById['comment'])) ? $this->normalizeTeamEventLabel((string)$eventById['comment']) : '';
            }
            if ($selected === '') {
                return $this->jsonError(400, 'Ungueltiger Spieltag.');
            }

            $event = $this->getOrCreateTeamEventByLabel($teamAdminUserId, $selected);
            if (!$event) {
                return $this->jsonError(500, 'Spieltag konnte nicht gespeichert werden.');
            }
            if ($isNewTeamEventRequest) {
                if ((int)$this->params()->fromPost('is_medenspiel', 1) === 1) {
                    try {
                        // Drink 2: Medenspiel-Pauschale
                        $this->ensureTeamEventDrinkOrderExists($teamAdminUserId, (int)$event['id'], 2, 1);
                    } catch (\Exception $e) {
                        return $this->jsonError(500, 'Medenspielpauschale konnte nicht angelegt werden.');
                    }
                }
                $this->saveTeamEventMembers($teamAdminUserId, (int)$event['id'], $this->parseTeamEventMemberIds($this->params()->fromPost('member_user_ids', '')));
            }

            $session->current_spieltag = $selected;
            $session->current_teamevent_id = (int)$event['id'];
        }

        list($currentTeamEventLabel, $availableTeamEventLabels) = $this->resolveSessionTeamEventSelection($teamAdminUserId, $session);
        return $this->jsonResponse([
            'success' => true,
            'current_spieltag' => $currentTeamEventLabel,
            'spieltage' => $availableTeamEventLabels,
            'open_spieltage' => $availableTeamEventLabels,
        ]);
    }

    /**
     * Kostenübersicht at the Theke: a team account manages its own Spieltage, any user sees
     * those they take part in. GET spieltag (id or label; defaults to the session selection).
     */
    public function teamStatsAction()
    {
        $session = $this->getSimpleLoginSession();
        if (empty($session->user_id)) {
            return $this->jsonError(401, 'Not authenticated.');
        }
        $userId = (int)$session->user_id;

        $events = $this->getVisibleTeamEvents([$userId], $userId);
        if (empty($events) && !$this->getDrinkManager()->isTeamAccount($userId)) {
            return $this->jsonError(403, 'Kein Team-Account und keine Team-Events gefunden.');
        }

        $requested = trim((string)$this->params()->fromQuery('spieltag', isset($session->current_spieltag) ? $session->current_spieltag : ''));
        try {
            $payload = $this->buildTeamStatsModalPayload(
                $events,
                ctype_digit($requested) ? (int)$requested : 0,
                $this->normalizeTeamEventLabel($requested)
            );
        } catch (\Exception $e) {
            // Details only in the server log; the Theke page is public-facing
            error_log(sprintf('simple-order team-stats: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
            return $this->jsonError(500, 'Team-Statistiken konnten nicht geladen werden.');
        }
        if (!empty($payload['team_event_id'])) {
            $session->current_teamevent_id = (int)$payload['team_event_id'];
            $session->current_spieltag = $payload['spieltag'];
        }

        return $this->jsonResponse(array_merge(['success' => true], $payload));
    }

    /**
     * Theke Kostenübersicht writes (see TeamEventEndpointsTrait), scoped to the logged-in team account.
     */
    public function teamMembersAction()
    {
        return $this->handleTeamMembersRequest();
    }

    public function teamOrderRelevanceAction()
    {
        return $this->handleOrderRelevanceRequest();
    }

    public function teamExtraCostAction()
    {
        return $this->handleExtraCostRequest();
    }

    public function teamUpdateExtraCostAction()
    {
        return $this->handleUpdateExtraCostRequest();
    }

    public function teamDeleteExtraCostAction()
    {
        return $this->handleDeleteExtraCostRequest();
    }

    public function teamGuestDonationAction()
    {
        return $this->handleGuestDonationRequest();
    }

    public function teamUpdateGuestDonationAction()
    {
        return $this->handleUpdateGuestDonationRequest();
    }

    public function teamDeleteGuestDonationAction()
    {
        return $this->handleDeleteGuestDonationRequest();
    }

    public function closeTeamEventAction()
    {
        return $this->handleCloseTeamEventRequest();
    }

    /**
     * Resolve a Spieltag of the logged-in team account.
     * Returns [teamAdminUserId, teamEventRow, actorUserId], or a JSON error response.
     */
    protected function resolveManagedTeamEvent($teamEventId, $requireOpen)
    {
        $teamAdminUserId = $this->getSimpleLoginUserId();
        if (!$teamAdminUserId) {
            return $this->jsonError(401, 'Not authenticated.');
        }
        if (!$this->getDrinkManager()->isTeamAccount($teamAdminUserId)) {
            return $this->jsonError(403, 'Kein Team-Account.');
        }
        if ((int)$teamEventId <= 0) {
            return $this->jsonError(400, 'Ungültiger Spieltag.');
        }
        $teamEvent = $this->getTeamEventById($teamAdminUserId, $teamEventId);
        if (!$teamEvent) {
            return $this->jsonError(404, 'Spieltag nicht gefunden.');
        }
        if ($requireOpen && $this->isTeamEventClosedRow($teamEvent)) {
            return $this->jsonError(400, 'Abrechnung ist beendet.');
        }
        return [$teamAdminUserId, $teamEvent, $teamAdminUserId];
    }

    /**
     * If the closed Spieltag was selected at the Theke, select the newest open one instead.
     */
    protected function afterTeamEventClosed($teamAdminUserId, $teamEvent)
    {
        $session = $this->getSimpleLoginSession();
        $closedLabel = isset($teamEvent['comment']) ? trim((string)$teamEvent['comment']) : '';
        if ($closedLabel !== '' && isset($session->current_spieltag) && trim((string)$session->current_spieltag) === $closedLabel) {
            $session->current_spieltag = '';
            $session->current_teamevent_id = 0;
            $this->resolveSessionTeamEventSelection($teamAdminUserId, $session);
        }
    }
}
