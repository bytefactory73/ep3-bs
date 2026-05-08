<?php

namespace User\Controller\Traits;

trait TeamEventTrait
{
    protected function getTeamEventSelectColumnsSql()
    {
        return $this->canUseTeamEventClosedColumn() ? 'id, comment, closed' : 'id, comment';
    }

    protected function getTeamEventOpenFilterSql($includeClosed = false)
    {
        return ($this->canUseTeamEventClosedColumn() && !(bool)$includeClosed)
            ? ' AND (closed IS NULL OR closed = 0)'
            : '';
    }

    protected function canUseTeamEventClosedColumn()
    {
        static $hasClosedColumn = null;
        if ($hasClosedColumn !== null) {
            return $hasClosedColumn;
        }

        try {
            $column = $this->getTeamEventDbAdapter()->query(
                "SHOW COLUMNS FROM drinks_teamevents LIKE 'closed'",
                []
            )->current();
            $hasClosedColumn = (bool)$column;
        } catch (\Exception $e) {
            $hasClosedColumn = false;
        }

        return $hasClosedColumn;
    }

    protected function isTeamEventClosedRow($teamEventRow)
    {
        if (!$this->canUseTeamEventClosedColumn()) {
            return false;
        }
        if (!is_array($teamEventRow) && !($teamEventRow instanceof \ArrayAccess)) {
            return false;
        }
        return isset($teamEventRow['closed']) && (int)$teamEventRow['closed'] === 1;
    }

    protected function getTeamEventDbAdapter()
    {
        return $this->getServiceLocator()->get('Zend\\Db\\Adapter\\Adapter');
    }

    protected function normalizeTeamEventLabel($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        return mb_substr($value, 0, 100);
    }

    protected function getLatestTeamEventRow($teamAdminUserId)
    {
        return $this->getTeamEventDbAdapter()->query(
            'SELECT ' . $this->getTeamEventSelectColumnsSql() . ' FROM drinks_teamevents WHERE team_admin_user_id = ? ORDER BY created_at DESC, id DESC LIMIT 1',
            [$teamAdminUserId]
        )->current();
    }

    protected function getTeamEventById($teamAdminUserId, $teamEventId)
    {
        $teamEventId = (int)$teamEventId;
        if ($teamEventId <= 0) {
            return null;
        }

        return $this->getTeamEventDbAdapter()->query(
            'SELECT ' . $this->getTeamEventSelectColumnsSql() . ' FROM drinks_teamevents WHERE team_admin_user_id = ? AND id = ? LIMIT 1',
            [$teamAdminUserId, $teamEventId]
        )->current();
    }

    protected function getTeamEventByLabel($teamAdminUserId, $label)
    {
        $label = $this->normalizeTeamEventLabel($label);
        if ($label === '') {
            return null;
        }

        return $this->getTeamEventDbAdapter()->query(
            'SELECT ' . $this->getTeamEventSelectColumnsSql() . ' FROM drinks_teamevents WHERE team_admin_user_id = ? AND comment = ? ORDER BY id DESC LIMIT 1',
            [$teamAdminUserId, $label]
        )->current();
    }

    protected function closeTeamEvent($teamAdminUserId, $teamEventId)
    {
        $teamAdminUserId = (int)$teamAdminUserId;
        $teamEventId = (int)$teamEventId;
        if ($teamAdminUserId <= 0 || $teamEventId <= 0) {
            return false;
        }
        if (!$this->canUseTeamEventClosedColumn()) {
            return false;
        }

        $this->getTeamEventDbAdapter()->query(
            'UPDATE drinks_teamevents SET closed = 1 WHERE team_admin_user_id = ? AND id = ?',
            [$teamAdminUserId, $teamEventId]
        );
        return true;
    }

    protected function closeTeamEventWithStatus($teamAdminUserId, $teamEventId)
    {
        $teamAdminUserId = (int)$teamAdminUserId;
        $teamEventId = (int)$teamEventId;
        if ($teamAdminUserId <= 0 || $teamEventId <= 0) {
            return ['success' => false, 'error' => 'invalid_input'];
        }
        if (!$this->canUseTeamEventClosedColumn()) {
            return ['success' => false, 'error' => 'feature_unavailable'];
        }

        $eventRow = $this->getTeamEventById($teamAdminUserId, $teamEventId);
        if (!$eventRow) {
            return ['success' => false, 'error' => 'not_found'];
        }
        if ($this->isTeamEventClosedRow($eventRow)) {
            return ['success' => true, 'already_closed' => true, 'event' => $eventRow];
        }

        $this->closeTeamEvent($teamAdminUserId, $teamEventId);
        return ['success' => true, 'already_closed' => false, 'event' => $eventRow];
    }

