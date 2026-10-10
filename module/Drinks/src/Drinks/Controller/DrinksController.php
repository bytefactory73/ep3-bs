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
use Drinks\Manager\PaypalTransactionManager;
use Drinks\Service\DbSchema;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class DrinksController extends AbstractActionController
{
    use JsonResponseTrait;
    use SessionUserTrait;
    use MoneyTransferTrait;
    use OrderResponseTrait;
    use TeamEventTrait;
    use TeamEventEndpointsTrait;

    /**
     * User drinks page - shows drink menu, order history, balance
     */
    public function drinksAction()
    {
        $user = $this->getSessionUser();
        if (!$user) {
            $this->redirectBack()->setOrigin('user/drinks');
            return $this->redirect()->toRoute('user/login');
        }

        $serviceManager = @$this->getServiceLocator();
        $drinkManager = $this->getDrinkManager();
        $drinkOrderManager = $this->service('Drinks\Manager\DrinkOrderManager');
        $userManager = $this->service('User\Manager\UserManager');
        $userId = $user->need('uid');

        $drinkOrders = iterator_to_array($drinkOrderManager->getByUser($userId));
        $drinkDeposits = iterator_to_array($this->service('Drinks\Manager\DrinkDepositManager')->getByUser($userId));

        $aliasRow = $this->service('Zend\Db\Adapter\Adapter')->query('SELECT enabled, thekenadmin FROM drink_aliases WHERE user_id = ?', [$userId])->current();
        $drinksEnabled = ($aliasRow && isset($aliasRow['enabled']) && (int)$aliasRow['enabled'] === 1);
        $thekenadmin = ($aliasRow && isset($aliasRow['thekenadmin']) && (int)$aliasRow['thekenadmin'] === 1);

        // Merge and sort by date descending
        $drinkHistory = [];
        foreach ($drinkOrders as $order) {
            $drinkId = isset($order['drink_id']) ? (int)$order['drink_id'] : null;
            $quantity = isset($order['quantity']) ? (int)$order['quantity'] : 1;
            $comment = isset($order['comment']) ? trim((string)$order['comment']) : '';
            // Sonstiges / transfers without comment show the drink name
            if (DrinkManager::isCustomPriceDrink($drinkId) && $comment === '') {
                $comment = $order['name'];
            }
            $drinkHistory[] = [
                'type' => 'order',
                'id' => $order['id'],
                'name' => $order['name'],
                'drink_id' => $drinkId,
                'quantity' => $quantity,
                'price' => $order['price'],
                'total' => $quantity * $order['price'],
                'datetime' => $order['order_time'],
                'deleted' => isset($order['deleted']) ? (int)$order['deleted'] : 0,
                'comment' => $comment,
            ];
        }
        foreach ($drinkDeposits as $deposit) {
            $drinkHistory[] = [
                'type' => 'deposit',
                'amount' => $deposit['amount'],
                'datetime' => $deposit['deposit_time'],
                'createdby' => $this->getUserDisplayName($userManager, $deposit['createdbyuserid']),
                'deleted' => isset($deposit['deleted']) ? (int)$deposit['deleted'] : 0,
                'user_id_deleted' => isset($deposit['user_id_deleted']) ? $deposit['user_id_deleted'] : null,
            ];
        }
        usort($drinkHistory, function($a, $b) {
            return strcmp($b['datetime'], $a['datetime']);
        });

        $drinkStats = [];
        try {
            foreach ($drinkOrderManager->getDrinkStatsByUser($userId) as $row) {
                $drinkStats[] = [
                    'id' => isset($row['id']) ? (int)$row['id'] : null,
                    'name' => $row['name'],
                    'total_count' => $row['total_count'],
                ];
            }
        } catch (\Exception $e) {
            // In case of DB error, leave $drinkStats empty
        }

        $viewModel = new ViewModel([
            'now' => new \DateTime(),
            'drinks' => $drinkManager->getAll($userId),
            'drinkCategories' => $this->service('Drinks\Manager\DrinkCategoryManager')->getAll(),
            'drinkHistory' => $drinkHistory,
            'drinkStats' => $drinkStats,
            'userId' => $userId,
            'userName' => $user->get('alias') ?: $user->get('name'),
            'drinkOrderCancelWindow' => DrinkOrderManager::CANCEL_WINDOW_SECONDS,
            'drinksEnabled' => $drinksEnabled,
            'moneyRecipients' => $drinkManager->getMoneyRecipients($userManager, $userId),
            'thekenadmin' => $thekenadmin,
            'pendingPaypalAmount' => $drinkManager->getPendingPaypalAmount($userId),
            'minimumAccountBalance' => $drinkManager->getMinimumAccountBalance($serviceManager),
            'pendingPaypalDeposits' => $this->service('Drinks\Manager\PaypalTransactionManager')->getPendingByUser($userId),
            'partyMode' => $drinkManager->getPartyMode($serviceManager),
        ]);
        $viewModel->setTemplate('drinks.phtml');
        return $viewModel;
    }

    /**
     * AJAX: Submit order for current user
     * POST: drink_counts (JSON), is_auto_order
     * Returns JSON: { success: true, balance: X } or { error: ... }
     */
    public function submitOrderAction()
    {
        $user = $this->getSessionUser();
        if (!$user) {
            return $this->jsonError(401, 'Not authenticated.');
        }

        $result = $this->getDrinkManager()->addOrdersAndNotify(
            $user,
            $this->params()->fromPost('drink_counts', []),
            [$this, 't'],
            @$this->getServiceLocator(),
            (int)$this->params()->fromPost('is_auto_order', 0),
            $this->params()->fromPost('comment', null)
        );
        return $this->orderResultResponse($result);
    }

    /**
     * AJAX: Drop (cancel) an existing order
     * POST: order_id
     */
    public function dropOrderAction()
    {
        $user = $this->getSessionUser();
        if (!$user) {
            return $this->getResponse()->setStatusCode(403);
        }
        return $this->dropOrderResponse($user);
    }

    /**
     * AJAX: Send money to another user
     * POST: receiver_user_id, amount, team_event_id, transfer_key
     */
    public function sendMoneyAction()
    {
        if ($error = $this->rejectNonPost()) {
            return $error;
        }
        $sessionUser = $this->getSessionUser();
        if (!$sessionUser) {
            return $this->jsonError(401, 'Not authenticated.');
        }
        return $this->moneyTransferFromPost((int)$sessionUser->need('uid'));
    }

    /**
     * AJAX: open Spieltage of a team receiver for the money transfer dialog (both login kinds)
     */
    public function moneyRecipientTeamEventsAction()
    {
        if (!$this->getSessionUser() && $this->getSimpleLoginUserId() <= 0) {
            return $this->jsonError(401, 'Not authenticated.');
        }
        $receiverUserId = (int)$this->params()->fromQuery('receiver_user_id', $this->params()->fromPost('receiver_user_id', 0));
        if ($receiverUserId <= 0) {
            return $this->jsonError(400, 'Empfänger fehlt.');
        }
        if (!$this->getDrinkManager()->isTeamAccount($receiverUserId)) {
            return $this->jsonResponse(['success' => true, 'is_team' => false, 'team_events' => []]);
        }

        $teamEvents = [];
        foreach ($this->getVisibleTeamEvents([$receiverUserId], 0) as $event) {
            if (empty($event['closed'])) {
                $teamEvents[] = ['id' => $event['id'], 'label' => $event['label']];
            }
        }
        return $this->jsonResponse(['success' => true, 'is_team' => true, 'team_events' => $teamEvents]);
    }

    /**
     * AJAX: Store a new drink check event in drink_checks
     */
    public function storeCheckDateAction()
    {
        $data = json_decode($this->getRequest()->getContent(), true);
        $datetime = isset($data['datetime']) ? $this->normalizeDateTimeInput($data['datetime']) : '';
        if (!$this->getRequest()->isPost() || $datetime === '') {
            return $this->jsonResponse(['success' => false]);
        }
        $user = $this->getSessionUser();
        $userId = $user ? $user->need('uid') : ($this->getSimpleLoginUserId() ?: null);
        try {
            $this->service('Zend\Db\Adapter\Adapter')->query('INSERT INTO drink_checks (user_id, check_time) VALUES (?, ?)', [$userId, $datetime]);
            return $this->jsonResponse(['success' => true]);
        } catch (\Exception $e) {
            return $this->jsonError(500, 'DB error');
        }
    }

    /**
     * Admin: Drinks summary page
     */
    public function drinksSummaryAction()
    {
        // Backend admins, or a thekenadmin logged in at the Theke
        $user = $this->getAdminUser();
        $simpleUserId = $user ? 0 : $this->getSimpleLoginUserId();
        $isSimple = $simpleUserId > 0 && $this->getDrinkManager()->isThekenadmin($simpleUserId);
        if (!$user && !$isSimple) {
            return $this->redirect()->toRoute('user/settings');
        }
        $serviceManager = @$this->getServiceLocator();
        $drinkManager = $serviceManager->get('Drinks\Manager\DrinkManager');
        $userManager = $serviceManager->get('User\Manager\UserManager');
        $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
        $drinks = iterator_to_array($drinkManager->getAll());
        $users = $userManager->getAll('alias ASC');

        $group = $this->params()->fromQuery('group', 'date');
        $quick = $this->params()->fromQuery('quick');
        $from = $this->params()->fromQuery('from');
        $to = $this->params()->fromQuery('to');
        $showUsers = $this->params()->fromQuery('show_users', '1');
        $showEmptyCols = $this->params()->fromQuery('show_emptycols', '0');

        $lastCheckDate = null;
        $lastCheckUserName = null;
        $lastUserCheckDate = null;

        try {
            $row = $dbAdapter->query(
                'SELECT dc.user_id, dc.check_time, u.alias AS user_alias, da.alias AS drink_alias
                 FROM drink_checks dc
                 LEFT JOIN bs_users u ON u.uid = dc.user_id
                 LEFT JOIN drink_aliases da ON da.user_id = dc.user_id
                 ORDER BY dc.check_time DESC LIMIT 1', []
            )->current();
            if ($row) {
                $lastCheckDate = $row['check_time'];
                $lastCheckUserName = $row['user_alias'] ?: ($row['drink_alias'] ?: ($row['user_id'] ? 'UID ' . $row['user_id'] : null));
            }
        } catch (\Exception $e) { /* ignore */ }

        $actorUid = $user ? $user->need('uid') : $simpleUserId;
        if ($actorUid) {
            try {
                $rowMy = $dbAdapter->query('SELECT check_time FROM drink_checks WHERE user_id = ? ORDER BY check_time DESC LIMIT 1', [$actorUid])->current();
                $lastUserCheckDate = $rowMy ? $rowMy['check_time'] : null;
            } catch (\Exception $e) { /* ignore */ }
        }

        $normalize = function($dt) {
            return $dt ? $this->normalizeDateTimeInput($dt) : null;
        };
        $formatMinutesT = function($dt) use ($normalize) {
            $n = $normalize($dt);
            if (!$n) return null;
            return str_replace(' ', 'T', substr($n, 0, 16));
        };
        $lastCheckNormalized = $normalize($lastCheckDate);
        $lastUserCheckNormalized = $normalize($lastUserCheckDate);
        $lastCheckDisplay = $formatMinutesT($lastCheckNormalized);
        $lastUserCheckDisplay = $formatMinutesT($lastUserCheckNormalized);

        $relativeAge = function($dt) {
            if (!$dt) return null;
            try {
                $now = new \DateTime();
                $base = new \DateTime($dt);
            } catch (\Exception $e) { return null; }
            $diff = $now->getTimestamp() - $base->getTimestamp();
            if ($diff < 0) $diff = 0;
            $seconds = $diff;
            $minutes = (int) floor($seconds / 60);
            $hours = (int) floor($minutes / 60);
            $days = (int) floor($hours / 24);
            $minutesR = $minutes % 60;
            $hoursR = $hours % 24;
            if ($days > 0) {
                if ($days <= 14) {
                    $s = $days . 'd';
                    if ($hoursR > 0) $s .= ' ' . $hoursR . 'h';
                    return $s;
                }
                return $days . 'd';
            }
            if ($hours > 0) {
                $s = $hours . 'h';
                if ($minutesR > 0) $s .= ' ' . $minutesR . 'm';
                return $s;
            }
            if ($minutes > 0) {
                $s = $minutes . 'm';
                $secondsR = $seconds % 60;
                if ($minutes < 5 && $secondsR > 0) $s .= ' ' . $secondsR . 's';
                return $s;
            }
            return '0m';
        };

        $lastCheckRelative = $relativeAge($lastCheckNormalized);
        $lastUserCheckRelative = $relativeAge($lastUserCheckNormalized);

        if ($quick) {
            $now = new \DateTime();
            if ($quick === 'sinceLastCheck') {
                if (empty($from) && $lastCheckNormalized) {
                    $from = $lastCheckNormalized;
                }
            } elseif ($quick === 'sinceMyLastCheck') {
                if (empty($from) && $lastUserCheckNormalized) {
                    $from = $lastUserCheckNormalized;
                }
            } elseif ($quick === 'cw' || $quick === 'lw') {
                $monday = clone $now;
                $dow = (int)$monday->format('N');
                $monday->modify('-' . ($dow - 1) . ' days');
                if ($quick === 'lw') $monday->modify('-7 days');
                $sunday = clone $monday; $sunday->modify('+6 days');
                if (empty($from)) $from = $monday->format('Y-m-d') . ' 00:00:00';
                if (empty($to)) $to = $sunday->format('Y-m-d') . ' 23:59:59';
            } elseif ($quick === 'cm' || $quick === 'lm') {
                $year = (int)$now->format('Y');
                $month = (int)$now->format('n');
                if ($quick === 'lm') {
                    $month -= 1; if ($month === 0) { $month = 12; $year -= 1; }
                }
                $first = new \DateTime(sprintf('%04d-%02d-01 00:00:00', $year, $month));
                $last = clone $first; $last->modify('+1 month -1 second');
                if (empty($from)) $from = $first->format('Y-m-d H:i:s');
                if (empty($to)) $to = $last->format('Y-m-d H:i:s');
            } elseif ($quick === 'cy' || $quick === 'ly') {
                $year = (int)$now->format('Y');
                if ($quick === 'ly') $year -= 1;
                if (empty($from)) $from = sprintf('%04d-01-01 00:00:00', $year);
                if (empty($to)) $to = sprintf('%04d-12-31 23:59:59', $year);
            }
        }

        $from = $normalize($from);
        $to = $normalize($to);

        // One expression per group that already is its label: 2026-05-01, 2026-KW05 (ISO week), 2026-05, 2026
        $groupExpressions = [
            'week' => "CONCAT(LEFT(YEARWEEK(order_time, 3), 4), '-KW', RIGHT(YEARWEEK(order_time, 3), 2))",
            'month' => "DATE_FORMAT(order_time, '%Y-%m')",
            'year' => 'YEAR(order_time)',
        ];
        $groupSql = isset($groupExpressions[$group]) ? $groupExpressions[$group] : 'DATE(order_time)';
        $sql = 'SELECT ' . $groupSql . ' as grp, user_id, drink_id, SUM(quantity) as quantity, MIN(order_time) as min_time, SUM(quantity * price) as total_amount
                FROM drink_orders
                WHERE deleted = 0
                  AND drink_id <> -1';
        $params = [];
        if ($from) {
            $sql .= ' AND order_time >= ?';
            $params[] = $from;
        }
        if ($to) {
            $sql .= ' AND order_time <= ?';
            $params[] = $to;
        }
        $sql .= ' GROUP BY grp, user_id, drink_id
                ORDER BY min_time ASC, user_id ASC, drink_id ASC';
        $statement = $dbAdapter->query($sql);
        $orders = [];
        foreach ($statement->execute($params) as $row) {
            $orders[] = $row;
        }
        $orderMap = [];
        foreach ($orders as $row) {
            $grp = $row['grp'];
            $uid = $row['user_id'];
            $did = $row['drink_id'];
            $count = (int)$row['quantity'];
            $amount = isset($row['total_amount']) ? (float)$row['total_amount'] : 0;
            $orderMap[$grp][$uid][$did] = [
                'count' => $count > 0 ? $count : '',
                'amount' => $count > 0 ? number_format($amount, 2, ',', '.') : '',
            ];
        }

        $viewVars = [
            'drinks' => $drinks,
            'users' => $users,
            'orders' => $orderMap,
            'mode' => $this->params()->fromQuery('mode', 'count'),
            'from' => $from,
            'to' => $to,
            'quick' => $quick,
            'show_users' => $showUsers,
            'show_emptycols' => $showEmptyCols,
            'group' => $group,
            'lastCheckDate' => $lastCheckDate,
            'lastCheckUserName' => $lastCheckUserName,
            'lastUserCheckDate' => $lastUserCheckDate,
            'lastCheckDateDisplay' => $lastCheckDisplay,
            'lastUserCheckDateDisplay' => $lastUserCheckDisplay,
            'lastCheckRelative' => $lastCheckRelative,
            'lastUserCheckRelative' => $lastUserCheckRelative,
        ];
        if ($isSimple) {
            $viewVars['simpleOrderMode'] = true;
        }
        $viewModel = new ViewModel($viewVars);
        $viewModel->setTemplate('drinks-summary.phtml');
        return $viewModel;
    }

    /**
     * AJAX: Kostenübersicht for the main-site user panel.
     * GET team_uid (first led team, or the user's own uid with is_team_member=1), team_uids (JSON,
     * all led teams), user_uid (the user; adds Spieltage they take part in), team_event_id / spieltag.
     */
    public function teamleadTeamStatsAction()
    {
        $sessionUser = $this->getSessionUser();
        if (!$sessionUser) {
            return $this->jsonError(401, 'Not authenticated.');
        }
        $sessionUid = (int)$sessionUser->need('uid');

        $teamUid = (int)$this->params()->fromQuery('team_uid', 0);
        if ($teamUid <= 0) {
            return $this->jsonError(400, 'No team_uid provided');
        }
        $requestedTeamUids = [];
        $decoded = json_decode((string)$this->params()->fromQuery('team_uids', ''), true);
        if (is_array($decoded)) {
            $requestedTeamUids = array_values(array_unique(array_filter(array_map('intval', $decoded), function ($v) {
                return $v > 0;
            })));
        }
        $userUid = (int)$this->params()->fromQuery('user_uid', 0);

        // Authorize: thekenadmins may view any team; everyone else only the teams they lead
        // (teamlead_email match, same rule as the frontend panel) or their own member view.
        $drinkManager = $this->getDrinkManager();
        if (!$drinkManager->isThekenadmin($sessionUid)) {
            $ledTeamUids = $drinkManager->getLedTeamUids($sessionUser->get('email'));
            $authorized = ($teamUid === $sessionUid || in_array($teamUid, $ledTeamUids, true))
                && ($userUid === 0 || $userUid === $sessionUid)
                && empty(array_diff($requestedTeamUids, $ledTeamUids));
            if (!$authorized) {
                return $this->jsonError(403, 'No permission');
            }
        }

        // A team account's Spieltage are editable; a plain member only sees those they take part in.
        $managedTeamUids = !empty($requestedTeamUids) ? $requestedTeamUids : [$teamUid];
        if (!$drinkManager->isTeamAccount($teamUid)) {
            if ((int)$this->params()->fromQuery('is_team_member', 0) !== 1) {
                return $this->jsonError(400, 'Not a team account');
            }
            $managedTeamUids = [];
        }
        $events = $this->getVisibleTeamEvents($managedTeamUids, $userUid > 0 ? $userUid : $teamUid);
        if (empty($managedTeamUids) && empty($events)) {
            return $this->jsonError(400, 'Not a team account and no team events found');
        }

        $payload = $this->buildTeamStatsModalPayload(
            $events,
            (int)$this->params()->fromQuery('team_event_id', 0),
            trim((string)$this->params()->fromQuery('spieltag', ''))
        );
        return $this->jsonResponse(array_merge(['success' => true], $payload));
    }

    /**
     * Main-site Kostenübersicht writes (see TeamEventEndpointsTrait). The team is taken from the
     * Spieltag; the caller must be allowed to manage it (canManageTeam).
     */
    public function teamleadOrderRelevanceAction()
    {
        return $this->handleOrderRelevanceRequest();
    }

    public function teamleadTeamMembersAction()
    {
        return $this->handleTeamMembersRequest();
    }

    public function teamleadExtraCostAction()
    {
        return $this->handleExtraCostRequest();
    }

    public function teamleadUpdateExtraCostAction()
    {
        return $this->handleUpdateExtraCostRequest();
    }

    public function teamleadDeleteExtraCostAction()
    {
        return $this->handleDeleteExtraCostRequest();
    }

    public function teamleadGuestDonationAction()
    {
        return $this->handleGuestDonationRequest();
    }

    public function teamleadUpdateGuestDonationAction()
    {
        return $this->handleUpdateGuestDonationRequest();
    }

    public function teamleadDeleteGuestDonationAction()
    {
        return $this->handleDeleteGuestDonationRequest();
    }

    public function teamleadCloseTeamEventAction()
    {
        return $this->handleCloseTeamEventRequest();
    }

    /**
     * Admin: Drinks management page
     */
    public function drinksAdminAction()
    {
        if (!$this->getAdminUser()) {
            return $this->redirect()->toRoute('user/settings');
        }
        // The settings form shows the stored switch; 'active' also respects the time window.
        $viewModel = new ViewModel([
            'partyMode' => $this->getDrinkManager()->getPartyMode(@$this->getServiceLocator()),
        ]);
        $viewModel->setTemplate('drinks-admin.phtml');
        return $viewModel;
    }

    /**
     * Admin: Save party mode settings
     */
    public function savePartyModeAction()
    {
        if (!$this->getAdminUser()) {
            return $this->getResponse()->setStatusCode(403)->setContent('No permission');
        }
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('user/drinks-admin');
        }

        // Datetimes may be empty; anything that is not a valid date/time is dropped
        $normalizeStrict = function ($value) {
            $value = $this->normalizeDateTimeInput($value);
            return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) ? $value : '';
        };
        $start = $normalizeStrict($this->params()->fromPost('party_mode_start', ''));
        $end = $normalizeStrict($this->params()->fromPost('party_mode_end', ''));
        if ($start && $end && strtotime($end) < strtotime($start)) {
            list($start, $end) = [$end, $start];
        }

        $optionManager = $this->service('Base\Manager\OptionManager');
        $optionManager->set('party_mode.enabled', $this->params()->fromPost('party_mode_enabled') ? '1' : '0');
        $optionManager->set('party_mode.message', strip_tags(trim($this->params()->fromPost('party_mode_message', ''))));
        $optionManager->set('party_mode.start', $start);
        $optionManager->set('party_mode.end', $end);

        return $this->redirect()->toRoute('user/drinks-admin');
    }

    /**
     * Admin: Manage drinks (add/edit/delete drinks and prices)
     */
    public function manageDrinksAction()
    {
        if (!$this->getAdminUser()) {
            return $this->redirect()->toRoute('user/settings');
        }
        $dbAdapter = $this->service('Zend\Db\Adapter\Adapter');

        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            $id = (int)($post['id'] ?? 0);
            $name = trim((string)($post['name'] ?? ''));
            $price = floatval($post['price'] ?? 0);
            $categoryId = isset($post['category']) ? (int)$post['category'] : null;

            if (isset($post['add_drink']) && $name !== '' && $price > 0) {
                $dbAdapter->query('INSERT INTO drinks (name, price, image, category) VALUES (?, ?, ?, ?)', [$name, $price, $this->storeUploadedDrinkImage(), $categoryId]);
                return $this->redirect()->toRoute(null, [], ['query' => ['message' => 'Drink added successfully.']], true);
            }
            if (isset($post['edit_drink']) && $id > 0 && $name !== '' && $price > 0) {
                $imageFilename = $this->storeUploadedDrinkImage() ?: ($post['existing_image'] ?? null);
                $dbAdapter->query('UPDATE drinks SET name = ?, price = ?, image = ?, category = ? WHERE id = ?', [$name, $price, $imageFilename, $categoryId, $id]);
                return $this->redirect()->toRoute(null, [], ['query' => ['message' => 'Drink updated successfully.']], true);
            }
            if (isset($post['delete_drink']) && $id > 0) {
                $dbAdapter->query('DELETE FROM drinks WHERE id = ?', [$id]);
                return $this->redirect()->toRoute(null, [], ['query' => ['message' => 'Drink deleted successfully.']], true);
            }
        }

        $viewModel = new ViewModel([
            'drinks' => iterator_to_array($this->getDrinkManager()->getAll()),
            'drinkCategories' => $this->service('Drinks\Manager\DrinkCategoryManager')->getAll(),
            'message' => null,
        ]);
        $viewModel->setTemplate('drinks/manage-drinks');
        return $viewModel;
    }

    /**
     * Move an uploaded drink image ('image' file field) to public/imgs/branding.
     * Returns the stored filename, or null if nothing (valid) was uploaded.
     */
    private function storeUploadedDrinkImage()
    {
        $files = $this->getRequest()->getFiles()->toArray();
        if (empty($files['image']['tmp_name']) || !is_uploaded_file($files['image']['tmp_name'])) {
            return null;
        }
        $uploadDir = dirname(dirname(dirname(dirname(dirname(__DIR__))))) . '/public/imgs/branding/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }
        $imageFilename = uniqid('drink_', true) . '.' . pathinfo($files['image']['name'], PATHINFO_EXTENSION);
        $destPath = $uploadDir . DIRECTORY_SEPARATOR . $imageFilename;
        if (!@move_uploaded_file($files['image']['tmp_name'], $destPath)) {
            error_log('Drink image upload failed: ' . $destPath);
            return null;
        }
        return $imageFilename;
    }

    /**
     * Admin: Deposits management page
     */
    public function depositsAction()
    {
        $user = $this->getAdminUser();
        if (!$user) {
            return $this->redirect()->toRoute('user/settings');
        }
        $viewVariables = [
            'users' => $this->service('User\Manager\UserManager')->getAll('alias ASC'),
            'drinks' => iterator_to_array($this->getDrinkManager()->getAll()),
            'message' => null,
        ];

        $post = $this->params()->fromPost();
        if ($this->getRequest()->isPost() && isset($post['add_deposit'])) {
            $depositUserId = intval($post['deposit_user_id']);
            $depositAmount = floatval($post['deposit_amount']);
            if ($depositUserId > 0 && $depositAmount > 0) {
                $teamEventId = null;
                if ($this->getDrinkManager()->isTeamAccount($depositUserId)) {
                    $eventRow = $this->resolveTeamEventForSelection(
                        $depositUserId,
                        isset($post['deposit_teamevent_id']) ? (int)$post['deposit_teamevent_id'] : 0,
                        isset($post['deposit_new_spieltag']) ? $post['deposit_new_spieltag'] : ''
                    );
                    if (!$eventRow || empty($eventRow['id'])) {
                        $viewVariables['message'] = 'Bitte gültigen Spieltag auswählen.';
                    } else {
                        $teamEventId = (int)$eventRow['id'];
                    }
                }
                if ($viewVariables['message'] === null) {
                    $depositComment = isset($post['deposit_comment']) ? trim($post['deposit_comment']) : null;
                    $this->service('Drinks\Manager\DrinkDepositManager')->addDeposit($depositUserId, $depositAmount, $depositComment, $user->need('uid'), null, $teamEventId);
                    $this->getDrinkManager()->notifyDeposit($depositUserId, $depositAmount, $depositComment, @$this->getServiceLocator());
                    return $this->redirect()->toRoute(null, [], ['query' => ['message' => 'Deposit added.']], true);
                }
            } else {
                $viewVariables['message'] = 'Invalid deposit data.';
            }
        }

        $viewModel = new ViewModel($viewVariables);
        $viewModel->setTemplate('deposits.phtml');
        return $viewModel;
    }

    /**
     * Admin AJAX: book drinks for a user.
     * JSON body: { uid, orders: [{drink_id, count, comment?, price?}], team_event_id?, new_spieltag? }
     */
    public function addDrinkBookingAction()
    {
        $admin = $this->getAdminUser();
        if (!$admin) {
            return $this->jsonError(403, 'No permission');
        }
        if ($error = $this->rejectNonPost()) {
            return $error;
        }

        $data = json_decode($this->getRequest()->getContent(), true);
        $uid = isset($data['uid']) ? (int)$data['uid'] : 0;
        $orders = isset($data['orders']) && is_array($data['orders']) ? $data['orders'] : [];
        if (!$uid || empty($orders)) {
            return $this->jsonError(400, 'Invalid input');
        }
        $user = $this->service('User\Manager\UserManager')->get($uid, false);
        if (!$user) {
            return $this->jsonError(404, 'User not found');
        }

        $serviceManager = @$this->getServiceLocator();
        $drinkManager = $this->getDrinkManager();
        $teamEventId = null;
        if ($drinkManager->isTeamAccount($uid)) {
            $teamEvent = $this->resolveTeamEventForSelection(
                $uid,
                isset($data['team_event_id']) ? (int)$data['team_event_id'] : 0,
                isset($data['new_spieltag']) ? $data['new_spieltag'] : ''
            );
            if (!$teamEvent || empty($teamEvent['id'])) {
                return $this->jsonError(400, 'Bitte gültigen Spieltag auswählen.');
            }
            $teamEventId = (int)$teamEvent['id'];
        }

        try {
            $drinkOrderManager = $this->service('Drinks\Manager\DrinkOrderManager');
            $lines = [];
            $total = 0;
            foreach ($orders as $order) {
                $drinkId = isset($order['drink_id']) ? (int)$order['drink_id'] : 0;
                $count = isset($order['count']) ? (int)$order['count'] : 1;
                $comment = isset($order['comment']) ? $order['comment'] : null;
                if (!$drinkId || $count <= 0) {
                    continue;
                }
                $isCustomPrice = DrinkManager::isCustomPriceDrink($drinkId);
                $customPrice = ($isCustomPrice && isset($order['price'])) ? (float)$order['price'] : null;
                $drinkOrderManager->addOrder($uid, $drinkId, $count, $admin->get('uid'), 0, $comment, $customPrice, $teamEventId);

                $drink = $drinkManager->get($drinkId);
                if (!$drink) {
                    continue;
                }
                $lineTotal = $count * ($isCustomPrice ? (float)$customPrice : (float)$drink['price']);
                $lines[] = DrinkManager::formatOrderLine($drinkId, $drink['name'], $count, $comment, $lineTotal);
                $total += $lineTotal;
            }

            $balance = $drinkManager->calculateUserDrinkBalance($uid, $serviceManager);
            if ($drinkManager->shouldSendOrderEmail($uid, $balance)) {
                $adminName = $admin->get('alias') ?: $admin->get('name') ?: 'Administrator';
                $subject = 'Bestätigung Ihrer Getränkebuchung (' . $adminName . ')';
                $body = 'Folgende Buchung(en) wurden von ' . htmlspecialchars($adminName) . ' für Sie hinzugefügt:<br><br>' . implode('<br>', $lines)
                    . '<br>---------------------<br>Gesamt: ' . number_format($total, 2, ',', '.') . ' EUR<br><br>Kontostand nach Buchung: <b>' . number_format($balance, 2, ',', '.') . ' EUR</b>';
                if ($balance < 0) {
                    $body .= '<br><br>' . $drinkManager->negativeBalanceWarningHtml([$this, 't']);
                }
                $this->service('Drinks\Service\ThekeMailer')->send($user, $subject, $body, ['isHtml' => true]);
            }
        } catch (\Exception $e) {
            error_log('addDrinkBooking: ' . $e->getMessage());
            return $this->jsonError(500, 'Buchung fehlgeschlagen.');
        }
        return $this->jsonResponse(['success' => true]);
    }

    public function createTeamEventAction()
    {
        if (!$this->getAdminUser()) {
            return $this->jsonError(403, 'No permission');
        }
        if ($error = $this->rejectNonPost()) {
            return $error;
        }

        $teamAdminUserId = (int)$this->params()->fromPost('uid', 0);
        $label = $this->normalizeTeamEventLabel($this->params()->fromPost('label', ''));
        if ($teamAdminUserId <= 0 || $label === '') {
            return $this->jsonError(400, 'Spieltagname fehlt.');
        }
        if (!$this->getDrinkManager()->isTeamAccount($teamAdminUserId)) {
            return $this->jsonError(400, 'Benutzer ist kein Mannschafts-Account.');
        }

        $event = $this->getOrCreateTeamEventByLabel($teamAdminUserId, $label);
        if (!$event || empty($event['id'])) {
            return $this->jsonError(500, 'Spieltag konnte nicht angelegt werden.');
        }
        if ($this->isTeamEventClosedRow($event)) {
            return $this->jsonError(400, 'Dieser Spieltag ist bereits abgeschlossen.');
        }

        return $this->jsonResponse([
            'success' => true,
            'team_event_id' => (int)$event['id'],
            'label' => isset($event['comment']) ? trim((string)$event['comment']) : $label,
        ]);
    }

    /**
     * Admin AJAX: account data and day-grouped history of a user
     */
    public function getUserDepositsDataAction()
    {
        if (!$this->getAdminUser()) {
            return $this->jsonError(403, 'No permission');
        }
        $uid = (int)$this->params()->fromQuery('uid');
        if (!$uid) {
            return $this->jsonError(400, 'No user selected');
        }
        $userManager = $this->service('User\Manager\UserManager');
        if (!$userManager->get($uid, false)) {
            return $this->jsonError(404, 'User not found');
        }
        $dbAdapter = $this->service('Zend\Db\Adapter\Adapter');

        $aliasRow = $dbAdapter->query('SELECT enabled, alias, is_team, order_email_option, teamlead_email FROM drink_aliases WHERE user_id = ?', [$uid])->current();
        $isTeam = ($aliasRow && (int)$aliasRow['is_team'] === 1);

        $teamEvents = [];
        $currentTeamEventId = null;
        if ($isTeam) {
            $teamEvents = $this->getTeamEventsWithBalances($uid, true, true);
            $teamEventIds = array_column($teamEvents, 'id');
            $preferredTeamEventId = (int)$this->params()->fromQuery('selected_teamevent_id', 0);
            if (in_array($preferredTeamEventId, $teamEventIds, true)) {
                $currentTeamEventId = $preferredTeamEventId;
            } elseif (!empty($teamEventIds)) {
                $currentTeamEventId = $teamEventIds[0];
            }
        }

        $showStorno = $this->params()->fromQuery('showStorno') === '1';
        $orders = iterator_to_array($this->service('Drinks\Manager\DrinkOrderManager')->getByUser($uid));
        $deposits = iterator_to_array($this->service('Drinks\Manager\DrinkDepositManager')->getByUser($uid, $showStorno));

        // Spieltag badges for entries of team and individual accounts (members pay into team Spieltage too)
        $teamEventIds = array_filter(array_unique(array_map('intval', array_merge(
            array_column($deposits, 'teamevent_id'),
            array_column($orders, 'teamevent_id')
        ))));
        $teamEventsById = [];
        if (!empty($teamEventIds)) {
            try {
                $eventRows = $dbAdapter->query(
                    'SELECT ' . $this->getTeamEventSelectColumnsSql() . ' FROM drinks_teamevents WHERE id IN (' . implode(',', array_fill(0, count($teamEventIds), '?')) . ')',
                    array_values($teamEventIds)
                )->toArray();
                foreach ($eventRows as $eventRow) {
                    $label = trim((string)$eventRow['comment']);
                    if ($label !== '') {
                        $teamEventsById[(int)$eventRow['id']] = ['label' => $label, 'closed' => $this->isTeamEventClosedRow($eventRow)];
                    }
                }
            } catch (\Throwable $e) {
                // Keep history rendering stable even if event lookup fails.
            }
        }
        $spieltagFields = function ($row) use ($teamEventsById) {
            $teamEventId = isset($row['teamevent_id']) ? (int)$row['teamevent_id'] : 0;
            return [
                'teamevent_id' => $teamEventId,
                'spieltag_label' => isset($teamEventsById[$teamEventId]) ? $teamEventsById[$teamEventId]['label'] : '',
                'spieltag_closed' => isset($teamEventsById[$teamEventId]) ? $teamEventsById[$teamEventId]['closed'] : false,
            ];
        };

        $history = [];
        foreach ($deposits as $d) {
            $history[] = array_merge([
                'type' => 'Einzahlung',
                'id' => isset($d['id']) ? (int)$d['id'] : null,
                'amount' => $d['amount'],
                'desc' => $d['comment'],
                'datetime' => $d['deposit_time'],
                'deleted' => isset($d['deleted']) ? (int)$d['deleted'] : 0,
                'createdby' => $this->getUserDisplayName($userManager, $d['createdbyuserid']),
            ], $spieltagFields($d));
        }
        foreach ($orders as $o) {
            $drinkId = isset($o['drink_id']) ? (int)$o['drink_id'] : null;
            $comment = isset($o['comment']) ? trim((string)$o['comment']) : '';
            $drinkName = isset($o['name']) ? $o['name'] : '';
            $desc = $o['quantity'] . ' x ' . $drinkName;
            // Sonstiges / transfers without comment: drink name as description and comment
            if (DrinkManager::isCustomPriceDrink($drinkId) && $comment === '') {
                $desc = $drinkName;
                $comment = $drinkName;
            }
            $history[] = array_merge([
                'type' => empty($o['deleted']) ? 'Buchung' : 'Storno',
                'id' => isset($o['id']) ? (int)$o['id'] : null,
                'amount' => -1 * $o['quantity'] * $o['price'],
                'desc' => $desc,
                'datetime' => $o['order_time'],
                'deleted' => empty($o['deleted']) ? 0 : 1,
                'comment' => $comment,
                'drink_id' => $drinkId,
                'quantity' => isset($o['quantity']) ? (int)$o['quantity'] : null,
            ], $spieltagFields($o));
        }
        usort($history, function($a, $b) { return strcmp($a['datetime'], $b['datetime']); });

        // Group by day with running balance
        $days = [];
        $balance = 0;
        foreach ($history as $entry) {
            if (empty($entry['deleted'])) {
                $balance += $entry['amount'];
                $entry['balance'] = $balance;
            }
            $days[substr($entry['datetime'], 0, 10)][] = $entry;
        }
        $userHistory = [];
        foreach ($days as $date => $entries) {
            $userHistory[] = ['date' => $date, 'entries' => $entries];
        }

        $orderEmailOption = ($aliasRow && $aliasRow['order_email_option'] !== null && $aliasRow['order_email_option'] !== '') ? $aliasRow['order_email_option'] : 'order';
        return $this->jsonResponse([
            'balance' => $balance,
            'history' => $userHistory,
            'pending_paypal_deposits' => $this->service('Drinks\Manager\PaypalTransactionManager')->getPendingByUser($uid),
            'drinks_enabled' => $aliasRow ? (bool)$aliasRow['enabled'] : false,
            'drinks_alias' => $aliasRow ? $aliasRow['alias'] : null,
            'is_team' => $isTeam,
            'order_email_option' => $orderEmailOption,
            'teamlead_email' => $aliasRow ? trim((string)$aliasRow['teamlead_email']) : '',
            'team_events' => $teamEvents,
            'current_teamevent_id' => $currentTeamEventId,
        ]);
    }

    /**
     * Admin AJAX: Kostenübersicht of a team account (deposits page, Spieltage overview).
     * Admins may manage every Spieltag of the team.
     */
    public function getUserTeamEventStatsDataAction()
    {
        if (!$this->getAdminUser()) {
            return $this->jsonError(403, 'No permission');
        }
        $uid = (int)$this->params()->fromQuery('uid', 0);
        if ($uid <= 0) {
            return $this->jsonError(400, 'No user selected');
        }
        if (!$this->getDrinkManager()->isTeamAccount($uid)) {
            return $this->jsonError(400, 'Selected user is not a team account');
        }

        $payload = $this->buildTeamStatsModalPayload(
            $this->getVisibleTeamEvents([$uid], 0),
            (int)$this->params()->fromQuery('team_event_id', 0),
            ''
        );
        return $this->jsonResponse(array_merge(['success' => true, 'team_uid' => $uid], $payload));
    }

    /**
     * Admin AJAX: move a deposit or order of a team account to another open Spieltag
     */
    public function updateUserHistoryTeamEventAction()
    {
        if (!$this->getAdminUser()) {
            return $this->jsonError(403, 'No permission');
        }
        if ($error = $this->rejectNonPost()) {
            return $error;
        }

        $uid = (int)$this->params()->fromPost('uid', 0);
        $entryId = (int)$this->params()->fromPost('entry_id', 0);
        $entryType = trim((string)$this->params()->fromPost('entry_type', ''));
        $teamEventId = (int)$this->params()->fromPost('team_event_id', 0);
        $tables = ['deposit' => ['drink_deposits', 'Einzahlung nicht gefunden.'], 'order' => ['drink_orders', 'Buchung nicht gefunden.']];
        if ($uid <= 0 || $entryId <= 0 || $teamEventId <= 0 || !isset($tables[$entryType])) {
            return $this->jsonError(400, 'Ungültige Eingabe.');
        }
        if (!$this->getDrinkManager()->isTeamAccount($uid)) {
            return $this->jsonError(400, 'Benutzer ist kein Mannschafts-Account.');
        }

        $teamEvent = $this->getTeamEventById($uid, $teamEventId);
        if (!$teamEvent) {
            return $this->jsonError(404, 'Spieltag nicht gefunden.');
        }
        if ($this->isTeamEventClosedRow($teamEvent)) {
            return $this->jsonError(400, 'Abgeschlossene Spieltage können nicht ausgewählt werden.');
        }

        list($table, $notFoundMessage) = $tables[$entryType];
        $dbAdapter = $this->service('Zend\Db\Adapter\Adapter');
        if (!$dbAdapter->query('SELECT id FROM ' . $table . ' WHERE id = ? AND user_id = ? LIMIT 1', [$entryId, $uid])->current()) {
            return $this->jsonError(404, $notFoundMessage);
        }
        $dbAdapter->query('UPDATE ' . $table . ' SET teamevent_id = ? WHERE id = ? AND user_id = ?', [$teamEventId, $entryId, $uid]);

        return $this->jsonResponse([
            'success' => true,
            'team_event_id' => $teamEventId,
            'team_event_label' => isset($teamEvent['comment']) ? trim((string)$teamEvent['comment']) : '',
        ]);
    }

    /**
     * Admin AJAX: cancel or restore a deposit or order (both sides of a money transfer) and
     * notify the affected users.
     */
    public function toggleDepositOrderDeletedAction()
    {
        $admin = $this->getAdminUser();
        if (!$admin) {
            return $this->jsonError(403, 'No permission');
        }
        if ($error = $this->rejectNonPost()) {
            return $error;
        }
        $entryId = (int)$this->params()->fromPost('entry_id');
        $entryType = $this->params()->fromPost('entry_type');
        if (!$entryId || !$entryType) {
            return $this->jsonError(400, 'No entry_id or entry_type');
        }
        $tables = ['deposit' => ['drink_deposits', 'drink_orders'], 'order' => ['drink_orders', 'drink_deposits']];
        if (!isset($tables[$entryType])) {
            return $this->jsonError(400, 'Invalid entry_type');
        }
        list($table, $counterTable) = $tables[$entryType];

        $dbAdapter = $this->service('Zend\Db\Adapter\Adapter');
        $row = $dbAdapter->query('SELECT * FROM ' . $table . ' WHERE id = ?', [$entryId])->current();
        if (!$row) {
            return $this->jsonError(404, 'Entry not found');
        }

        $newDeleted = empty($row['deleted']) ? 1 : 0;
        $deletedBy = $newDeleted ? $admin->get('uid') : null;
        $transferReference = (DbSchema::hasTransferReferenceColumns($dbAdapter) && isset($row['transfer_reference'])) ? trim((string)$row['transfer_reference']) : '';
        $dbAdapter->query('UPDATE ' . $table . ' SET deleted = ?, user_id_deleted = ? WHERE id = ?', [$newDeleted, $deletedBy, $entryId]);
        if ($transferReference !== '') {
            $dbAdapter->query('UPDATE ' . $counterTable . ' SET deleted = ?, user_id_deleted = ? WHERE transfer_reference = ?', [$newDeleted, $deletedBy, $transferReference]);
        }

        $serviceManager = @$this->getServiceLocator();
        $userManager = $this->service('User\Manager\UserManager');
        $mailer = $this->service('Drinks\Service\ThekeMailer');
        $drinkManager = $this->getDrinkManager();
        $action = $newDeleted ? 'storniert' : 'wiederhergestellt';
        $adminName = htmlspecialchars($admin->get('alias') ?: $admin->get('name') ?: 'Administrator');
        $balanceLine = function ($userId) use ($drinkManager, $serviceManager) {
            return '<br><br>Kontostand nach Änderung: <b>' . number_format($drinkManager->calculateUserDrinkBalance($userId, $serviceManager), 2, ',', '.') . ' EUR</b>';
        };

        $user = $userManager->get($row['user_id'], false);
        if ($user && $entryType === 'deposit') {
            if ($transferReference !== '') {
                $subject = 'Geldüberweisung ' . ucfirst($action);
                $body = 'Eine Geldüberweisung auf Ihr Konto wurde von ' . $adminName . ' ' . $action . '.';
            } else {
                $subject = 'Einzahlung ' . ucfirst($action);
                $body = 'Ihre Einzahlung am ' . $row['deposit_time'] . ' wurde von ' . $adminName . ' ' . $action . '.';
            }
            $mailer->send($user, $subject, $body . $balanceLine($row['user_id']), ['isHtml' => true]);
        } elseif ($user && $drinkManager->shouldSendOrderEmail($row['user_id'], $drinkManager->calculateUserDrinkBalance($row['user_id'], $serviceManager))) {
            $drink = $drinkManager->get($row['drink_id']);
            $drinkName = $drink ? $drink['name'] : $row['drink_id'];
            $label = DrinkManager::isCustomPriceDrink($row['drink_id'])
                ? DrinkManager::formatCustomEntryLabel($row['quantity'], $row['comment'], $drinkName)
                : $row['quantity'] . 'x ' . $drinkName;
            if ($transferReference !== '' || (int)$row['drink_id'] === -1) {
                $subject = 'Geldüberweisung ' . ucfirst($action);
                $body = 'Ihre Geldüberweisung (' . $label . ') wurde von ' . $adminName . ' ' . $action . '.';
            } else {
                $subject = 'Buchung ' . ucfirst($action) . ' (Admin)';
                $body = 'Ihre Getränkebuchung (' . $label . ') am ' . $row['order_time'] . ' wurde von ' . $adminName . ' ' . $action . '.';
            }
            $mailer->send($user, $subject, $body . $balanceLine($row['user_id']), ['isHtml' => true]);
        }

        // The other side of a money transfer
        if ($transferReference !== '') {
            $counterRow = $dbAdapter->query('SELECT user_id FROM ' . $counterTable . ' WHERE transfer_reference = ? LIMIT 1', [$transferReference])->current();
            $counterUser = ($counterRow && (int)$counterRow['user_id'] !== (int)$row['user_id']) ? $userManager->get($counterRow['user_id'], false) : null;
            if ($counterUser) {
                $counterBody = 'Eine Geldüberweisung, die Ihr Konto betrifft, wurde von ' . $adminName . ' ' . $action . '.' . $balanceLine($counterRow['user_id']);
                $mailer->send($counterUser, 'Geldüberweisung ' . ucfirst($action), $counterBody, ['isHtml' => true]);
            }
        }

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * Admin AJAX: update the drink account settings of a user (only the fields sent).
     * POST: uid, drinks_enabled, drinks_alias, order_email_option, teamlead_email, is_team
     */
    public function setUserDrinksSettingsAction()
    {
        if (!$this->getAdminUser()) {
            return $this->jsonError(403, 'No permission');
        }
        if ($error = $this->rejectNonPost()) {
            return $error;
        }
        $uid = (int)$this->params()->fromPost('uid');
        if (!$uid) {
            return $this->jsonError(400, 'No user selected');
        }
        $isTrue = function ($value) {
            return $value === '1' || $value === 1 || $value === true || $value === 'true';
        };

        $values = [];
        $drinksEnabled = $this->params()->fromPost('drinks_enabled', null);
        if ($drinksEnabled !== null) {
            $values['enabled'] = $isTrue($drinksEnabled) ? 1 : 0;
        }
        $drinksAlias = $this->params()->fromPost('drinks_alias', null);
        if ($drinksAlias !== null) {
            // Empty or up to 50 word characters, dashes and spaces
            $drinksAlias = trim($drinksAlias);
            if ($drinksAlias !== '' && !preg_match('/^[\w\-\s]{1,50}$/u', $drinksAlias)) {
                return $this->jsonError(400, 'Invalid alias');
            }
            $values['alias'] = $drinksAlias;
        }
        $orderEmailOption = $this->params()->fromPost('order_email_option', null);
        if ($orderEmailOption !== null) {
            $orderEmailOption = trim((string)$orderEmailOption);
            if (!in_array($orderEmailOption, ['order', 'summary', 'negative'], true)) {
                return $this->jsonError(400, 'Invalid order email option');
            }
            $values['order_email_option'] = $orderEmailOption;
        }
        $teamleadEmail = $this->params()->fromPost('teamlead_email', null);
        if ($teamleadEmail !== null) {
            $normalizedEmails = [];
            foreach (preg_split('/[;,]+/', trim((string)$teamleadEmail)) as $rawEmail) {
                $email = strtolower(trim((string)$rawEmail));
                if ($email === '') {
                    continue;
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return $this->jsonError(400, 'Invalid teamlead email address');
                }
                $normalizedEmails[$email] = $email;
            }
            $values['teamlead_email'] = implode(', ', $normalizedEmails);
        }
        $isTeam = $this->params()->fromPost('is_team', null);
        if ($isTeam !== null) {
            $values['is_team'] = $isTrue($isTeam) ? 1 : 0;
        }

        $dbAdapter = $this->service('Zend\Db\Adapter\Adapter');
        try {
            $exists = (bool)$dbAdapter->query('SELECT user_id FROM drink_aliases WHERE user_id = ?', [$uid])->current();
            if (!$exists) {
                if (empty($values)) {
                    return $this->jsonError(400, 'Alias, enabled, and thekenadmin required for new entry');
                }
                $values = array_merge(['alias' => null, 'enabled' => 0, 'thekenadmin' => 0, 'is_team' => 0, 'order_email_option' => 'order', 'teamlead_email' => ''], $values);
            }
            if (!empty($values)) {
                $columns = array_keys($values);
                $updates = array_map(function ($column) {
                    return $column . ' = VALUES(' . $column . ')';
                }, $columns);
                $dbAdapter->query(
                    'INSERT INTO drink_aliases (user_id, ' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns) + 1, '?')) . ')'
                    . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates),
                    array_merge([$uid], array_values($values))
                );
            }
        } catch (\Exception $e) {
            error_log('setUserDrinksSettings: ' . $e->getMessage());
            return $this->jsonError(500, 'DB error');
        }
        return $this->jsonResponse(['success' => true]);
    }

    /**
     * Admin: Balance list - overview of all users with balances
     */
    public function balanceListAction()
    {
        if (!$this->getAdminUser()) {
            return $this->redirect()->toRoute('user/settings');
        }
        $serviceManager = @$this->getServiceLocator();
        $userManager = $serviceManager->get('User\Manager\UserManager');
        $drinkOrderManager = $serviceManager->get('Drinks\Manager\DrinkOrderManager');
        $drinkDepositManager = $serviceManager->get('Drinks\Manager\DrinkDepositManager');
        $drinkManager = $this->getDrinkManager();

        $users = $userManager->getAll('alias ASC');
        $userList = [];
        foreach ($users as $u) {
            $uid = $u->get('uid');
            $balance = $drinkManager->calculateUserDrinkBalance($uid, $serviceManager);
            $deposits = iterator_to_array($drinkDepositManager->getByUser($uid, true));
            $lastDeposit = null;
            foreach ($deposits as $d) {
                if (empty($d['deleted'])) {
                    if (!$lastDeposit || (isset($d['deposit_time']) && $d['deposit_time'] > $lastDeposit)) {
                        $lastDeposit = $d['deposit_time'];
                    }
                }
            }
            $orders = iterator_to_array($drinkOrderManager->getByUser($uid));
            $lastOrder = null;
            $ordersTotal = 0.0;
            foreach ($orders as $o) {
                if (empty($o['deleted'])) {
                    if ((int)($o['drink_id'] ?? 0) === -1) {
                        continue;
                    }
                    if (!$lastOrder || (isset($o['order_time']) && $o['order_time'] > $lastOrder)) {
                        $lastOrder = $o['order_time'];
                    }
                    if (isset($o['price'])) {
                        $qty = isset($o['quantity']) ? (float)$o['quantity'] : 1;
                        $ordersTotal += ((float)$o['price']) * $qty;
                    }
                }
            }
            $lastActivity = null;
            if ($lastDeposit && $lastOrder) {
                $lastActivity = max($lastDeposit, $lastOrder);
            } elseif ($lastDeposit) {
                $lastActivity = $lastDeposit;
            } elseif ($lastOrder) {
                $lastActivity = $lastOrder;
            }
            if (count($deposits) > 0 || count($orders) > 0) {
                $userList[] = [
                    'uid' => $u->get('uid'),
                    'alias' => $u->get('alias'),
                    'name' => $u->get('name'),
                    'email' => $u->get('email'),
                    'balance' => $balance,
                    'last_activity' => $lastActivity,
                    'orders_total' => $ordersTotal,
                ];
            }
        }

        usort($userList, function($a, $b) {
            return $a['balance'] <=> $b['balance'];
        });

        $viewModel = new ViewModel([
            'users' => $userList,
            'total_balance' => array_sum(array_column($userList, 'balance')),
        ]);
        $viewModel->setTemplate('balance-list.phtml');
        return $viewModel;
    }

    /**
     * Admin: Deposit overview showing all member deposits with balance after deposit
     */
    public function depositOverviewAction()
    {
        if (!$this->getAdminUser()) {
            return $this->redirect()->toRoute('user/settings');
        }
        $serviceManager = @$this->getServiceLocator();
        $userManager = $serviceManager->get('User\Manager\UserManager');
        $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
        DbSchema::ensureUtf8mb4($dbAdapter);

        $users = $userManager->getAll('alias ASC');
        $userMap = [];
        foreach ($users as $u) {
            $uid = $u->get('uid');
            $display = $u->get('alias') ?: $u->get('name');
            $userMap[$uid] = [
                'display' => $display,
                'email' => $u->get('email'),
            ];
        }

        $paypalLinkedUsersByEmail = [];
        try {
            $paypalLinkedRows = $dbAdapter->query(
                'SELECT payer_email, linked_user_id FROM drinks_paypal WHERE payer_email IS NOT NULL AND payer_email != "" AND linked_user_id IS NOT NULL',
                []
            )->toArray();
            foreach ($paypalLinkedRows as $paypalLinkedRow) {
                $emailKey = strtolower(trim((string)$paypalLinkedRow['payer_email']));
                $linkedUserId = (int)$paypalLinkedRow['linked_user_id'];
                if ($emailKey !== '' && $linkedUserId > 0) {
                    $paypalLinkedUsersByEmail[$emailKey][] = $linkedUserId;
                }
            }
            foreach ($paypalLinkedUsersByEmail as $emailKey => $linkedUserIds) {
                $paypalLinkedUsersByEmail[$emailKey] = array_values(array_unique(array_map('intval', $linkedUserIds)));
            }
        } catch (\Throwable $e) {
            // Keep the overview functional if historical links cannot be read.
        }

        $showTransfers = $this->params()->fromQuery('showTransfers', '0') === '1';
        $quickRange = trim((string)$this->params()->fromQuery('quick', ''));
        $fromFilterRaw = trim((string)$this->params()->fromQuery('from', ''));
        $toFilterRaw = trim((string)$this->params()->fromQuery('to', ''));
        $fromFilter = null;
        $toFilter = null;

        if ($quickRange === '' && $fromFilterRaw === '' && $toFilterRaw === '') {
            $quickRange = 'l31d';
            $toFilter = new \DateTime();
            $fromFilter = clone $toFilter;
            $fromFilter->modify('-30 days');
            $fromFilterRaw = $fromFilter->format('Y-m-d');
            $toFilterRaw = $toFilter->format('Y-m-d');
        }

        if ($fromFilter === null && $fromFilterRaw !== '') {
            try {
                $fromFilter = new \DateTime($fromFilterRaw);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromFilterRaw)) {
                    $fromFilter->setTime(0, 0, 0);
                }
            } catch (\Throwable $e) {
                $fromFilter = null;
            }
        }

        if ($toFilter === null && $toFilterRaw !== '') {
            try {
                $toFilter = new \DateTime($toFilterRaw);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $toFilterRaw)) {
                    $toFilter->setTime(23, 59, 59);
                }
            } catch (\Throwable $e) {
                $toFilter = null;
            }
        }

        $fromFilterSql = $fromFilter ? $fromFilter->format('Y-m-d H:i:s') : '';
        $toFilterSql = $toFilter ? $toFilter->format('Y-m-d H:i:s') : '';
        $fromFilterTs = $fromFilter ? $fromFilter->getTimestamp() : null;
        $toFilterTs = $toFilter ? $toFilter->getTimestamp() : null;
        $paypalLastSyncAt = '';
        try {
            $optionManager = $serviceManager->get('Base\\Manager\\OptionManager');
            $paypalLastSyncAt = trim((string)$optionManager->get('paypal.last_sync_at', ''));
        } catch (\Throwable $e) {
            $paypalLastSyncAt = '';
        }

        $paypalLastSyncLabel = '';
        if ($paypalLastSyncAt !== '') {
            $timestamp = strtotime($paypalLastSyncAt);
            if ($timestamp !== false) {
                $paypalLastSyncLabel = date('d.m.Y H:i:s', $timestamp);
            } else {
                $paypalLastSyncLabel = $paypalLastSyncAt;
            }
        }

        $depositSql = 'SELECT * FROM drink_deposits WHERE deleted IS NULL OR deleted = 0';
        $depositParams = [];
        if (!$showTransfers && DbSchema::hasColumn($dbAdapter, 'drink_deposits', 'transfer_reference')) {
            $depositSql .= ' AND (transfer_reference IS NULL OR transfer_reference = "")';
        }
        if ($fromFilterSql !== '') {
            $depositSql .= ' AND deposit_time >= ?';
            $depositParams[] = $fromFilterSql;
        }
        if ($toFilterSql !== '') {
            $depositSql .= ' AND deposit_time <= ?';
            $depositParams[] = $toFilterSql;
        }
        $depositSql .= ' ORDER BY deposit_time ASC';

        $depositRows = iterator_to_array($dbAdapter->query($depositSql, $depositParams));
        $orderRows = iterator_to_array($dbAdapter->query(
            'SELECT * FROM drink_orders WHERE deleted IS NULL OR deleted = 0 ORDER BY order_time ASC',
            []
        ));

        $ordersByUser = [];
        foreach ($orderRows as $orderRow) {
            $uid = isset($orderRow['user_id']) ? (int)$orderRow['user_id'] : 0;
            if ($uid <= 0) {
                continue;
            }
            $ordersByUser[$uid][] = $orderRow;
        }

        $orderPositions = [];
        $orderSums = [];
        $depositSums = [];
        $depositEntries = [];
        $depositPaypalInfo = [];
        $depositIds = [];
        foreach ($depositRows as $depositRow) {
            $depositId = isset($depositRow['id']) ? (int)$depositRow['id'] : 0;
            if ($depositId > 0) {
                $depositIds[] = $depositId;
            }
        }
        if (!empty($depositIds)) {
            $placeholders = implode(',', array_fill(0, count($depositIds), '?'));
            try {
                $paypalLinkedRows = iterator_to_array($dbAdapter->query(
                    'SELECT id, linked_deposit_id, payer_email, linked_user_id, state, transaction_status, paypal_transaction_id, transaction_note FROM drinks_paypal WHERE linked_deposit_id IN (' . $placeholders . ')',
                    $depositIds
                ));
                foreach ($paypalLinkedRows as $paypalLinkedRow) {
                    $linkedDepositId = isset($paypalLinkedRow['linked_deposit_id']) ? (int)$paypalLinkedRow['linked_deposit_id'] : 0;
                    if ($linkedDepositId > 0 && !isset($depositPaypalInfo[$linkedDepositId])) {
                        $payerEmail = isset($paypalLinkedRow['payer_email']) ? trim((string)$paypalLinkedRow['payer_email']) : '';
                        $emailKey = strtolower($payerEmail);
                        $matchUserIds = isset($paypalLinkedUsersByEmail[$emailKey]) ? $paypalLinkedUsersByEmail[$emailKey] : [];
                        $linkedUserId = isset($paypalLinkedRow['linked_user_id']) ? (int)$paypalLinkedRow['linked_user_id'] : 0;
                        if ($linkedUserId > 0) {
                            $matchUserIds = array_values(array_unique(array_merge([$linkedUserId], $matchUserIds)));
                        }
                        $depositPaypalInfo[$linkedDepositId] = [
                            'drinks_paypal_id' => isset($paypalLinkedRow['id']) ? (int)$paypalLinkedRow['id'] : null,
                            'payer_email' => $payerEmail,
                            'paypal_match_user_ids' => $matchUserIds,
                            'paypal_state' => isset($paypalLinkedRow['state']) ? trim((string)$paypalLinkedRow['state']) : null,
                            'paypal_status' => isset($paypalLinkedRow['transaction_status']) ? trim((string)$paypalLinkedRow['transaction_status']) : '',
                            'paypal_transaction_id' => isset($paypalLinkedRow['paypal_transaction_id']) ? trim((string)$paypalLinkedRow['paypal_transaction_id']) : null,
                            'transaction_note' => trim((string)($paypalLinkedRow['transaction_note'] ?? '')),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // ignore paypal lookup failures for deposit overview
            }
        }

        foreach ($depositRows as $depositRow) {
            $uid = isset($depositRow['user_id']) ? (int)$depositRow['user_id'] : 0;
            if ($uid <= 0 || !isset($userMap[$uid])) {
                continue;
            }

            if (!isset($orderPositions[$uid])) {
                $orderPositions[$uid] = 0;
                $orderSums[$uid] = 0.0;
            }
            if (!isset($depositSums[$uid])) {
                $depositSums[$uid] = 0.0;
            }

            $depositTime = isset($depositRow['deposit_time']) ? $depositRow['deposit_time'] : '';
            if (isset($ordersByUser[$uid])) {
                while (isset($ordersByUser[$uid][$orderPositions[$uid]])
                    && strcmp($ordersByUser[$uid][$orderPositions[$uid]]['order_time'], $depositTime) <= 0
                ) {
                    $entry = $ordersByUser[$uid][$orderPositions[$uid]];
                    $orderSums[$uid] += (float)$entry['quantity'] * (float)$entry['price'];
                    $orderPositions[$uid]++;
                }
            }

            $depositSums[$uid] += (float)$depositRow['amount'];
            $balanceAfter = $depositSums[$uid] - $orderSums[$uid];
            $comment = isset($depositRow['comment']) ? trim((string)$depositRow['comment']) : '';

            $depositId = isset($depositRow['id']) ? (int)$depositRow['id'] : 0;
            $paypalInfo = isset($depositPaypalInfo[$depositId]) ? $depositPaypalInfo[$depositId] : null;
            $depositEntries[] = [
                'id' => $depositId,
                'user_id' => $uid,
                'name' => $userMap[$uid]['display'],
                'email' => $userMap[$uid]['email'],
                'date' => $depositTime,
                'amount' => (float)$depositRow['amount'],
                'balance_after' => $balanceAfter,
                'comment' => $comment,
                'is_paypal_deposit' => $paypalInfo !== null ? 1 : 0,
                'is_paypal_transaction' => 0,
                'drinks_paypal_id' => $paypalInfo !== null ? $paypalInfo['drinks_paypal_id'] : null,
                'paypal_state' => $paypalInfo !== null ? $paypalInfo['paypal_state'] : null,
                'paypal_status' => $paypalInfo !== null ? $paypalInfo['paypal_status'] : '',
                'payer_email' => $paypalInfo !== null ? $paypalInfo['payer_email'] : null,
                'paypal_match_user_ids' => $paypalInfo !== null ? ($paypalInfo['paypal_match_user_ids'] ?? []) : [],
                'paypal_transaction_id' => $paypalInfo !== null ? $paypalInfo['paypal_transaction_id'] : null,
                'paypal_note' => $paypalInfo !== null ? ($paypalInfo['transaction_note'] ?? '') : '',
            ];
        }

        // Append PayPal transactions: show those linked to a user as additional deposit rows
        try {
            foreach ($serviceManager->get('Drinks\\Manager\\PaypalTransactionManager')->getUnlinked(500) as $p) {
                $linkedUid = isset($p['linked_user_id']) ? (int)$p['linked_user_id'] : 0;
                $payerEmail = isset($p['payer_email']) ? trim((string)$p['payer_email']) : '';
                $matchUserIds = [];
                if ($payerEmail !== '') {
                    $emailKey = strtolower($payerEmail);
                    $matchUserIds = isset($paypalLinkedUsersByEmail[$emailKey]) ? $paypalLinkedUsersByEmail[$emailKey] : [];
                    $matchUserIds = array_values(array_unique(array_map('intval', $matchUserIds)));
                }
                if ($linkedUid > 0) {
                    $matchUserIds = array_values(array_unique(array_merge([$linkedUid], $matchUserIds)));
                }
                $matchedUid = ($linkedUid <= 0 && count($matchUserIds) === 1) ? $matchUserIds[0] : 0;
                $matchCandidates = [];
                if (count($matchUserIds) === 1 && isset($userMap[$matchUserIds[0]])) {
                    $matchCandidates[] = [
                        'uid' => $matchUserIds[0],
                        'name' => $userMap[$matchUserIds[0]]['display'],
                    ];
                }
                $name = 'PayPal (unlinked)';
                $displayEmail = $payerEmail;
                if ($linkedUid > 0 && isset($userMap[$linkedUid])) {
                    $name = $userMap[$linkedUid]['display'];
                    $displayEmail = $userMap[$linkedUid]['email'];
                } elseif ($matchedUid > 0 && isset($userMap[$matchedUid])) {
                    $name = $userMap[$matchedUid]['display'] . ' (PayPal Match)';
                } elseif (count($matchUserIds) > 1) {
                    $matchNames = [];
                    foreach ($matchUserIds as $matchUserId) {
                        if (isset($userMap[$matchUserId])) {
                            $matchNames[] = $userMap[$matchUserId]['display'];
                        }
                    }
                    if ($matchNames) {
                        $name = 'PayPal: ' . implode(', ', $matchNames);
                    }
                }
                $date = isset($p['received_at']) && $p['received_at'] ? $p['received_at'] : (isset($p['created_at']) ? $p['created_at'] : date('Y-m-d H:i:s'));
                $paypalTs = strtotime($date);
                if ($paypalTs !== false) {
                    if ($fromFilterTs !== null && $paypalTs < $fromFilterTs) {
                        continue;
                    }
                    if ($toFilterTs !== null && $paypalTs > $toFilterTs) {
                        continue;
                    }
                }
                $payerName = isset($p['payer_name']) ? trim((string)$p['payer_name']) : '';
                $transactionNote = trim((string)($p['transaction_note'] ?? ''));
                $commentParts = array_filter([$payerName, $transactionNote], 'strlen');
                $comment = $commentParts ? implode(' - ', $commentParts) : ('PayPal ' . (isset($p['paypal_transaction_id']) ? $p['paypal_transaction_id'] : ''));
                $depositEntries[] = [
                    'id' => isset($p['id']) ? (int)$p['id'] : null,
                    'user_id' => ($linkedUid > 0 ? $linkedUid : ($matchedUid > 0 ? $matchedUid : null)),
                    'linked_user_id' => ($linkedUid > 0 ? $linkedUid : null),
                    'matched_user_id' => ($matchedUid > 0 ? $matchedUid : null),
                    'paypal_match_user_ids' => $matchUserIds,
                    'paypal_match_candidates' => $matchCandidates,
                    'name' => $name,
                    'email' => $displayEmail,
                    'date' => $date,
                    'amount' => isset($p['amount']) ? (float)$p['amount'] : 0.0,
                    'balance_after' => null,
                    'comment' => $comment,
                    'is_paypal_deposit' => 0,
                    'is_paypal_transaction' => 1,
                    'paypal_state' => isset($p['state']) ? $p['state'] : null,
                    'paypal_status' => isset($p['transaction_status']) ? trim((string)$p['transaction_status']) : '',
                    'payer_email' => $payerEmail,
                    'paypal_note' => $transactionNote,
                ];
            }
        } catch (\Throwable $e) {
            // ignore paypal errors to keep deposit overview stable
        }

        usort($depositEntries, function ($a, $b) {
            return strcmp($b['date'], $a['date']);
        });

        $viewModel = new ViewModel([
            'deposits' => $depositEntries,
            'showTransfers' => $showTransfers,
            'quickRange' => $quickRange,
            'fromFilterValue' => $fromFilter ? $fromFilter->format('Y-m-d') : '',
            'toFilterValue' => $toFilter ? $toFilter->format('Y-m-d') : '',
            'allUsers' => $userMap,
            'paypalLastSyncLabel' => $paypalLastSyncLabel,
        ]);
        $viewModel->setTemplate('deposit-overview.phtml');
        return $viewModel;
    }

    /**
     * Admin: Spieltage overview - lists all team events across all team accounts, newest first
     */
    public function spieltageOverviewAction()
    {
        if (!$this->getAdminUser()) {
            return $this->redirect()->toRoute('user/settings');
        }
        $dbAdapter = $this->service('Zend\Db\Adapter\Adapter');

        // Fetch all team accounts
        $teamAccountRows = $dbAdapter->query(
            'SELECT da.user_id, da.alias FROM drink_aliases da WHERE da.is_team = 1 ORDER BY da.alias ASC',
            []
        )->toArray();

        $teamAliasMap = [];
        foreach ($teamAccountRows as $row) {
            $uid = (int)$row['user_id'];
            $teamAliasMap[$uid] = isset($row['alias']) ? trim((string)$row['alias']) : ('Team ' . $uid);
        }

        // All Spieltage of all team accounts, newest first
        $allEvents = [];
        foreach ($this->getTeamEventsWithBalances(array_keys($teamAliasMap), true, true) as $event) {
            $teamUserId = $event['team_admin_user_id'];
            $allEvents[] = [
                'id' => $event['id'],
                'label' => $event['label'],
                'balance' => $event['balance'],
                'closed' => $event['closed'],
                'team_user_id' => $teamUserId,
                'team_alias' => $teamAliasMap[$teamUserId] ?? ('Team ' . $teamUserId),
            ];
        }

        // Fetch per-event counts for badge items (by drink ID)
        $badgeItems = [
            DrinkManager::MEDENSPIEL_FLAT_DRINK_ID => ['emoji' => '🏆', 'name' => 'Medenspiel-Pauschale'],
            DrinkManager::HTV_BALLS_DRINK_ID => ['emoji' => '🎾', 'name' => 'HTV Bälle'],
        ];
        if (!empty($allEvents)) {
            $eventIds = array_column($allEvents, 'id');
            $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
            $drinkIds = array_keys($badgeItems);
            $idPlaceholders = implode(',', array_fill(0, count($drinkIds), '?'));
            try {
                $badgeRows = $dbAdapter->query(
                    'SELECT teamevent_id, drink_id, SUM(quantity) AS total_qty
                     FROM drink_orders
                     WHERE teamevent_id IN (' . $placeholders . ')
                       AND drink_id IN (' . $idPlaceholders . ')
                       AND (deleted IS NULL OR deleted = 0)
                     GROUP BY teamevent_id, drink_id',
                    array_merge($eventIds, $drinkIds)
                )->toArray();
                foreach ($badgeRows as $badgeRow) {
                    $eid = (int)$badgeRow['teamevent_id'];
                    $did = (int)$badgeRow['drink_id'];
                    $qty = (int)$badgeRow['total_qty'];
                    if (isset($badgeItems[$did])) {
                        $badgeItems[$did]['count'][$eid] = $qty;
                    }
                }
            } catch (\Exception $e) {
                // Leave badge counts empty if query fails
            }
        }

        // Attach badge counts to each event
        foreach ($allEvents as &$event) {
            $eid = (int)$event['id'];
            $badges = [];
            foreach ($badgeItems as $did => $cfg) {
                $qty = isset($cfg['count'][$eid]) ? (int)$cfg['count'][$eid] : 0;
                if ($qty > 0) {
                    $badges[] = ['qty' => $qty, 'emoji' => $cfg['emoji'], 'name' => $cfg['name']];
                }
            }
            $event['badges'] = $badges;
        }
        unset($event);

        $viewModel = new ViewModel([
            'events' => $allEvents,
        ]);
        $viewModel->setTemplate('spieltage-overview.phtml');
        return $viewModel;
    }

    /**
     * Thekenadmin: PayPal settings page
     */
    public function paypalSettingsAction()
    {
        if ($error = $this->rejectNonThekenadmin()) {
            return $error;
        }
        $optionManager = $this->service('Base\Manager\OptionManager');
        $paypalSettings = PaypalTransactionManager::loadSettings($optionManager);
        foreach (['minimum_account_balance', 'account_balance_reminder_threshold'] as $key) {
            try {
                $paypalSettings[$key] = (string)$optionManager->get('drinks.' . $key, '') ?: '0.00';
            } catch (\RuntimeException $e) {
                $paypalSettings[$key] = '0.00';
            }
        }

        $viewModel = new ViewModel([
            'paypalSettings' => $paypalSettings,
            'saved' => $this->params()->fromQuery('saved', '0') === '1',
        ]);
        $viewModel->setTemplate('paypal-settings.phtml');
        return $viewModel;
    }

    /**
     * Admin only: Save PayPal settings (credentials)
     */
    public function savePaypalSettingsAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('user/drinks-admin/paypal-settings');
        }
        if (!$this->getAdminUser()) {
            return $this->redirect()->toRoute('user/settings');
        }

        $optionManager = $this->service('Base\Manager\OptionManager');
        foreach (PaypalTransactionManager::SETTING_KEYS as $field) {
            if ($field === 'imap_ssl') {
                $value = $this->params()->fromPost('imap_ssl', '') ? '1' : '0';
            } else {
                $value = trim((string)$this->params()->fromPost($field, ''));
            }
            // Secrets are shown masked (●); an unchanged mask keeps the stored value
            if (in_array($field, ['imap_password', 'paypal_client_secret'], true) && $value !== '' && preg_match('/^[●\s]*$/u', $value)) {
                continue;
            }
            $optionManager->set('paypal.' . $field, $value);
        }

        foreach (['minimum_account_balance', 'account_balance_reminder_threshold'] as $key) {
            $amount = str_replace(',', '.', trim((string)$this->params()->fromPost($key, '0')));
            $optionManager->set('drinks.' . $key, is_numeric($amount) ? number_format((float)$amount, 2, '.', '') : '0.00');
        }

        return $this->redirect()->toRoute('user/drinks-admin/paypal-settings', [], ['query' => ['saved' => 1]], true);
    }

    /**
     * Thekenadmin: Trigger manual PayPal fetch (IMAP import, API crosscheck, auto-assign)
     */
    public function triggerPaypalFetchAction()
    {
        if ($error = $this->rejectNonThekenadmin() ?: $this->rejectNonPost()) {
            return $error;
        }

        $settings = PaypalTransactionManager::loadSettings($this->service('Base\Manager\OptionManager'));
        if (!PaypalTransactionManager::hasImapSettings($settings)) {
            return $this->jsonError(400, 'PayPal IMAP settings are incomplete.');
        }

        try {
            $paypalManager = $this->service('Drinks\Manager\PaypalTransactionManager');
            $result = $paypalManager->importFromImap($settings);
            $message = sprintf('PayPal Abruf abgeschlossen. %d neue Nachrichten importiert, %d übersprungen.', $result['imported'], $result['skipped']);
            $response = ['success' => true, 'result' => $result];

            if (PaypalTransactionManager::hasApiCredentials($settings)) {
                $syncResult = $paypalManager->syncEmailReceivedTransactions($settings['paypal_client_id'], $settings['paypal_client_secret'], 100);
                $message .= sprintf(' API-Crosscheck: %d synchronisiert, %d übersprungen.', $syncResult['synced'], $syncResult['skipped']);
                if (!empty($syncResult['errors'])) {
                    $message .= ' Fehler: ' . implode(' | ', $syncResult['errors']);
                }
                $response['sync_result'] = $syncResult;
            } else {
                $message .= ' PayPal-API-Credentials nicht konfiguriert, kein Crosscheck ausgeführt.';
            }

            $autoResult = $this->autoAssignPaypalTransactions();
            if ($autoResult['assigned'] > 0 || !empty($autoResult['errors'])) {
                $message .= sprintf(' Auto-Zuweisung: %d Deposits angelegt, %d übersprungen.', $autoResult['assigned'], $autoResult['skipped']);
                if (!empty($autoResult['errors'])) {
                    $message .= ' Fehler: ' . implode(' | ', $autoResult['errors']);
                }
            }
            $response['message'] = $message . $this->describeNeedsReview($autoResult);
            $response['auto_result'] = $autoResult;
            return $this->jsonResponse($response);
        } catch (\Throwable $e) {
            return $this->jsonError(500, $e->getMessage());
        }
    }

    /**
     * Thekenadmin: Trigger PayPal history import (API only, no IMAP) for the from/to range
     */
    public function triggerPaypalHistoryImportAction()
    {
        if ($error = $this->rejectNonThekenadmin() ?: $this->rejectNonPost()) {
            return $error;
        }
        try {
            $dates = $this->parseDateRangeFromQuery();
        } catch (\InvalidArgumentException $e) {
            return $this->jsonError(400, $e->getMessage());
        }

        $settings = PaypalTransactionManager::loadSettings($this->service('Base\Manager\OptionManager'));
        if (!PaypalTransactionManager::hasApiCredentials($settings)) {
            return $this->jsonError(400, 'PayPal API-Credentials nicht konfiguriert.');
        }
        $clientId = $settings['paypal_client_id'];
        $clientSecret = $settings['paypal_client_secret'];

        try {
            $paypalManager = $this->service('Drinks\Manager\PaypalTransactionManager');

            // The Reporting API allows 31 days per request: import in 30-day blocks, newest first
            $importResult = ['imported' => 0, 'skipped' => 0, 'errors' => []];
            $current = clone $dates['end'];
            while ($current >= $dates['start']) {
                $blockStart = clone $current;
                $blockStart->modify('-30 days');
                if ($blockStart < $dates['start']) {
                    $blockStart = clone $dates['start'];
                }
                $blockImportResult = $paypalManager->importFromReportingApi($clientId, $clientSecret, 30, $blockStart->format('Y-m-d'), $current->format('Y-m-d'));
                $importResult['imported'] += $blockImportResult['imported'];
                $importResult['skipped'] += $blockImportResult['skipped'];
                $importResult['errors'] = array_merge($importResult['errors'], $blockImportResult['errors']);

                $current = clone $blockStart;
                $current->modify('-1 second');
            }

            $syncResult = $paypalManager->syncEmailReceivedTransactions($clientId, $clientSecret, 100);
            $autoResult = $this->autoAssignPaypalTransactions();

            $message = sprintf('Historie-Import: %d importiert, %d übersprungen.', $importResult['imported'], $importResult['skipped']);
            if (!empty($importResult['errors'])) {
                $message .= ' Import-Fehler: ' . implode(' | ', array_slice($importResult['errors'], 0, 3));
            }
            $message .= sprintf(' API-Crosscheck: %d synchronisiert, %d übersprungen.', $syncResult['synced'], $syncResult['skipped']);
            $message .= sprintf(' Auto-Zuweisung: %d Buchungen verknüpft, %d übersprungen.', $autoResult['assigned'], $autoResult['skipped']);
            $message .= $this->describeNeedsReview($autoResult);
            if (!empty($autoResult['errors'])) {
                $message .= ' Fehler: ' . implode(' | ', array_slice($autoResult['errors'], 0, 3));
            }

            return $this->jsonResponse([
                'success'       => true,
                'message'       => $message,
                'import_result' => $importResult,
                'sync_result'   => $syncResult,
                'auto_result'   => $autoResult,
            ]);
        } catch (\Throwable $e) {
            return $this->jsonError(500, $e->getMessage());
        }
    }

    /**
     * Thekenadmin: Scan all IMAP folders for PayPal Guthaben emails in the
     * selected date range and fill payer_name in existing drinks_paypal rows
     * where it is still empty.
     *
     * No new records, deposits, or user assignments are created.
     * No PayPal API calls are made.
     */
    public function triggerEmailsImportAction()
    {
        if ($error = $this->rejectNonThekenadmin() ?: $this->rejectNonPost()) {
            return $error;
        }
        try {
            $dates = $this->parseDateRangeFromQuery();
        } catch (\InvalidArgumentException $e) {
            return $this->jsonError(400, $e->getMessage());
        }

        $settings = PaypalTransactionManager::loadSettings($this->service('Base\Manager\OptionManager'));
        if (!PaypalTransactionManager::hasImapSettings($settings)) {
            return $this->jsonError(400, 'PayPal IMAP settings are incomplete.');
        }

        try {
            $result = $this->service('Drinks\Manager\PaypalTransactionManager')
                ->fillPayerNamesFromImap($settings, $dates['start'], $dates['end']);

            $message = sprintf(
                'E-Mail-Import abgeschlossen. %d Ordner durchsucht, %d Nachrichten gefunden, %d Treffer. %d Namen ergänzt, %d übersprungen.',
                $result['folders'],
                $result['messages'],
                $result['matched'],
                $result['updated'],
                $result['skipped']
            );
            if (!empty($result['errors'])) {
                $message .= ' Fehler: ' . implode(' | ', array_slice($result['errors'], 0, 3));
            }

            return $this->jsonResponse([
                'success' => true,
                'message' => $message,
                'result'  => $result,
            ]);
        } catch (\Throwable $e) {
            return $this->jsonError(500, $e->getMessage());
        }
    }

    /**
     * AJAX: Create a deposit from a PayPal transaction
     */
    public function createDepositFromPaypalAction()
    {
        $admin = $this->getAdminUser();
        if (!$admin) {
            return $this->jsonError(403, 'No permission');
        }
        if ($error = $this->rejectNonPost()) {
            return $error;
        }

        $paypalId = (int)$this->params()->fromPost('paypal_id', 0);
        $userId = (int)$this->params()->fromPost('user_id', 0);
        if ($paypalId <= 0 || $userId <= 0) {
            return $this->jsonError(400, 'Invalid paypal_id or user_id');
        }

        $paypalManager = $this->service('Drinks\Manager\PaypalTransactionManager');
        if (!$paypalManager->getById($paypalId)) {
            return $this->jsonError(404, 'PayPal transaction not found');
        }
        if (!$this->service('User\Manager\UserManager')->get($userId, false)) {
            return $this->jsonError(404, 'User not found');
        }

        try {
            $creditResult = $paypalManager->createDepositForTransaction($paypalId, $userId, $this->service('Drinks\Manager\DrinkDepositManager'), $admin->get('uid'));
        } catch (\Throwable $e) {
            error_log('createDepositFromPaypal: ' . $e->getMessage());
            return $this->jsonError(500, 'Deposit creation failed');
        }
        if (empty($creditResult['success'])) {
            $error = isset($creditResult['error']) ? $creditResult['error'] : '';
            switch ($error) {
                case 'already_linked':
                    return $this->jsonError(409, 'PayPal transaction already credited', ['deposit_id' => $creditResult['deposit_id']]);
                case 'not_completed':
                    $status = isset($creditResult['status']) ? (string)$creditResult['status'] : '';
                    return $this->jsonError(409, 'PayPal-Zahlung ist nicht abgeschlossen (Status: ' . ($status !== '' ? $status : 'unbekannt') . ')');
                case 'invalid_amount':
                    return $this->jsonError(400, 'Invalid PayPal amount');
                case 'locked':
                    return $this->jsonError(409, 'PayPal transaction is being processed, please retry');
                case 'link_failed':
                    return $this->jsonError(500, 'Failed to link PayPal transaction');
                default:
                    return $this->jsonError(500, 'Deposit creation failed');
            }
        }

        $this->getDrinkManager()->notifyDeposit($userId, $creditResult['amount'], $creditResult['comment'], @$this->getServiceLocator());

        return $this->jsonResponse(['success' => true, 'deposit_id' => $creditResult['deposit_id']]);
    }

    /**
     * AJAX: Reassign a PayPal transaction (and its linked deposit) to a different user
     */
    public function reassignPaypalTransactionAction()
    {
        if ($error = $this->rejectNonThekenadmin() ?: $this->rejectNonPost()) {
            return $error;
        }
        $paypalId = (int)$this->params()->fromPost('paypal_id', 0);
        $newUserId = (int)$this->params()->fromPost('user_id', 0);
        if ($paypalId <= 0 || $newUserId <= 0) {
            return $this->jsonError(400, 'Invalid paypal_id or user_id');
        }
        $result = $this->service('Drinks\Manager\PaypalTransactionManager')->reassignTransaction($paypalId, $newUserId);
        if (empty($result['success'])) {
            return $this->jsonError(500, isset($result['error']) ? $result['error'] : 'Reassignment failed');
        }
        return $this->jsonResponse([
            'success' => true,
            'deposit_linked' => !empty($result['deposit_linked']),
            'linked_deposit_id' => $result['linked_deposit_id'] ?? null,
        ]);
    }

    /**
     * AJAX: Ignore a PayPal transaction (mark as 'ignored' state)
     */
    public function ignorePaypalTransactionAction()
    {
        if ($error = $this->rejectNonThekenadmin() ?: $this->rejectNonPost()) {
            return $error;
        }
        $paypalId = (int)$this->params()->fromPost('paypal_id', 0);
        if ($paypalId <= 0) {
            return $this->jsonError(400, 'Invalid paypal_id');
        }
        try {
            $this->service('Zend\Db\Adapter\Adapter')->query('UPDATE drinks_paypal SET state = ? WHERE id = ?', ['ignored', $paypalId]);
            return $this->jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            return $this->jsonError(500, $e->getMessage());
        }
    }

    /**
     * Resolve a Spieltag for a main-site teamlead action and authorise the caller.
     * Returns [teamAdminUserId, teamEventRow, actorUserId], or a JSON error response.
     */
    protected function resolveManagedTeamEvent($teamEventId, $requireOpen)
    {
        $user = $this->getSessionUser();
        if (!$user) {
            return $this->jsonError(401, 'Not authenticated.');
        }
        $teamEventId = (int)$teamEventId;
        if ($teamEventId <= 0) {
            return $this->jsonError(400, 'Ungültiger Spieltag.');
        }
        $row = $this->getTeamEventDbAdapter()->query(
            'SELECT team_admin_user_id FROM drinks_teamevents WHERE id = ? LIMIT 1',
            [$teamEventId]
        )->current();
        $teamAdminUserId = $row ? (int)$row['team_admin_user_id'] : 0;
        if ($teamAdminUserId <= 0) {
            return $this->jsonError(404, 'Spieltag nicht gefunden.');
        }
        if (!$this->canManageTeam($user, $teamAdminUserId)) {
            return $this->jsonError(403, 'No permission');
        }
        $teamEvent = $this->getTeamEventById($teamAdminUserId, $teamEventId);
        if (!$teamEvent) {
            return $this->jsonError(404, 'Spieltag nicht gefunden.');
        }
        if ($requireOpen && $this->isTeamEventClosedRow($teamEvent)) {
            return $this->jsonError(400, 'Abrechnung ist beendet.');
        }
        return [$teamAdminUserId, $teamEvent, (int)$user->need('uid')];
    }

    /**
     * May the user change data of the given team account?
     * Thekenadmins, the team account itself and its teamleads may.
     */
    private function canManageTeam($user, $teamAdminUserId)
    {
        $teamAdminUserId = (int)$teamAdminUserId;
        $userId = (int)$user->need('uid');
        if ($teamAdminUserId <= 0) {
            return false;
        }
        if ($userId === $teamAdminUserId || $this->getDrinkManager()->isThekenadmin($userId)) {
            return true;
        }
        return in_array($teamAdminUserId, $this->getDrinkManager()->getLedTeamUids($user->get('email')), true);
    }

    /**
     * JSON 401/403 response unless the session user has the thekenadmin flag; null if allowed.
     */
    private function rejectNonThekenadmin()
    {
        $user = $this->getSessionUser();
        if (!$user) {
            return $this->jsonError(401, 'Not logged in');
        }
        if (!$this->getDrinkManager()->isThekenadmin($user->get('uid'))) {
            return $this->jsonError(403, 'No permission');
        }
        return null;
    }

    /**
     * Parse and validate 'from' and 'to' date query parameters.
     *
     * @return array{start: \DateTime, end: \DateTime}
     * @throws \InvalidArgumentException on missing or malformed dates
     */
    private function parseDateRangeFromQuery()
    {
        $fromDateStr = (string)$this->params()->fromQuery('from', '');
        $toDateStr   = (string)$this->params()->fromQuery('to', '');

        if ($fromDateStr === '' || $toDateStr === '') {
            throw new \InvalidArgumentException('from and to dates required');
        }

        try {
            $startDate = new \DateTime($fromDateStr, new \DateTimeZone('UTC'));
            $startDate->setTime(0, 0, 0);
            $endDate = new \DateTime($toDateStr, new \DateTimeZone('UTC'));
            $endDate->setTime(23, 59, 59);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid date format');
        }

        return ['start' => $startDate, 'end' => $endDate];
    }

    /**
     * alias or name of a user, null if unknown (e.g. the creator of a deposit).
     */
    private function getUserDisplayName($userManager, $userId)
    {
        $user = !empty($userId) ? $userManager->get($userId, false) : null;
        return $user ? ($user->get('alias') ?: $user->get('name')) : null;
    }

    /**
     * Normalize a date/time input ('2026-05-01', '2026-05-01T18:30', ...) to 'Y-m-d H:i:s';
     * other formats are returned trimmed but unchanged.
     */
    private function normalizeDateTimeInput($value)
    {
        $value = str_replace('T', ' ', trim((string)$value));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
            return $value . ':00';
        }
        return $value;
    }

    /**
     * Link or credit synced PayPal payments (no new deposits from the admin buttons; the cron creates them).
     */
    private function autoAssignPaypalTransactions()
    {
        return $this->service('Drinks\Manager\PaypalTransactionManager')->autoAssignSyncedTransactions(
            $this->service('Drinks\Manager\DrinkDepositManager'),
            $this->getSessionUser()->get('uid'),
            @$this->getServiceLocator(),
            false
        );
    }

    private function describeNeedsReview(array $autoResult)
    {
        if (empty($autoResult['needs_review'])) {
            return '';
        }
        return sprintf(
            ' %d PayPal-Zahlung(en) zur Prüfung offen gelassen (gleicher Betrag wie eine Einzahlung ohne PayPal-Vermerk): #%s.',
            count($autoResult['needs_review']),
            implode(', #', $autoResult['needs_review'])
        );
    }
}