    protected function getOrCreateTeamEventByLabel($teamAdminUserId, $label)
    {
        $label = $this->normalizeTeamEventLabel($label);
        if ($label === '') {
            return null;
        }

        $row = $this->getTeamEventByLabel($teamAdminUserId, $label);
        if ($row) {
            return $row;
        }

        $this->getTeamEventDbAdapter()->query(
            'INSERT INTO drinks_teamevents (team_admin_user_id, comment) VALUES (?, ?)',
            [$teamAdminUserId, $label]
        );
        return $this->getTeamEventByLabel($teamAdminUserId, $label);
    }

    protected function ensureTeamEventDrinkOrderExists($teamAdminUserId, $teamEventId, $drinkId, $quantity = 1)
    {
        $teamAdminUserId = (int)$teamAdminUserId;
        $teamEventId = (int)$teamEventId;
        $drinkId = (int)$drinkId;
        $quantity = (int)$quantity;
        if ($teamAdminUserId <= 0 || $teamEventId <= 0 || $drinkId <= 0 || $quantity <= 0) {
            return false;
        }

        $dbAdapter = $this->getTeamEventDbAdapter();
        $existingOrder = $dbAdapter->query(
            'SELECT id
             FROM drink_orders
             WHERE user_id = ?
               AND drink_id = ?
               AND teamevent_id = ?
               AND (deleted IS NULL OR deleted = 0)
             LIMIT 1',
            [$teamAdminUserId, $drinkId, $teamEventId]
        )->current();
        if ($existingOrder) {
            return true;
        }

        $drinkOrderManager = $this->getServiceLocator()->get('Drinks\\Manager\\DrinkOrderManager');
        $drinkOrderManager->addOrder($teamAdminUserId, $drinkId, $quantity, $teamAdminUserId, 0, null, null, $teamEventId);
        return true;
    }

    protected function parseTeamEventMemberIds($rawValue)
    {
        if (is_array($rawValue)) {
            $items = $rawValue;
        } else {
            $raw = trim((string)$rawValue);
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $items = $decoded;
            } else {
                $items = preg_split('/\s*,\s*/', $raw);
            }
        }

        $result = [];
        foreach ($items as $item) {
            $uid = (int)$item;
            if ($uid > 0 && !in_array($uid, $result, true)) {
                $result[] = $uid;
            }
        }
        return $result;
    }

    protected function saveTeamEventMembers($teamAdminUserId, $teamEventId, array $memberUserIds)
    {
        $teamAdminUserId = (int)$teamAdminUserId;
        $teamEventId = (int)$teamEventId;
        if ($teamAdminUserId <= 0 || $teamEventId <= 0) {
            return;
        }

        $dbAdapter = $this->getTeamEventDbAdapter();
        $memberUserIds = array_values(array_unique(array_filter(array_map('intval', $memberUserIds), function ($id) {
            return $id > 0;
        })));

        if (!empty($memberUserIds)) {
            $placeholders = implode(', ', array_fill(0, count($memberUserIds), '?'));
            $validRows = $dbAdapter->query(
                'SELECT uid FROM bs_users WHERE uid IN (' . $placeholders . ')',
                $memberUserIds
            )->toArray();
            $validIds = [];
            foreach ($validRows as $row) {
                $uid = isset($row['uid']) ? (int)$row['uid'] : 0;
                if ($uid > 0) {
                    $validIds[] = $uid;
                }
            }
            $memberUserIds = array_values(array_unique($validIds));
        }

        // Replace member list for this event with submitted participants.
        $dbAdapter->query('DELETE FROM drinks_teamevent_members WHERE team_event_id = ?', [$teamEventId]);

        foreach ($memberUserIds as $memberUserId) {
            $dbAdapter->query(
                'INSERT INTO drinks_teamevent_members (team_event_id, user_id, added_by_user_id) VALUES (?, ?, ?)',
                [$teamEventId, $memberUserId, $teamAdminUserId]
            );
        }
    }

        protected function getTeamEventMemberCandidates($excludeUserId = 0, $teamEventId = 0)
        {
            $excludeUserId = (int)$excludeUserId;
            $teamEventId = (int)$teamEventId;
        
            // Get all enabled users
            $rows = $this->getTeamEventDbAdapter()->query(
                'SELECT uid, alias, email
                 FROM bs_users
                 WHERE status IN ("enabled", "admin", "assist")
                 ORDER BY alias ASC, uid ASC',
                []
            )->toArray();

            // Exclude only users who are already active members in this event.
            // Users with deposits but without membership must stay selectable.
            $usedUids = [];
            if ($teamEventId > 0) {
                $memberRows = $this->getTeamEventDbAdapter()->query(
                    'SELECT DISTINCT user_id AS uid
                     FROM drinks_teamevent_members
                     WHERE team_event_id = ?',
                    [$teamEventId]
                )->toArray();
            
                foreach ($memberRows as $row) {
                    $uid = isset($row['uid']) ? (int)$row['uid'] : 0;
                    if ($uid > 0) {
                        $usedUids[] = $uid;
                    }
                }
            }

            $result = [];
            foreach ($rows as $row) {
                $uid = isset($row['uid']) ? (int)$row['uid'] : 0;
                if ($uid <= 0 || ($excludeUserId > 0 && $uid === $excludeUserId) || in_array($uid, $usedUids, true)) {
                    continue;
                }
                $alias = isset($row['alias']) ? trim((string)$row['alias']) : '';
                $email = isset($row['email']) ? trim((string)$row['email']) : '';
                $name = $alias !== '' ? $alias : ('User ' . $uid);
                $result[] = [
                    'uid' => $uid,
                    'name' => $name,
                    'email' => $email,
                ];
            }
            return $result;
        }

    protected function getTeamEventMembersWithContribution($teamAdminUserId, $teamEventId, $teamEventLabel)
    {
        $teamAdminUserId = (int)$teamAdminUserId;
        $teamEventId = (int)$teamEventId;
        $teamEventLabel = $this->normalizeTeamEventLabel($teamEventLabel);
        if ($teamAdminUserId <= 0 || $teamEventId <= 0 || $teamEventLabel === '') {
            return [];
        }

        // Get members (current and former) with their contributions
        // Shows current members + any non-member users who made deposits
        $rows = $this->getTeamEventDbAdapter()->query(
            'SELECT
                    m.user_id AS uid,
                    u.alias,
                    u.email,
                    COALESCE(SUM(d.amount), 0) AS total_paid,
                    1 AS is_member,
                    "" AS deposit_comment
             FROM drinks_teamevent_members m
             JOIN bs_users u ON u.uid = m.user_id
             LEFT JOIN drink_deposits d
                    ON d.user_id = ?
                   AND (d.deleted IS NULL OR d.deleted = 0)
                   AND d.createdbyuserid = m.user_id
                   AND (
                        d.teamevent_id = ?
                        OR (d.teamevent_id IS NULL AND TRIM(COALESCE(d.comment, "")) = ?)
                   )
             WHERE m.team_event_id = ?
             GROUP BY m.user_id, u.alias, u.email
             UNION
             SELECT
                    d.createdbyuserid AS uid,
                    u.alias,
                    u.email,
                    d.amount AS total_paid,
                    0 AS is_member,
                    COALESCE(d.comment, "") AS deposit_comment
             FROM drink_deposits d
             JOIN bs_users u ON u.uid = d.createdbyuserid
             WHERE d.user_id = ?
               AND (d.deleted IS NULL OR d.deleted = 0)
               AND (
                    d.teamevent_id = ?
                    OR (d.teamevent_id IS NULL AND TRIM(COALESCE(d.comment, "")) = ?)
               )
               AND d.createdbyuserid NOT IN (
                    SELECT m.user_id FROM drinks_teamevent_members m WHERE m.team_event_id = ?
               )
             ORDER BY is_member DESC, alias ASC, uid ASC',
            [$teamAdminUserId, $teamEventId, $teamEventLabel, $teamEventId, $teamAdminUserId, $teamEventId, $teamEventLabel, $teamEventId]
        )->toArray();

        $result = [];
        foreach ($rows as $row) {
            $uid = isset($row['uid']) ? (int)$row['uid'] : 0;
            if ($uid <= 0) {
                continue;
            }
            $alias = isset($row['alias']) ? trim((string)$row['alias']) : '';
            $email = isset($row['email']) ? trim((string)$row['email']) : '';
            $name = $alias !== '' ? $alias : ('User ' . $uid);
            $isMember = isset($row['is_member']) ? (bool)(int)$row['is_member'] : false;
            $depositComment = isset($row['deposit_comment']) ? trim((string)$row['deposit_comment']) : '';
            $result[] = [
                'uid' => $uid,
                'name' => $name,
                'email' => $email,
                'total_paid' => isset($row['total_paid']) ? (float)$row['total_paid'] : 0.0,
                'is_member' => $isMember,
                'deposit_comment' => $depositComment,
            ];
        }
        return $result;
    }

    protected function getTeamEventOrderRowsAndTotal($teamAdminUserId, $teamEventLabel)
    {
        $teamAdminUserId = (int)$teamAdminUserId;
        $teamEventLabel = $this->normalizeTeamEventLabel($teamEventLabel);
        if ($teamAdminUserId <= 0 || $teamEventLabel === '') {
            return ['rows' => [], 'total_sum' => 0.0];
        }

        $orderRows = $this->getTeamEventDbAdapter()->query(
            'SELECT
                COALESCE(c.name, "") AS category_name,
                COALESCE(c.sort_priority, 0) AS category_sort,
                d.name AS article,
                SUM(o.quantity) AS quantity,
                (0 - o.price) AS single_price,
                (0 - SUM(o.quantity * o.price)) AS total_price
             FROM drink_orders o
             JOIN drinks d ON d.id = o.drink_id
             LEFT JOIN drink_categories c ON c.id = d.category
             WHERE o.user_id = ?
               AND o.deleted = 0
               AND (
                   o.teamevent_id IN (
                       SELECT e.id
                       FROM drinks_teamevents e
                       WHERE e.team_admin_user_id = ?
                         AND TRIM(COALESCE(e.comment, "")) = ?
                   )
                   OR (o.teamevent_id IS NULL AND TRIM(COALESCE(o.comment, "")) = ?)
               )
             GROUP BY o.drink_id, d.name, o.price, c.name, c.sort_priority
             ORDER BY category_sort ASC, category_name ASC, d.name ASC, o.price ASC',
            [$teamAdminUserId, $teamAdminUserId, $teamEventLabel, $teamEventLabel]
        )->toArray();

        $rows = [];
        foreach ($orderRows as $orderRow) {
            $rows[] = [
                'category' => isset($orderRow['category_name']) ? (string)$orderRow['category_name'] : '',
                'article' => isset($orderRow['article']) ? (string)$orderRow['article'] : '',
                'quantity' => isset($orderRow['quantity']) ? (int)$orderRow['quantity'] : 0,
                'single_price' => isset($orderRow['single_price']) ? (float)$orderRow['single_price'] : 0.0,
                'total_price' => isset($orderRow['total_price']) ? (float)$orderRow['total_price'] : 0.0,
            ];
        }

        $totalSum = 0.0;
        foreach ($rows as $row) {
            $totalSum += (float)$row['total_price'];
        }

        return ['rows' => $rows, 'total_sum' => $totalSum];
    }

    protected function buildTeamStatsPayload($teamAdminUserId, $teamEventLabel, array $extra = [])
    {
        $teamAdminUserId = (int)$teamAdminUserId;
        $teamEventLabel = $this->normalizeTeamEventLabel($teamEventLabel);
        $selectedTeamEvent = $this->getTeamEventByLabel($teamAdminUserId, $teamEventLabel);
        $selectedTeamEventId = $selectedTeamEvent && isset($selectedTeamEvent['id']) ? (int)$selectedTeamEvent['id'] : 0;
        $isClosed = $this->isTeamEventClosedRow($selectedTeamEvent);
        $stats = $this->getTeamEventOrderRowsAndTotal($teamAdminUserId, $teamEventLabel);

        return array_merge([
            'spieltag' => $teamEventLabel,
            'team_event_id' => $selectedTeamEventId,
            'team_event_closed' => $isClosed,
            'can_close_team_event' => true,
            'rows' => $stats['rows'],
            'total_sum' => $stats['total_sum'],
            'members' => $this->getTeamEventMembersWithContribution($teamAdminUserId, $selectedTeamEventId, $teamEventLabel),
            'member_candidates' => $this->getTeamEventMemberCandidates($teamAdminUserId, $selectedTeamEventId),
            'can_manage_members' => true,
        ], $extra);
    }

    protected function processTeamEventMemberOperation($teamAdminUserId, $teamEventId, $memberUserId, $operation, $actorUserId = null)
    {
        $teamAdminUserId = (int)$teamAdminUserId;
        $teamEventId = (int)$teamEventId;
        $memberUserId = (int)$memberUserId;
        $operation = trim((string)$operation);

        if ($memberUserId <= 0) {
            return [false, 'Ungültiger Teilnehmer.', null];
        }

        $teamEvent = $this->getTeamEventById($teamAdminUserId, $teamEventId);
        if (!$teamEvent || empty($teamEvent['id'])) {
            return [false, 'Ungültiger Spieltag.', null];
        }

        if ($operation === 'add') {
            if (!$this->addTeamEventMember($teamEventId, $memberUserId, $actorUserId)) {
                return [false, 'Teilnehmer konnte nicht hinzugefügt werden.', null];
            }
        } elseif ($operation === 'remove') {
            $this->removeTeamEventMember($teamEventId, $memberUserId);
        } else {
            return [false, 'Ungültige Operation.', null];
        }

        return [true, null, $teamEvent];
    }

    protected function buildMemberOperationJsonResponse($teamAdminUserId, $teamEventId, $memberUserId, $operation, $actorUserId = null)
    {
        list($success, $error, $teamEvent) = $this->processTeamEventMemberOperation($teamAdminUserId, $teamEventId, $memberUserId, $operation, $actorUserId);
        if (!$success) {
            return ['success' => false, 'error' => $error];
        }

        $teamEventLabel = isset($teamEvent['comment']) ? trim((string)$teamEvent['comment']) : '';
        return [
            'success' => true,
            'team_event_id' => $teamEventId,
            'members' => $this->getTeamEventMembersWithContribution($teamAdminUserId, $teamEventId, $teamEventLabel),
        ];
    }

    protected function addTeamEventMember($teamEventId, $memberUserId, $actorUserId = null)
    {
        $teamEventId = (int)$teamEventId;
        $memberUserId = (int)$memberUserId;
        $actorUserId = $actorUserId !== null ? (int)$actorUserId : null;
        if ($teamEventId <= 0 || $memberUserId <= 0) {
            return false;
        }

        $dbAdapter = $this->getTeamEventDbAdapter();
        $userExists = $dbAdapter->query('SELECT uid FROM bs_users WHERE uid = ? LIMIT 1', [$memberUserId])->current();
        if (!$userExists) {
            return false;
        }

        $dbAdapter->query(
            'INSERT INTO drinks_teamevent_members (team_event_id, user_id, added_by_user_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE added_by_user_id = VALUES(added_by_user_id)',
            [$teamEventId, $memberUserId, $actorUserId]
        );
        return true;
    }

    protected function removeTeamEventMember($teamEventId, $memberUserId)
    {
        $teamEventId = (int)$teamEventId;
        $memberUserId = (int)$memberUserId;
        if ($teamEventId <= 0 || $memberUserId <= 0) {
            return false;
        }

        $this->getTeamEventDbAdapter()->query(
            'DELETE FROM drinks_teamevent_members WHERE team_event_id = ? AND user_id = ?',
            [$teamEventId, $memberUserId]
        );
        return true;
    }

    protected function getAvailableTeamEventLabels($teamAdminUserId, $includeClosed = false)
    {
        $includeClosed = (bool)$includeClosed;
        $rows = $this->getTeamEventDbAdapter()->query(
            'SELECT comment AS team_event_label FROM drinks_teamevents WHERE team_admin_user_id = ?' . $this->getTeamEventOpenFilterSql($includeClosed) . ' ORDER BY created_at DESC, id DESC',
            [$teamAdminUserId]
        )->toArray();
        $result = [];
        foreach ($rows as $row) {
            $teamEventLabel = isset($row['team_event_label']) ? trim((string)$row['team_event_label']) : '';
            if ($teamEventLabel !== '' && !in_array($teamEventLabel, $result, true)) {
                $result[] = $teamEventLabel;
            }
        }
        return $result;
    }

    protected function resolveSessionTeamEventSelection($teamAdminUserId, \Zend\Session\Container $session)
    {
        $selectedTeamEventLabel = $this->normalizeTeamEventLabel(isset($session->current_spieltag) ? $session->current_spieltag : '');
        $availableTeamEventLabels = $this->getAvailableTeamEventLabels($teamAdminUserId);
        if ($selectedTeamEventLabel !== '') {
            $teamEvent = $this->getTeamEventByLabel($teamAdminUserId, $selectedTeamEventLabel);
            if ($teamEvent) {
                $session->current_teamevent_id = (int)$teamEvent['id'];
            } else {
                // Event does not exist – clear stale session value
                $selectedTeamEventLabel = '';
                $session->current_spieltag = '';
                $session->current_teamevent_id = 0;
            }
        }
        if ($selectedTeamEventLabel !== '' && !in_array($selectedTeamEventLabel, $availableTeamEventLabels, true)) {
            array_unshift($availableTeamEventLabels, $selectedTeamEventLabel);
        }
        if ($selectedTeamEventLabel === '' && !empty($availableTeamEventLabels)) {
            $selectedTeamEventLabel = $availableTeamEventLabels[0];
            $teamEvent = $this->getTeamEventByLabel($teamAdminUserId, $selectedTeamEventLabel);
            if ($teamEvent) {
                $session->current_teamevent_id = (int)$teamEvent['id'];
            }
        }
        $session->current_spieltag = $selectedTeamEventLabel;
        return [$selectedTeamEventLabel, $availableTeamEventLabels];
    }

    protected function resolveTeamEventForSelection($teamAdminUserId, $preferredTeamEventId = 0, $newTeamEventLabel = '')
    {
        $preferredTeamEventId = (int)$preferredTeamEventId;
        if ($preferredTeamEventId > 0) {
            $selectedTeamEvent = $this->getTeamEventById($teamAdminUserId, $preferredTeamEventId);
            if ($selectedTeamEvent && !$this->isTeamEventClosedRow($selectedTeamEvent)) {
                return $selectedTeamEvent;
            }
            return null;
        }

        $newTeamEventLabel = $this->normalizeTeamEventLabel($newTeamEventLabel);
        if ($newTeamEventLabel !== '') {
            return $this->getOrCreateTeamEventByLabel($teamAdminUserId, $newTeamEventLabel);
        }

        return null;
    }

    protected function calculateTeamEventBalance($teamAdminUserId, $teamEventId, $teamEventLabel)
    {
        $teamAdminUserId = (int)$teamAdminUserId;
        $teamEventId = (int)$teamEventId;
        $teamEventLabel = $this->normalizeTeamEventLabel($teamEventLabel);
        if ($teamAdminUserId <= 0 || $teamEventId <= 0 || $teamEventLabel === '') {
            return 0.0;
        }

        $dbAdapter = $this->getTeamEventDbAdapter();
        $depositRow = $dbAdapter->query(
            'SELECT COALESCE(SUM(amount), 0) AS total
             FROM drink_deposits
             WHERE user_id = ?
               AND (deleted IS NULL OR deleted = 0)
               AND (
                    teamevent_id = ?
                    OR (teamevent_id IS NULL AND TRIM(COALESCE(comment, "")) = ?)
               )',
            [$teamAdminUserId, $teamEventId, $teamEventLabel]
        )->current();

        $orderRow = $dbAdapter->query(
            'SELECT COALESCE(SUM(quantity * price), 0) AS total
             FROM drink_orders
             WHERE user_id = ?
               AND (deleted IS NULL OR deleted = 0)
               AND (
                    teamevent_id = ?
                    OR (teamevent_id IS NULL AND TRIM(COALESCE(comment, "")) = ?)
               )',
            [$teamAdminUserId, $teamEventId, $teamEventLabel]
        )->current();

        $depositTotal = $depositRow && isset($depositRow['total']) ? (float)$depositRow['total'] : 0.0;
        $orderTotal = $orderRow && isset($orderRow['total']) ? (float)$orderRow['total'] : 0.0;
        return $depositTotal - $orderTotal;
    }

    protected function getTeamEventsWithBalances($teamAdminUserId, $includeClosed = false)
    {
        $includeClosed = (bool)$includeClosed;
        $rows = $this->getTeamEventDbAdapter()->query(
            'SELECT ' . $this->getTeamEventSelectColumnsSql() . ' FROM drinks_teamevents WHERE team_admin_user_id = ?' . $this->getTeamEventOpenFilterSql($includeClosed) . ' ORDER BY created_at DESC, id DESC',
            [$teamAdminUserId]
        )->toArray();

        $result = [];
        foreach ($rows as $row) {
            $teamEventId = isset($row['id']) ? (int)$row['id'] : 0;
            $teamEventLabel = isset($row['comment']) ? trim((string)$row['comment']) : '';
            if ($teamEventId <= 0 || $teamEventLabel === '') {
                continue;
            }

            $result[] = [
                'id' => $teamEventId,
                'label' => $teamEventLabel,
                'balance' => $this->calculateTeamEventBalance($teamAdminUserId, $teamEventId, $teamEventLabel),
                'closed' => $this->canUseTeamEventClosedColumn() ? ((isset($row['closed']) && (int)$row['closed'] === 1) ? 1 : 0) : 0,
            ];
        }

        return $result;
    }
}