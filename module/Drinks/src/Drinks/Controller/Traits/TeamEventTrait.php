<?php

namespace Drinks\Controller\Traits;

use Base\Service\MoneyCalculator;

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

    protected function canUseTeamEventOrderRelevanceTable()
    {
        static $hasTable = null;
        if ($hasTable !== null) {
            return $hasTable;
        }

        try {
            $tableRow = $this->getTeamEventDbAdapter()->query(
                "SHOW TABLES LIKE 'drinks_teamevent_order_relevance'",
                []
            )->current();
            $hasTable = (bool)$tableRow;
        } catch (\Exception $e) {
            $hasTable = false;
        }

        return $hasTable;
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
        $serviceLocator = $this->getServiceLocator();
        if (!is_object($serviceLocator) || !method_exists($serviceLocator, 'get')) {
            throw new \Exception('Service locator unavailable');
        }
        return $serviceLocator->get('Zend\\Db\\Adapter\\Adapter');
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

    protected function getTeamEventMemberUserIds($teamEventId)
    {
        $teamEventId = (int)$teamEventId;
        if ($teamEventId <= 0) {
            return [];
        }

        $rows = $this->getTeamEventDbAdapter()->query(
            'SELECT user_id FROM drinks_teamevent_members WHERE team_event_id = ? ORDER BY user_id ASC',
            [$teamEventId]
        )->toArray();

        $result = [];
        foreach ($rows as $row) {
            $userId = isset($row['user_id']) ? (int)$row['user_id'] : 0;
            if ($userId > 0) {
                $result[] = $userId;
            }
        }

        return array_values(array_unique($result));
    }

    protected function getTeamEventOrderRelevanceMap($teamEventId)
    {
        $teamEventId = (int)$teamEventId;
        if ($teamEventId <= 0 || !$this->canUseTeamEventOrderRelevanceTable()) {
            return [];
        }

        $rows = $this->getTeamEventDbAdapter()->query(
            'SELECT drink_id, unit_price, member_user_id
             FROM drinks_teamevent_order_relevance
             WHERE team_event_id = ?',
            [$teamEventId]
        )->toArray();

        $map = [];
        foreach ($rows as $row) {
            $drinkId = isset($row['drink_id']) ? (int)$row['drink_id'] : 0;
            $unitPrice = isset($row['unit_price']) ? (float)$row['unit_price'] : 0.0;
            $memberUserId = isset($row['member_user_id']) ? (int)$row['member_user_id'] : 0;
            if ($drinkId <= 0 || $unitPrice <= 0 || $memberUserId <= 0) {
                continue;
            }
            $key = $drinkId . '|' . number_format($unitPrice, 2, '.', '');
            if (!isset($map[$key])) {
                $map[$key] = [];
            }
            $map[$key][] = $memberUserId;
        }

        foreach ($map as $key => $memberIds) {
            $map[$key] = array_values(array_unique($memberIds));
        }

        return $map;
    }

    protected function saveTeamEventOrderRelevance($teamAdminUserId, $teamEventId, $drinkId, $unitPrice, array $memberUserIds)
    {
        $teamAdminUserId = (int)$teamAdminUserId;
        $teamEventId = (int)$teamEventId;
        $drinkId = (int)$drinkId;
        $unitPrice = round((float)$unitPrice, 2);
        $memberUserIds = array_values(array_unique(array_map('intval', $memberUserIds)));
        $memberUserIds = array_values(array_filter($memberUserIds, function ($userId) {
            return $userId > 0;
        }));

        if ($teamAdminUserId <= 0 || $teamEventId <= 0 || $drinkId <= 0 || $unitPrice <= 0) {
            throw new \RuntimeException('Invalid relevance input');
        }
        if (!$this->canUseTeamEventOrderRelevanceTable()) {
            throw new \RuntimeException('Relevance table not available');
        }

        $teamEvent = $this->getTeamEventById($teamAdminUserId, $teamEventId);
        if (!$teamEvent) {
            throw new \RuntimeException('Team event not found');
        }

        $dbAdapter = $this->getTeamEventDbAdapter();
        $dbAdapter->query(
            'DELETE FROM drinks_teamevent_order_relevance
             WHERE team_event_id = ? AND drink_id = ? AND unit_price = ?',
            [$teamEventId, $drinkId, $unitPrice]
        );

        foreach ($memberUserIds as $memberUserId) {
            $dbAdapter->query(
                'INSERT INTO drinks_teamevent_order_relevance (team_event_id, drink_id, unit_price, member_user_id)
                 VALUES (?, ?, ?, ?)',
                [$teamEventId, $drinkId, $unitPrice, $memberUserId]
            );
        }

        return true;
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

        // Get members (current and former) with their contributions.
        // Resolve payer per deposit in PHP for robust cross-install behavior.
        $dbAdapter = $this->getTeamEventDbAdapter();

        $memberRows = $dbAdapter->query(
            'SELECT m.user_id AS uid, u.alias, u.email
             FROM drinks_teamevent_members m
             JOIN bs_users u ON u.uid = m.user_id
             WHERE m.team_event_id = ?
             ORDER BY u.alias ASC, m.user_id ASC',
            [$teamEventId]
        )->toArray();

        $memberUids = [];
        $userInfoByUid = [];
        foreach ($memberRows as $mr) {
            $uid = isset($mr['uid']) ? (int)$mr['uid'] : 0;
            if ($uid <= 0) {
                continue;
            }
            $memberUids[$uid] = true;
            $userInfoByUid[$uid] = [
                'alias' => isset($mr['alias']) ? trim((string)$mr['alias']) : '',
                'email' => isset($mr['email']) ? trim((string)$mr['email']) : '',
            ];
        }

        $aliasRows = $dbAdapter->query(
            'SELECT uid, alias FROM bs_users WHERE alias IS NOT NULL AND TRIM(alias) <> ""',
            []
        )->toArray();
        $aliasToUid = [];
        foreach ($aliasRows as $ar) {
            $uid = isset($ar['uid']) ? (int)$ar['uid'] : 0;
            $alias = isset($ar['alias']) ? strtolower(trim((string)$ar['alias'])) : '';
            if ($uid > 0 && $alias !== '' && !isset($aliasToUid[$alias])) {
                $aliasToUid[$alias] = $uid;
            }
        }

        // Also map drink aliases because transfer comments can use those display names.
        try {
            $drinkAliasRows = $dbAdapter->query(
                'SELECT user_id AS uid, alias FROM drink_aliases WHERE alias IS NOT NULL AND TRIM(alias) <> ""',
                []
            )->toArray();
            foreach ($drinkAliasRows as $dar) {
                $uid = isset($dar['uid']) ? (int)$dar['uid'] : 0;
                $alias = isset($dar['alias']) ? strtolower(trim((string)$dar['alias'])) : '';
                if ($uid > 0 && $alias !== '' && !isset($aliasToUid[$alias])) {
                    $aliasToUid[$alias] = $uid;
                }
            }
        } catch (\Exception $e) {
            // Optional table/column differences should not break stats.
        }

        $depositRows = [];
        $eventTransferRefs = [];
        try {
            $eventTransferRows = $dbAdapter->query(
                'SELECT DISTINCT transfer_reference
                 FROM drink_orders
                 WHERE (deleted IS NULL OR deleted = 0)
                   AND drink_id = -1
                   AND transfer_reference IS NOT NULL
                   AND transfer_reference <> ""
                   AND (
                        teamevent_id = ?
                        OR (teamevent_id IS NULL AND TRIM(COALESCE(comment, "")) = ?)
                   )',
                [$teamEventId, $teamEventLabel]
            )->toArray();
            foreach ($eventTransferRows as $etr) {
                $ref = isset($etr['transfer_reference']) ? trim((string)$etr['transfer_reference']) : '';
                if ($ref !== '') {
                    $eventTransferRefs[$ref] = true;
                }
            }
        } catch (\Exception $e) {
            $eventTransferRefs = [];
        }

        if (!empty($eventTransferRefs)) {
            $refValues = array_keys($eventTransferRefs);
            $refPlaceholders = implode(',', array_fill(0, count($refValues), '?'));
            $depositRows = $dbAdapter->query(
                'SELECT id, user_id, createdbyuserid, amount, comment, transfer_reference
                 FROM drink_deposits
                 WHERE (deleted IS NULL OR deleted = 0)
                   AND (
                        teamevent_id = ?
                        OR (teamevent_id IS NULL AND TRIM(COALESCE(comment, "")) = ?)
                        OR transfer_reference IN (' . $refPlaceholders . ')
                   )',
                array_merge([$teamEventId, $teamEventLabel], $refValues)
            )->toArray();
        } else {
            $depositRows = $dbAdapter->query(
                'SELECT id, user_id, createdbyuserid, amount, comment, transfer_reference
                 FROM drink_deposits
                 WHERE (deleted IS NULL OR deleted = 0)
                   AND (
                        teamevent_id = ?
                        OR (teamevent_id IS NULL AND TRIM(COALESCE(comment, "")) = ?)
                   )',
                [$teamEventId, $teamEventLabel]
            )->toArray();
        }

        $transferRefs = [];
        foreach ($depositRows as $dr) {
            $ref = isset($dr['transfer_reference']) ? trim((string)$dr['transfer_reference']) : '';
            if ($ref !== '' && !isset($transferRefs[$ref])) {
                $transferRefs[$ref] = true;
            }
        }

        $payerByTransferRef = [];
        if (!empty($transferRefs)) {
            $refValues = array_keys($transferRefs);
            $refPlaceholders = implode(',', array_fill(0, count($refValues), '?'));
            try {
                $transferRows = $dbAdapter->query(
                    'SELECT transfer_reference, MAX(user_id) AS payer_user_id
                     FROM drink_orders
                     WHERE transfer_reference IN (' . $refPlaceholders . ')
                       AND drink_id = -1
                       AND (deleted IS NULL OR deleted = 0)
                     GROUP BY transfer_reference',
                    $refValues
                )->toArray();
                foreach ($transferRows as $tr) {
                    $ref = isset($tr['transfer_reference']) ? trim((string)$tr['transfer_reference']) : '';
                    $payerUid = isset($tr['payer_user_id']) ? (int)$tr['payer_user_id'] : 0;
                    if ($ref !== '' && $payerUid > 0) {
                        $payerByTransferRef[$ref] = $payerUid;
                    }
                }
            } catch (\Exception $e) {
                // Older installations may not have transfer_reference columns.
                $payerByTransferRef = [];
            }
        }

        $paidByUid = [];
        $depositCommentByUid = [];
        foreach ($depositRows as $dr) {
            $amount = isset($dr['amount']) ? (float)$dr['amount'] : 0.0;
            if ($amount <= 0) {
                continue;
            }

            $payerUid = 0;
            $comment = isset($dr['comment']) ? trim((string)$dr['comment']) : '';
            $commentLower = strtolower($comment);
            $createdBy = isset($dr['createdbyuserid']) ? (int)$dr['createdbyuserid'] : 0;
            $receiverUid = isset($dr['user_id']) ? (int)$dr['user_id'] : 0;
            $ref = isset($dr['transfer_reference']) ? trim((string)$dr['transfer_reference']) : '';

            if ($ref !== '' && isset($payerByTransferRef[$ref])) {
                $payerUid = (int)$payerByTransferRef[$ref];
            } elseif (strpos($commentLower, 'geld empfangen von ') === 0) {
                $senderAlias = trim(substr($comment, strlen('Geld empfangen von ')));
                $senderAliasLower = strtolower($senderAlias);
                if ($senderAliasLower !== '' && isset($aliasToUid[$senderAliasLower])) {
                    $payerUid = (int)$aliasToUid[$senderAliasLower];
                }
                if ($payerUid <= 0 && $createdBy > 0 && $createdBy !== $teamAdminUserId) {
                    $payerUid = $createdBy;
                }
            } elseif ($createdBy > 0) {
                $payerUid = $createdBy;
            }

            if ($payerUid <= 0 && $receiverUid > 0 && $receiverUid !== $teamAdminUserId) {
                $payerUid = $receiverUid;
            }

            if ($payerUid <= 0) {
                continue;
            }

            // Refund transfers from the team account to members must not count
            // as additional member contributions for this event.
            if (
                $receiverUid > 0
                && $receiverUid !== $teamAdminUserId
                && $payerUid === $teamAdminUserId
                && strpos($commentLower, 'geld empfangen von ') === 0
            ) {
                continue;
            }

            if (!isset($paidByUid[$payerUid])) {
                $paidByUid[$payerUid] = 0.0;
            }
            $paidByUid[$payerUid] += $amount;

            if (!isset($memberUids[$payerUid]) && !isset($depositCommentByUid[$payerUid]) && $comment !== '') {
                $depositCommentByUid[$payerUid] = $comment;
            }
        }

        $allUids = array_keys($paidByUid);
        foreach (array_keys($memberUids) as $memberUid) {
            if (!in_array($memberUid, $allUids, true)) {
                $allUids[] = $memberUid;
            }
        }

        $missingUids = [];
        foreach ($allUids as $uid) {
            if (!isset($userInfoByUid[$uid])) {
                $missingUids[] = (int)$uid;
            }
        }

        if (!empty($missingUids)) {
            $missingPlaceholders = implode(',', array_fill(0, count($missingUids), '?'));
            $missingRows = $dbAdapter->query(
                'SELECT uid, alias, email FROM bs_users WHERE uid IN (' . $missingPlaceholders . ')',
                $missingUids
            )->toArray();
            foreach ($missingRows as $row) {
                $uid = isset($row['uid']) ? (int)$row['uid'] : 0;
                if ($uid > 0) {
                    $userInfoByUid[$uid] = [
                        'alias' => isset($row['alias']) ? trim((string)$row['alias']) : '',
                        'email' => isset($row['email']) ? trim((string)$row['email']) : '',
                    ];
                }
            }
        }

        $rows = [];
        foreach ($allUids as $uid) {
            $uid = (int)$uid;
            if ($uid <= 0) {
                continue;
            }
            $info = isset($userInfoByUid[$uid]) ? $userInfoByUid[$uid] : ['alias' => '', 'email' => ''];
            $rows[] = [
                'uid' => $uid,
                'alias' => $info['alias'],
                'email' => $info['email'],
                'total_paid' => isset($paidByUid[$uid]) ? (float)$paidByUid[$uid] : 0.0,
                'is_member' => isset($memberUids[$uid]) ? 1 : 0,
                'deposit_comment' => isset($depositCommentByUid[$uid]) ? (string)$depositCommentByUid[$uid] : '',
            ];
        }

        usort($rows, function ($a, $b) {
            $am = isset($a['is_member']) ? (int)$a['is_member'] : 0;
            $bm = isset($b['is_member']) ? (int)$b['is_member'] : 0;
            if ($am !== $bm) {
                return $bm <=> $am;
            }
            $aa = isset($a['alias']) ? strtolower((string)$a['alias']) : '';
            $ba = isset($b['alias']) ? strtolower((string)$b['alias']) : '';
            if ($aa !== $ba) {
                return strcmp($aa, $ba);
            }
            return ((int)$a['uid']) <=> ((int)$b['uid']);
        });

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
                'total_paid' => MoneyCalculator::roundMoney(isset($row['total_paid']) ? (float)$row['total_paid'] : 0.0),
                'total_refunded' => 0.0,
                'is_member' => $isMember,
                'deposit_comment' => $depositComment,
            ];
        }

        // Add payer contributions from extra costs (money paid outside the booking system).
        $extraCosts = $this->getTeamEventExtraCosts($teamEventId);
        if (!empty($extraCosts)) {
            $resultByUid = [];
            foreach ($result as $index => $row) {
                $uid = isset($row['uid']) ? (int)$row['uid'] : 0;
                if ($uid > 0) {
                    $resultByUid[$uid] = $index;
                }
            }

            foreach ($extraCosts as $extraCost) {
                $payerUid = isset($extraCost['payer_user_id']) ? (int)$extraCost['payer_user_id'] : 0;
                $amount = isset($extraCost['amount']) ? (float)$extraCost['amount'] : 0.0;
                if ($payerUid <= 0 || $amount <= 0) {
                    continue;
                }

                if (isset($resultByUid[$payerUid])) {
                    $idx = (int)$resultByUid[$payerUid];
                    $result[$idx]['total_paid'] = MoneyCalculator::add($result[$idx]['total_paid'], $amount);
                    continue;
                }

                $userRow = $this->getTeamEventDbAdapter()->query(
                    'SELECT uid, alias, email FROM bs_users WHERE uid = ? LIMIT 1',
                    [$payerUid]
                )->current();
                if (!$userRow) {
                    continue;
                }

                $alias = isset($userRow['alias']) ? trim((string)$userRow['alias']) : '';
                $email = isset($userRow['email']) ? trim((string)$userRow['email']) : '';
                $result[] = [
                    'uid' => $payerUid,
                    'name' => $alias !== '' ? $alias : ('User ' . $payerUid),
                    'email' => $email,
                    'total_paid' => MoneyCalculator::roundMoney($amount),
                    'total_refunded' => 0.0,
                    'is_member' => false,
                    'deposit_comment' => 'Extrakosten',
                ];
                $resultByUid[$payerUid] = count($result) - 1;
            }
        }

        // Reduce contributed amount by refunds paid out from the team account for this event.
        if (!empty($result)) {
            $memberUids = [];
            foreach ($result as $row) {
                $uid = isset($row['uid']) ? (int)$row['uid'] : 0;
                if ($uid > 0 && !in_array($uid, $memberUids, true)) {
                    $memberUids[] = $uid;
                }
            }

            if (!empty($memberUids)) {
                $placeholders = implode(',', array_fill(0, count($memberUids), '?'));
                $refundTotalsByUid = [];

                try {
                    $refundRows = $this->getTeamEventDbAdapter()->query(
                        'SELECT d.user_id AS uid, COALESCE(SUM(d.amount), 0) AS total_refunded
                         FROM drink_deposits d
                         JOIN drink_orders o ON o.transfer_reference = d.transfer_reference
                         WHERE d.user_id IN (' . $placeholders . ')
                           AND d.createdbyuserid = ?
                           AND (d.deleted IS NULL OR d.deleted = 0)
                           AND o.user_id = ?
                           AND o.drink_id = -1
                           AND (o.deleted IS NULL OR o.deleted = 0)
                           AND (
                                o.teamevent_id = ?
                                OR (o.teamevent_id IS NULL AND TRIM(COALESCE(o.comment, "")) = ?)
                           )
                         GROUP BY d.user_id',
                        array_merge($memberUids, [$teamAdminUserId, $teamAdminUserId, $teamEventId, $teamEventLabel])
                    )->toArray();
                } catch (\Exception $e) {
                    // Fallback for installations without transfer_reference columns.
                    $refundRows = $this->getTeamEventDbAdapter()->query(
                        'SELECT d.user_id AS uid, COALESCE(SUM(d.amount), 0) AS total_refunded
                         FROM drink_deposits d
                         WHERE d.user_id IN (' . $placeholders . ')
                           AND d.createdbyuserid = ?
                           AND (d.deleted IS NULL OR d.deleted = 0)
                           AND (
                                d.teamevent_id = ?
                                OR (d.teamevent_id IS NULL AND TRIM(COALESCE(d.comment, "")) = ?)
                           )
                           AND LOWER(TRIM(COALESCE(d.comment, ""))) LIKE ?
                         GROUP BY d.user_id',
                        array_merge($memberUids, [$teamAdminUserId, $teamEventId, $teamEventLabel, 'geld empfangen von %'])
                    )->toArray();
                }

                foreach ($refundRows as $refundRow) {
                    $uid = isset($refundRow['uid']) ? (int)$refundRow['uid'] : 0;
                    $totalRefunded = MoneyCalculator::roundMoney(isset($refundRow['total_refunded']) ? (float)$refundRow['total_refunded'] : 0.0);
                    if ($uid > 0 && $totalRefunded > 0) {
                        $refundTotalsByUid[$uid] = $totalRefunded;
                    }
                }

                foreach ($result as $index => $row) {
                    $uid = isset($row['uid']) ? (int)$row['uid'] : 0;
                    $totalRefunded = isset($refundTotalsByUid[$uid]) ? (float)$refundTotalsByUid[$uid] : 0.0;
                    $result[$index]['total_refunded'] = MoneyCalculator::roundMoney($totalRefunded);
                }
            }
        }

        return $result;
    }

    protected function getTeamEventOrderRowsAndTotal($teamAdminUserId, $teamEventLabel, $memberUserIds = null, $teamEventId = 0)
    {
        $teamAdminUserId = (int)$teamAdminUserId;
        $teamEventLabel = $this->normalizeTeamEventLabel($teamEventLabel);
        if ($teamAdminUserId <= 0 || $teamEventLabel === '') {
            return ['rows' => [], 'total_sum' => 0.0, 'guest_donation_due_total' => 0.0, 'settlement_total_sum' => 0.0, 'extra_costs' => [], 'guest_donations' => []];
        }

        // Use provided teamEventId if available (for member-only events from different teams)
        $selectedTeamEventId = $teamEventId > 0 ? (int)$teamEventId : 0;
        $selectedTeamEvent = null;
        if ($selectedTeamEventId > 0) {
            $serviceManager = @$this->getServiceLocator();
            if (is_object($serviceManager) && method_exists($serviceManager, 'get')) {
                $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
                $selectedTeamEvent = $dbAdapter->query(
                    'SELECT id, comment, closed FROM drinks_teamevents WHERE id = ? LIMIT 1',
                    [$selectedTeamEventId]
                )->current();
            }
        }
        if (!$selectedTeamEvent || !isset($selectedTeamEvent['id']) || $selectedTeamEvent['id'] <= 0) {
            $selectedTeamEvent = $this->getTeamEventByLabel($teamAdminUserId, $teamEventLabel);
            $selectedTeamEventId = $selectedTeamEvent && isset($selectedTeamEvent['id']) ? (int)$selectedTeamEvent['id'] : 0;
        }
        $activeMembers = $selectedTeamEventId > 0
            ? $this->getTeamEventMembersWithContribution($teamAdminUserId, $selectedTeamEventId, $teamEventLabel)
            : [];
        $storedRelevanceByRowKey = $selectedTeamEventId > 0
            ? $this->getTeamEventOrderRelevanceMap($selectedTeamEventId)
            : [];
        $relevantMembers = [];
        $activeMemberNamesById = [];
        foreach ($activeMembers as $memberRow) {
            if (empty($memberRow['is_member'])) {
                continue;
            }
            $memberUid = isset($memberRow['uid']) ? (int)$memberRow['uid'] : 0;
            if ($memberUid <= 0) {
                continue;
            }
            $memberName = isset($memberRow['name']) ? (string)$memberRow['name'] : ('User ' . $memberUid);
            $relevantMembers[] = [
                'uid' => $memberUid,
                'name' => $memberName,
            ];
            $activeMemberNamesById[$memberUid] = $memberName;
        }

        // Build list of user IDs to query orders from: team admin + all members
        $orderUserIds = [$teamAdminUserId];
        if ($memberUserIds !== null && is_array($memberUserIds)) {
            foreach ($memberUserIds as $mid) {
                $midInt = (int)$mid;
                if ($midInt > 0 && !in_array($midInt, $orderUserIds)) {
                    $orderUserIds[] = $midInt;
                }
            }
        }

        $userIdsPlaceholder = implode(', ', array_fill(0, count($orderUserIds), '?'));
        
        // Build the teamevent filter condition
        // If a specific teamEventId is provided, use it directly for accurate results
        // Otherwise, look up events by team_admin_user_id and label
        $teameventFilter = '';
        $teameventParams = [];
        if ($selectedTeamEventId > 0) {
            $teameventFilter = 'o.teamevent_id = ?';
            $teameventParams[] = $selectedTeamEventId;
        } else {
            $teameventFilter = 'o.teamevent_id IN (
                SELECT e.id
                FROM drinks_teamevents e
                WHERE e.team_admin_user_id = ?
                  AND TRIM(COALESCE(e.comment, "")) = ?
            )';
            $teameventParams = [$teamAdminUserId, $teamEventLabel];
        }
        
        $orderRows = $this->getTeamEventDbAdapter()->query(
            'SELECT
                o.drink_id,
                o.price AS unit_price,
                COALESCE(c.name, "") AS category_name,
                COALESCE(c.sort_priority, 0) AS category_sort,
                d.name AS article,
                SUM(o.quantity) AS quantity,
                (0 - o.price) AS single_price,
                (0 - SUM(o.quantity * o.price)) AS total_price
             FROM drink_orders o
             JOIN drinks d ON d.id = o.drink_id
             LEFT JOIN drink_categories c ON c.id = d.category
             WHERE o.user_id IN (' . $userIdsPlaceholder . ')
              AND o.deleted = 0
              AND o.drink_id <> -1
              AND (
                  ' . $teameventFilter . '
                  OR (o.teamevent_id IS NULL AND TRIM(COALESCE(o.comment, "")) = ?)
              )
             GROUP BY o.drink_id, d.name, o.price, c.name, c.sort_priority
             ORDER BY category_sort ASC, category_name ASC, d.name ASC, o.price ASC',
            array_merge($orderUserIds, $teameventParams, [$teamEventLabel])
        )->toArray();

        $rows = [];
        foreach ($orderRows as $orderRow) {
            $totalPrice = isset($orderRow['total_price']) ? (float)$orderRow['total_price'] : 0.0;
            $drinkId = isset($orderRow['drink_id']) ? (int)$orderRow['drink_id'] : 0;
            $unitPrice = isset($orderRow['unit_price']) ? round((float)$orderRow['unit_price'], 2) : 0.0;
            $rowKey = $drinkId . '|' . number_format($unitPrice, 2, '.', '');

            $selectedRelevantIds = [];
            if (isset($storedRelevanceByRowKey[$rowKey]) && is_array($storedRelevanceByRowKey[$rowKey])) {
                foreach ($storedRelevanceByRowKey[$rowKey] as $memberId) {
                    $memberId = (int)$memberId;
                    if ($memberId > 0 && isset($activeMemberNamesById[$memberId])) {
                        $selectedRelevantIds[] = $memberId;
                    }
                }
                $selectedRelevantIds = array_values(array_unique($selectedRelevantIds));
            }

            $rowRelevantMembers = [];
            if (!empty($selectedRelevantIds)) {
                foreach ($selectedRelevantIds as $memberId) {
                    $rowRelevantMembers[] = [
                        'uid' => $memberId,
                        'name' => $activeMemberNamesById[$memberId],
                    ];
                }
            } else {
                $rowRelevantMembers = $relevantMembers;
            }

            $rowRelevantMemberCount = count($rowRelevantMembers);
            $rows[] = [
                'row_type' => 'order',
                'drink_id' => $drinkId,
                'unit_price' => $unitPrice,
                'category' => isset($orderRow['category_name']) ? (string)$orderRow['category_name'] : '',
                'article' => isset($orderRow['article']) ? (string)$orderRow['article'] : '',
                'quantity' => isset($orderRow['quantity']) ? (int)$orderRow['quantity'] : 0,
                'single_price' => MoneyCalculator::roundMoney(isset($orderRow['single_price']) ? (float)$orderRow['single_price'] : 0.0),
                'total_price' => MoneyCalculator::roundMoney($totalPrice),
                'relevant_members' => $rowRelevantMembers,
                'relevant_member_count' => $rowRelevantMemberCount,
                'share_per_member' => $rowRelevantMemberCount > 0 ? ((float)$totalPrice / (float)$rowRelevantMemberCount) : 0.0,
            ];
        }

        $extraCosts = [];
        if ($selectedTeamEventId > 0) {
            $rawExtraCosts = $this->getTeamEventExtraCosts($selectedTeamEventId);
            foreach ($rawExtraCosts as $extraCost) {
                $extraCostId = isset($extraCost['id']) ? (int)$extraCost['id'] : 0;
                $amount = isset($extraCost['amount']) ? (float)$extraCost['amount'] : 0.0;
                if ($extraCostId <= 0 || $amount <= 0) {
                    continue;
                }
                $signedAmount = MoneyCalculator::roundMoney(0.0 - abs($amount));

                $selectedRelevantIds = [];
                if (!empty($extraCost['relevant_member_ids']) && is_array($extraCost['relevant_member_ids'])) {
                    foreach ($extraCost['relevant_member_ids'] as $memberId) {
                        $memberId = (int)$memberId;
                        if ($memberId > 0 && isset($activeMemberNamesById[$memberId])) {
                            $selectedRelevantIds[] = $memberId;
                        }
                    }
                    $selectedRelevantIds = array_values(array_unique($selectedRelevantIds));
                }

                $rowRelevantMembers = [];
                if (!empty($selectedRelevantIds)) {
                    foreach ($selectedRelevantIds as $memberId) {
                        $rowRelevantMembers[] = [
                            'uid' => $memberId,
                            'name' => $activeMemberNamesById[$memberId],
                        ];
                    }
                } else {
                    $rowRelevantMembers = $relevantMembers;
                    $selectedRelevantIds = array_keys($activeMemberNamesById);
                }

                $rowRelevantMemberCount = count($rowRelevantMembers);
                $articleLabel = 'Extrakosten';
                $comment = isset($extraCost['comment']) ? trim((string)$extraCost['comment']) : '';
                $payerName = isset($extraCost['payer_name']) ? trim((string)$extraCost['payer_name']) : '';
                if ($comment !== '') {
                    $articleLabel .= ': ' . $comment;
                }
                if ($payerName !== '') {
                    $articleLabel .= ' (' . $payerName . ')';
                }

                $extraRowsEntry = [
                    'id' => $extraCostId,
                    'row_type' => 'extra_cost',
                    'extra_cost_id' => $extraCostId,
                    'payer_user_id' => isset($extraCost['payer_user_id']) ? (int)$extraCost['payer_user_id'] : 0,
                    'payer_name' => $payerName,
                    'comment' => $comment,
                    'amount' => MoneyCalculator::roundMoney($amount),
                    'total_price' => $signedAmount,
                    'relevant_member_ids' => $selectedRelevantIds,
                    'relevant_members' => $rowRelevantMembers,
                    'relevant_member_count' => $rowRelevantMemberCount,
                    'share_per_member' => $rowRelevantMemberCount > 0 ? ((float)$signedAmount / (float)$rowRelevantMemberCount) : 0.0,
                ];
                $extraCosts[] = $extraRowsEntry;

                $rows[] = [
                    'row_type' => 'extra_cost',
                    'extra_cost_id' => $extraCostId,
                    'drink_id' => 0,
                    'unit_price' => $signedAmount,
                    'category' => 'Extrakosten',
                    'article' => $articleLabel,
                    'quantity' => 1,
                    'single_price' => $signedAmount,
                    'total_price' => $signedAmount,
                    'relevant_members' => $rowRelevantMembers,
                    'relevant_member_count' => $rowRelevantMemberCount,
                    'share_per_member' => $rowRelevantMemberCount > 0 ? ((float)$signedAmount / (float)$rowRelevantMemberCount) : 0.0,
                ];
            }
        }

        $guestDonations = [];
        $guestDonationDueTotal = 0.0;
        if ($selectedTeamEventId > 0) {
            $rawGuestDonations = $this->getTeamEventGuestDonations($selectedTeamEventId);
            foreach ($rawGuestDonations as $guestDonation) {
                $guestDonationId = isset($guestDonation['id']) ? (int)$guestDonation['id'] : 0;
                $amount = isset($guestDonation['amount']) ? (float)$guestDonation['amount'] : 0.0;
                if ($guestDonationId <= 0 || $amount <= 0) {
                    continue;
                }
                $receiverUserId = isset($guestDonation['receiver_user_id']) ? (int)$guestDonation['receiver_user_id'] : 0;
                if ($receiverUserId <= 0 || !isset($activeMemberNamesById[$receiverUserId])) {
                    continue;
                }

                $comment = isset($guestDonation['comment']) ? trim((string)$guestDonation['comment']) : '';
                $receiverName = isset($guestDonation['receiver_name']) ? trim((string)$guestDonation['receiver_name']) : '';

                $guestDonations[] = [
                    'id' => $guestDonationId,
                    'team_event_id' => $selectedTeamEventId,
                    'receiver_user_id' => $receiverUserId,
                    'receiver_name' => $receiverName !== '' ? $receiverName : $activeMemberNamesById[$receiverUserId],
                    'comment' => $comment,
                    'amount' => MoneyCalculator::roundMoney(abs($amount)),
                    // Receiver has to transfer this amount additionally to the account.
                    'due_amount' => MoneyCalculator::roundMoney(0.0 - abs($amount)),
                ];
                $guestDonationDueTotal = MoneyCalculator::add($guestDonationDueTotal, 0.0 - abs($amount));
            }
        }

        $totalSum = 0.0;
        foreach ($rows as $row) {
            $totalSum = MoneyCalculator::add($totalSum, $row['total_price']);
        }

        return [
            'rows' => $rows,
            'total_sum' => $totalSum,
            'guest_donation_due_total' => MoneyCalculator::roundMoney($guestDonationDueTotal),
            'settlement_total_sum' => $totalSum,
            'extra_costs' => $extraCosts,
            'guest_donations' => $guestDonations,
        ];
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
            // Fallback to latest open team event if the preferred one is closed or invalid
            if (!$selectedTeamEvent || $this->isTeamEventClosedRow($selectedTeamEvent)) {
                $latestEvent = $this->getLatestTeamEventRow($teamAdminUserId);
                if ($latestEvent && !$this->isTeamEventClosedRow($latestEvent)) {
                    return $latestEvent;
                }
            }
            return null;
        }

        $newTeamEventLabel = $this->normalizeTeamEventLabel($newTeamEventLabel);
        if ($newTeamEventLabel !== '') {
            return $this->getOrCreateTeamEventByLabel($teamAdminUserId, $newTeamEventLabel);
        }

        // Fallback: return the latest open team event when no specific ID or label is provided
        $latestEvent = $this->getLatestTeamEventRow($teamAdminUserId);
        if ($latestEvent && !$this->isTeamEventClosedRow($latestEvent)) {
            return $latestEvent;
        }

        return null;
    }

    protected function calculateTeamEventBalance($teamAdminUserId, $teamEventId, $teamEventLabel, $includeTransferOrders = false)
    {
        $teamAdminUserId = (int)$teamAdminUserId;
        $teamEventId = (int)$teamEventId;
        $teamEventLabel = $this->normalizeTeamEventLabel($teamEventLabel);
        $includeTransferOrders = (bool)$includeTransferOrders;
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

        $orderSql =
            'SELECT COALESCE(SUM(quantity * price), 0) AS total
             FROM drink_orders
             WHERE user_id = ?
               AND (deleted IS NULL OR deleted = 0)';
        if (!$includeTransferOrders) {
            $orderSql .= ' AND drink_id <> -1';
        }
        $orderSql .=
            ' AND (
                    teamevent_id = ?
                    OR (teamevent_id IS NULL AND TRIM(COALESCE(comment, "")) = ?)
               )';

        $orderRow = $dbAdapter->query(
            $orderSql,
            [$teamAdminUserId, $teamEventId, $teamEventLabel]
        )->current();

        $depositTotal = $depositRow && isset($depositRow['total']) ? (float)$depositRow['total'] : 0.0;
        $orderTotal = $orderRow && isset($orderRow['total']) ? (float)$orderRow['total'] : 0.0;
        return MoneyCalculator::subtract($depositTotal, $orderTotal);
    }

    protected function processTeamEventSettlementRefunds($teamAdminUserId, $teamEventId, array $refunds, $allowClosed = false)
    {
        $teamAdminUserId = (int)$teamAdminUserId;
        $teamEventId = (int)$teamEventId;
        if ($teamAdminUserId <= 0 || $teamEventId <= 0) {
            return ['success' => false, 'error' => 'invalid_input'];
        }

        $teamEvent = $this->getTeamEventById($teamAdminUserId, $teamEventId);
        if (!$teamEvent) {
            return ['success' => false, 'error' => 'not_found'];
        }
        if ($this->isTeamEventClosedRow($teamEvent) && !$allowClosed) {
            return ['success' => false, 'error' => 'already_closed'];
        }
        if (!method_exists($this, 'executeMoneyTransfer')) {
            return ['success' => false, 'error' => 'transfer_unavailable'];
        }

        $allowedMemberIds = array_fill_keys($this->getTeamEventMemberUserIds($teamEventId), true);
        $validRefunds = [];
        foreach ($refunds as $refundRow) {
            if (!is_array($refundRow)) {
                continue;
            }
            $receiverUserId = isset($refundRow['receiver_user_id']) ? (int)$refundRow['receiver_user_id'] : 0;
            $amountRaw = isset($refundRow['amount']) ? (string)$refundRow['amount'] : '0';
            $amountRaw = str_replace(',', '.', trim($amountRaw));
            $amount = MoneyCalculator::roundMoney((float)$amountRaw);
            if ($receiverUserId <= 0 || $amount == 0.0) {
                continue;
            }
            if (!isset($allowedMemberIds[$receiverUserId])) {
                continue;
            }

            $validRefunds[] = [
                'receiver_user_id' => $receiverUserId,
                'amount' => $amount,
            ];
        }

        if (empty($validRefunds)) {
            return ['success' => true, 'total_refund' => 0.0, 'transfers' => []];
        }

        $serviceManager = $this->getServiceLocator();
        $teamEventLabel = isset($teamEvent['comment']) ? trim((string)$teamEvent['comment']) : '';
        $eventBalance = 0.0;
        if ($teamEventLabel !== '') {
            $eventBalance = (float)$this->calculateTeamEventBalance($teamAdminUserId, $teamEventId, $teamEventLabel);
        }
        $totalRefund = 0.0;
        $totalCharge = 0.0;
        $netOutgoing = 0.0;
        foreach ($validRefunds as $refundRow) {
            $amount = MoneyCalculator::roundMoney((float)$refundRow['amount']);
            if ($amount > 0) {
                $totalRefund = MoneyCalculator::add($totalRefund, $amount);
            } else {
                $totalCharge = MoneyCalculator::add($totalCharge, abs($amount));
            }
            $netOutgoing = MoneyCalculator::add($netOutgoing, $amount);
        }
        // Settlement is valid when event balance after requested charges/payouts is non-negative.
        $eventRemaining = MoneyCalculator::add($eventBalance, MoneyCalculator::subtract($totalCharge, $totalRefund));
        if (MoneyCalculator::roundMoney($eventRemaining) < 0) {
            return [
                'success' => false,
                'error' => 'insufficient_settlement_balance',
                'event_balance' => $eventBalance,
                'event_remaining' => $eventRemaining,
                'total_refund' => $totalRefund,
                'total_charge' => $totalCharge,
                'net_outgoing' => $netOutgoing,
            ];
        }

        $transfers = [];
        foreach ($validRefunds as $refundRow) {
            $receiverUserId = (int)$refundRow['receiver_user_id'];
            $amount = MoneyCalculator::roundMoney((float)$refundRow['amount']);
            if ($amount > 0) {
                $transferResult = $this->executeMoneyTransfer($teamAdminUserId, $receiverUserId, $amount, $teamEventId, true);
                $direction = 'team_to_member';
                $effectiveAmount = $amount;
            } else {
                $transferResult = $this->executeMoneyTransfer($receiverUserId, $teamAdminUserId, abs($amount), $teamEventId, true);
                $direction = 'member_to_team';
                $effectiveAmount = 0.0 - abs($amount);
            }
            $statusCode = isset($transferResult['statusCode']) ? (int)$transferResult['statusCode'] : 500;
            $payload = isset($transferResult['payload']) && is_array($transferResult['payload']) ? $transferResult['payload'] : [];
            if ($statusCode !== 200 || empty($payload['success'])) {
                return [
                    'success' => false,
                    'error' => 'transfer_failed',
                    'receiver_user_id' => $receiverUserId,
                    'amount' => $effectiveAmount,
                    'transfer_result' => $payload,
                    'transfers' => $transfers,
                ];
            }
            $transfers[] = [
                'receiver_user_id' => $receiverUserId,
                'amount' => $effectiveAmount,
                'direction' => $direction,
            ];
        }

        return [
            'success' => true,
            'total_refund' => $totalRefund,
            'total_charge' => $totalCharge,
            'net_outgoing' => $netOutgoing,
            'transfers' => $transfers,
        ];
    }

    protected function getTeamEventsWithBalances($teamAdminUserId, $includeClosed = false, $includeTransferOrders = false)
    {
        $includeClosed = (bool)$includeClosed;
        $includeTransferOrders = (bool)$includeTransferOrders;
        $dbAdapter = $this->getTeamEventDbAdapter();
        try {
            $rows = $dbAdapter->query(
                'SELECT ' . $this->getTeamEventSelectColumnsSql() . ' FROM drinks_teamevents WHERE team_admin_user_id = ?' . $this->getTeamEventOpenFilterSql($includeClosed) . ' ORDER BY created_at DESC, id DESC',
                [$teamAdminUserId]
            )->toArray();
        } catch (\Exception $e) {
            try {
                $rows = $dbAdapter->query(
                    'SELECT ' . $this->getTeamEventSelectColumnsSql() . ' FROM drinks_teamevents WHERE team_admin_user_id = ?' . $this->getTeamEventOpenFilterSql($includeClosed) . ' ORDER BY id DESC',
                    [$teamAdminUserId]
                )->toArray();
            } catch (\Exception $inner) {
                return [];
            }
        }

        $result = [];
        foreach ($rows as $row) {
            $teamEventId = isset($row['id']) ? (int)$row['id'] : 0;
            $teamEventLabel = isset($row['comment']) ? trim((string)$row['comment']) : '';
            if ($teamEventId <= 0 || $teamEventLabel === '') {
                continue;
            }

            $balance = 0.0;
            try {
                $balance = $this->calculateTeamEventBalance($teamAdminUserId, $teamEventId, $teamEventLabel, $includeTransferOrders);
            } catch (\Exception $e) {
                $balance = 0.0;
            }

            $result[] = [
                'id' => $teamEventId,
                'label' => $teamEventLabel,
                'balance' => $balance,
                'closed' => $this->canUseTeamEventClosedColumn() ? ((isset($row['closed']) && (int)$row['closed'] === 1) ? 1 : 0) : 0,
            ];
        }

        return $result;
    }

    protected function canUseTeamEventExtraCostsTable()
    {
        static $hasTable = null;
        if ($hasTable !== null) {
            return $hasTable;
        }

        try {
            $tableRow = $this->getTeamEventDbAdapter()->query(
                "SHOW TABLES LIKE 'drinks_teamevent_extra_costs'",
                []
            )->current();
            $hasTable = (bool)$tableRow;
        } catch (\Exception $e) {
            $hasTable = false;
        }

        return $hasTable;
    }

    protected function canUseTeamEventExtraCostRelevanceTable()
    {
        static $hasTable = null;
        if ($hasTable !== null) {
            return $hasTable;
        }

        try {
            $tableRow = $this->getTeamEventDbAdapter()->query(
                "SHOW TABLES LIKE 'drinks_teamevent_extra_cost_relevance'",
                []
            )->current();
            $hasTable = (bool)$tableRow;
        } catch (\Exception $e) {
            $hasTable = false;
        }

        return $hasTable;
    }

    protected function canUseTeamEventGuestDonationsTable()
    {
        static $hasTable = null;
        if ($hasTable !== null) {
            return $hasTable;
        }

        try {
            $tableRow = $this->getTeamEventDbAdapter()->query(
                "SHOW TABLES LIKE 'drinks_teamevent_guest_donations'",
                []
            )->current();
            $hasTable = (bool)$tableRow;
        } catch (\Exception $e) {
            $hasTable = false;
        }

        return $hasTable;
    }

    protected function getTeamEventExtraCosts($teamEventId, $includeSoftDeleted = false)
    {
        if (!$this->canUseTeamEventExtraCostsTable()) {
            return [];
        }

        $teamEventId = (int)$teamEventId;
        if ($teamEventId <= 0) {
            return [];
        }

        $deleteFilter = $includeSoftDeleted ? '' : ' AND (deleted IS NULL OR deleted = 0)';
        $rows = $this->getTeamEventDbAdapter()->query(
            'SELECT ec.id, ec.team_event_id, ec.payer_user_id, ec.amount, ec.comment, ec.created_at, ec.updated_at, ec.deleted, u.alias, u.email
             FROM drinks_teamevent_extra_costs ec
             JOIN bs_users u ON u.uid = ec.payer_user_id
             WHERE ec.team_event_id = ?' . $deleteFilter . '
             ORDER BY ec.created_at ASC',
            [$teamEventId]
        )->toArray();

        $result = [];
        foreach ($rows as $row) {
            $extraCostId = isset($row['id']) ? (int)$row['id'] : 0;
            if ($extraCostId <= 0) {
                continue;
            }

            $payerUid = isset($row['payer_user_id']) ? (int)$row['payer_user_id'] : 0;
            $relevance = $this->getTeamEventExtraCostRelevance($extraCostId);
            $relevanceIds = [];
            foreach ($relevance as $rel) {
                $memberId = isset($rel['member_user_id']) ? (int)$rel['member_user_id'] : 0;
                if ($memberId > 0) {
                    $relevanceIds[] = $memberId;
                }
            }

            $result[] = [
                'id' => $extraCostId,
                'team_event_id' => isset($row['team_event_id']) ? (int)$row['team_event_id'] : 0,
                'payer_user_id' => $payerUid,
                'payer_name' => isset($row['alias']) && trim($row['alias']) !== '' ? (string)$row['alias'] : ('User ' . $payerUid),
                'payer_email' => isset($row['email']) ? (string)$row['email'] : '',
                'amount' => isset($row['amount']) ? (float)$row['amount'] : 0.0,
                'comment' => isset($row['comment']) ? (string)$row['comment'] : '',
                'relevant_member_ids' => $relevanceIds,
                'created_at' => isset($row['created_at']) ? (string)$row['created_at'] : '',
                'updated_at' => isset($row['updated_at']) ? (string)$row['updated_at'] : '',
                'deleted' => isset($row['deleted']) ? (int)$row['deleted'] : 0,
            ];
        }

        return $result;
    }

    protected function getTeamEventExtraCostRelevance($extraCostId)
    {
        if (!$this->canUseTeamEventExtraCostsTable() || !$this->canUseTeamEventExtraCostRelevanceTable()) {
            return [];
        }

        $extraCostId = (int)$extraCostId;
        if ($extraCostId <= 0) {
            return [];
        }

        return $this->getTeamEventDbAdapter()->query(
            'SELECT member_user_id FROM drinks_teamevent_extra_cost_relevance
             WHERE extra_cost_id = ?
             ORDER BY member_user_id ASC',
            [$extraCostId]
        )->toArray();
    }

    protected function saveTeamEventExtraCost($teamEventId, $payerUserId, $amount, $comment = '', $relevantMemberIds = [])
    {
        if (!$this->canUseTeamEventExtraCostsTable()) {
            throw new \Exception('Extra costs table not available');
        }

        $teamEventId = (int)$teamEventId;
        $payerUserId = (int)$payerUserId;
        $amount = (float)$amount;
        $comment = trim((string)($comment ?? ''));

        if ($teamEventId <= 0) {
            throw new \Exception('Invalid team event ID');
        }
        if ($payerUserId <= 0) {
            throw new \Exception('Invalid payer user ID');
        }
        if ($amount <= 0) {
            throw new \Exception('Invalid amount');
        }

        $dbAdapter = $this->getTeamEventDbAdapter();
        $result = $dbAdapter->query(
            'INSERT INTO drinks_teamevent_extra_costs (team_event_id, payer_user_id, amount, comment)
             VALUES (?, ?, ?, ?)',
            [$teamEventId, $payerUserId, $amount, $comment]
        );

        $extraCostId = $dbAdapter->getDriver()->getLastGeneratedValue();
        if (!$extraCostId) {
            throw new \Exception('Failed to insert extra cost');
        }

        $this->saveTeamEventExtraCostRelevance($extraCostId, $relevantMemberIds);

        return (int)$extraCostId;
    }

    protected function updateTeamEventExtraCost($extraCostId, $teamEventId, $payerUserId, $amount, $comment = '', $relevantMemberIds = [])
    {
        if (!$this->canUseTeamEventExtraCostsTable()) {
            throw new \Exception('Extra costs table not available');
        }

        $extraCostId = (int)$extraCostId;
        $teamEventId = (int)$teamEventId;
        $payerUserId = (int)$payerUserId;
        $amount = (float)$amount;
        $comment = trim((string)($comment ?? ''));

        if ($extraCostId <= 0 || $teamEventId <= 0 || $payerUserId <= 0 || $amount <= 0) {
            throw new \Exception('Invalid parameters');
        }

        $dbAdapter = $this->getTeamEventDbAdapter();
        $dbAdapter->query(
            'UPDATE drinks_teamevent_extra_costs
             SET payer_user_id = ?, amount = ?, comment = ?, updated_at = NOW()
             WHERE id = ? AND team_event_id = ?',
            [$payerUserId, $amount, $comment, $extraCostId, $teamEventId]
        );

        $this->saveTeamEventExtraCostRelevance($extraCostId, $relevantMemberIds);
    }

    protected function saveTeamEventExtraCostRelevance($extraCostId, $relevantMemberIds = [])
    {
        if (!$this->canUseTeamEventExtraCostsTable() || !$this->canUseTeamEventExtraCostRelevanceTable()) {
            return;
        }

        $extraCostId = (int)$extraCostId;
        if ($extraCostId <= 0) {
            return;
        }

        $dbAdapter = $this->getTeamEventDbAdapter();

        // Delete existing relevance entries
        $dbAdapter->query(
            'DELETE FROM drinks_teamevent_extra_cost_relevance WHERE extra_cost_id = ?',
            [$extraCostId]
        );

        // Insert new relevance entries
        $relevantMemberIds = array_filter(array_map('intval', (array)$relevantMemberIds));
        if (empty($relevantMemberIds)) {
            return;
        }

        $relevantMemberIds = array_unique($relevantMemberIds);
        foreach ($relevantMemberIds as $memberId) {
            if ($memberId > 0) {
                $dbAdapter->query(
                    'INSERT INTO drinks_teamevent_extra_cost_relevance (extra_cost_id, member_user_id)
                     VALUES (?, ?)',
                    [$extraCostId, $memberId]
                );
            }
        }
    }

    protected function deleteTeamEventExtraCost($extraCostId, $softDelete = true)
    {
        if (!$this->canUseTeamEventExtraCostsTable()) {
            throw new \Exception('Extra costs table not available');
        }

        $extraCostId = (int)$extraCostId;
        if ($extraCostId <= 0) {
            throw new \Exception('Invalid extra cost ID');
        }

        $dbAdapter = $this->getTeamEventDbAdapter();
        if ($softDelete) {
            $dbAdapter->query(
                'UPDATE drinks_teamevent_extra_costs SET deleted = 1 WHERE id = ?',
                [$extraCostId]
            );
        } else {
            $dbAdapter->query(
                'DELETE FROM drinks_teamevent_extra_cost_relevance WHERE extra_cost_id = ?',
                [$extraCostId]
            );
            $dbAdapter->query(
                'DELETE FROM drinks_teamevent_extra_costs WHERE id = ?',
                [$extraCostId]
            );
        }
    }

    protected function getTeamEventGuestDonations($teamEventId, $includeSoftDeleted = false)
    {
        if (!$this->canUseTeamEventGuestDonationsTable()) {
            return [];
        }

        $teamEventId = (int)$teamEventId;
        if ($teamEventId <= 0) {
            return [];
        }

        $deleteFilter = $includeSoftDeleted ? '' : ' AND (gd.deleted IS NULL OR gd.deleted = 0)';
        $rows = $this->getTeamEventDbAdapter()->query(
            'SELECT gd.id, gd.team_event_id, gd.receiver_user_id, gd.amount, gd.comment, gd.created_at, gd.updated_at, gd.deleted, u.alias, u.email
             FROM drinks_teamevent_guest_donations gd
             JOIN bs_users u ON u.uid = gd.receiver_user_id
             WHERE gd.team_event_id = ?' . $deleteFilter . '
             ORDER BY gd.created_at ASC',
            [$teamEventId]
        )->toArray();

        $result = [];
        foreach ($rows as $row) {
            $guestDonationId = isset($row['id']) ? (int)$row['id'] : 0;
            if ($guestDonationId <= 0) {
                continue;
            }

            $receiverUid = isset($row['receiver_user_id']) ? (int)$row['receiver_user_id'] : 0;
            $result[] = [
                'id' => $guestDonationId,
                'team_event_id' => isset($row['team_event_id']) ? (int)$row['team_event_id'] : 0,
                'receiver_user_id' => $receiverUid,
                'receiver_name' => isset($row['alias']) && trim($row['alias']) !== '' ? (string)$row['alias'] : ('User ' . $receiverUid),
                'receiver_email' => isset($row['email']) ? (string)$row['email'] : '',
                'amount' => isset($row['amount']) ? (float)$row['amount'] : 0.0,
                'comment' => isset($row['comment']) ? (string)$row['comment'] : '',
                'created_at' => isset($row['created_at']) ? (string)$row['created_at'] : '',
                'updated_at' => isset($row['updated_at']) ? (string)$row['updated_at'] : '',
                'deleted' => isset($row['deleted']) ? (int)$row['deleted'] : 0,
            ];
        }

        return $result;
    }

    protected function saveTeamEventGuestDonation($teamEventId, $receiverUserId, $amount, $comment = '')
    {
        if (!$this->canUseTeamEventGuestDonationsTable()) {
            throw new \Exception('Guest donations table not available');
        }

        $teamEventId = (int)$teamEventId;
        $receiverUserId = (int)$receiverUserId;
        $amount = (float)$amount;
        $comment = trim((string)($comment ?? ''));

        if ($teamEventId <= 0) {
            throw new \Exception('Invalid team event ID');
        }
        if ($receiverUserId <= 0) {
            throw new \Exception('Invalid receiver user ID');
        }
        if ($amount <= 0) {
            throw new \Exception('Invalid amount');
        }

        $dbAdapter = $this->getTeamEventDbAdapter();
        $dbAdapter->query(
            'INSERT INTO drinks_teamevent_guest_donations (team_event_id, receiver_user_id, amount, comment)
             VALUES (?, ?, ?, ?)',
            [$teamEventId, $receiverUserId, $amount, $comment]
        );

        $guestDonationId = $dbAdapter->getDriver()->getLastGeneratedValue();
        if (!$guestDonationId) {
            throw new \Exception('Failed to insert guest donation');
        }

        return (int)$guestDonationId;
    }

    protected function updateTeamEventGuestDonation($guestDonationId, $teamEventId, $receiverUserId, $amount, $comment = '')
    {
        if (!$this->canUseTeamEventGuestDonationsTable()) {
            throw new \Exception('Guest donations table not available');
        }

        $guestDonationId = (int)$guestDonationId;
        $teamEventId = (int)$teamEventId;
        $receiverUserId = (int)$receiverUserId;
        $amount = (float)$amount;
        $comment = trim((string)($comment ?? ''));

        if ($guestDonationId <= 0 || $teamEventId <= 0 || $receiverUserId <= 0 || $amount <= 0) {
            throw new \Exception('Invalid parameters');
        }

        $this->getTeamEventDbAdapter()->query(
            'UPDATE drinks_teamevent_guest_donations
             SET receiver_user_id = ?, amount = ?, comment = ?, updated_at = NOW()
             WHERE id = ? AND team_event_id = ?',
            [$receiverUserId, $amount, $comment, $guestDonationId, $teamEventId]
        );
    }

    protected function deleteTeamEventGuestDonation($guestDonationId, $softDelete = true)
    {
        if (!$this->canUseTeamEventGuestDonationsTable()) {
            throw new \Exception('Guest donations table not available');
        }

        $guestDonationId = (int)$guestDonationId;
        if ($guestDonationId <= 0) {
            throw new \Exception('Invalid guest donation ID');
        }

        $dbAdapter = $this->getTeamEventDbAdapter();
        if ($softDelete) {
            $dbAdapter->query(
                'UPDATE drinks_teamevent_guest_donations SET deleted = 1 WHERE id = ?',
                [$guestDonationId]
            );
        } else {
            $dbAdapter->query(
                'DELETE FROM drinks_teamevent_guest_donations WHERE id = ?',
                [$guestDonationId]
            );
        }
    }

    /**
     * Get full data for a specific team event including orders, members, extra costs, guest donations
     * Returns data compatible with buildTeamStatsPayload output format
     */
    protected function getTeamEventData($teamAdminUserId, $teamEventId, $userId = null)
    {
        $teamAdminUserId = (int)$teamAdminUserId;
        $teamEventId = (int)$teamEventId;
        if ($teamAdminUserId <= 0 || $teamEventId <= 0) {
            return null;
        }

        try {
            // Get team event row
            $teamEvent = $this->getTeamEventById($teamAdminUserId, $teamEventId);
            if (!$teamEvent) {
                return null;
            }

            $eventLabel = isset($teamEvent['comment']) ? trim((string)$teamEvent['comment']) : '';
            if ($eventLabel === '') {
                return null;
            }

            // Get team alias
            $teamAlias = '';
            $aliasRow = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter')->query(
                'SELECT alias FROM drink_aliases WHERE user_id = ?',
                [$teamAdminUserId]
            )->current();
            if ($aliasRow && isset($aliasRow['alias'])) {
                $teamAlias = $aliasRow['alias'];
            }

            // Get order data
            $orderData = $this->getTeamEventOrderRowsAndTotal($teamAdminUserId, $eventLabel, null, $teamEventId);

            // Get account balance
            $drinkManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkManager');
            $accountBalance = $drinkManager->calculateUserDrinkBalance($teamAdminUserId, $this->getServiceLocator());

            // Get member candidates
            $memberCandidates = [];
            $activeMembers = $this->getTeamEventMembersWithContribution($teamAdminUserId, $teamEventId, $eventLabel);
            foreach ($activeMembers as $member) {
                if (!empty($member['is_member'])) {
                    $memberCandidates[] = [
                        'uid' => (int)$member['uid'],
                        'name' => (string)$member['name'],
                        'contribution' => isset($member['contribution']) ? (float)$member['contribution'] : 0.0,
                    ];
                }
            }

            // Get guest donations
            $guestDonations = [];
            if ($this->canUseTeamEventGuestDonationsTable()) {
                $guestDonations = $this->getTeamEventGuestDonations($teamEventId, false);
            }

            return [
                'team_event' => [
                    'id' => (int)$teamEvent['id'],
                    'label' => $eventLabel,
                    'team_alias' => $teamAlias,
                    'team_admin_user_id' => $teamAdminUserId,
                    'closed' => $this->isTeamEventClosed($teamEvent) ? 1 : 0,
                ],
                'orders' => $orderData['rows'],
                'total_sum' => $orderData['total_sum'],
                'guest_donation_due_total' => $orderData['guest_donation_due_total'],
                'settlement_total_sum' => $orderData['settlement_total_sum'],
                'extra_costs' => $orderData['extra_costs'],
                'member_candidates' => $memberCandidates,
                'guest_donations' => $guestDonations,
                'account_balance' => $accountBalance,
            ];
        } catch (\Throwable $e) {
            error_log('getTeamEventData error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if a column exists in a table
     */
    protected function hasClosedColumn($db, $tableName)
    {
        try {
            $col = $db->query("SHOW COLUMNS FROM " . $tableName . " LIKE 'closed'", [])->current();
            return (bool)$col;
        } catch (\Throwable $e) {
            return false;
        }
    }

     /**
      * Check if a team event is closed
      */
    protected function isTeamEventClosed($teamEvent)
    {
        if (!$teamEvent) {
            return false;
        }
        return isset($teamEvent['closed']) && (int)$teamEvent['closed'] !== 0;
    }

    /**
     * Get team events for a user (both admin-owned and member-assigned events).
     * Returns an array with:
     *   - 'events': array of event rows with id, label, team_alias, team_admin_user_id, closed
     *   - 'teamAdminUserIds': unique list of team admin user IDs
     *   - 'adminEventIds': list of event IDs the user owns
     *   - 'memberEventIds': list of event IDs the user is a member of (but doesn't own)
     *   - 'isTeamLead': whether the user is a team lead for any event
     *   - 'isTeamMember': whether the user is a member of any event (but doesn't own)
     */
    protected function getTeamEventsForUser($userId, $isTeamAccount = false)
    {
        $userId = (int)$userId;
        $dbAdapter = $this->getTeamEventDbAdapter();

        // Get admin-owned events (events where user is the team lead)
        $adminEventRows = $dbAdapter->query(
            'SELECT id, comment, team_admin_user_id, closed FROM drinks_teamevents WHERE team_admin_user_id = ? ORDER BY id DESC',
            [$userId]
        )->toArray();

        $adminEventIds = [];
        foreach ($adminEventRows as $er) {
            $adminEventIds[] = (int)$er['id'];
        }

        // Get member-assigned events (events where user is a member but doesn't own)
        $memberEventRows = $dbAdapter->query(
            'SELECT DISTINCT dte.id, dte.comment, dte.team_admin_user_id, dte.closed FROM drinks_teamevents dte
             INNER JOIN drinks_teamevent_members dtm ON dte.id = dtm.team_event_id
             WHERE dtm.user_id = ?
             ORDER BY dte.id DESC',
            [$userId]
        )->toArray();

        $memberEventIds = [];
        $memberTeamAdminUserIds = [];
        foreach ($memberEventRows as $mr) {
            $eventId = (int)$mr['id'];
            if (!in_array($eventId, $adminEventIds)) {
                $memberEventIds[] = $eventId;
                $memberTeamAdminUserIds[] = (int)$mr['team_admin_user_id'];
            }
        }

        // Merge all event IDs
        $allEventIds = array_unique(array_merge($adminEventIds, $memberEventIds));

        // Get team aliases for all unique team_admin_user_ids
        $allTeamAdminUserIds = array_unique(array_merge(
            array_column($adminEventRows, 'team_admin_user_id'),
            $memberTeamAdminUserIds
        ));

        $teamAliasMap = [];
        if (!empty($allTeamAdminUserIds)) {
            $placeholders = implode(', ', array_fill(0, count($allTeamAdminUserIds), '?'));
            $aliasRows = $dbAdapter->query(
                'SELECT user_id, alias FROM drink_aliases WHERE user_id IN (' . $placeholders . ')',
                $allTeamAdminUserIds
            )->toArray();
            foreach ($aliasRows as $ar) {
                $teamAliasMap[(int)$ar['user_id']] = isset($ar['alias']) ? $ar['alias'] : '';
            }
        }

        // Build combined events array
        $events = [];
        $seenEventIds = [];
        foreach ($adminEventRows as $er) {
            $eventId = (int)$er['id'];
            if (!isset($seenEventIds[$eventId])) {
                $seenEventIds[$eventId] = true;
                $adminUserId = (int)$er['team_admin_user_id'];
                $events[] = [
                    'id' => $eventId,
                    'label' => isset($er['comment']) ? trim((string)$er['comment']) : '',
                    'team_admin_user_id' => $adminUserId,
                    'team_alias' => isset($teamAliasMap[$adminUserId]) ? $teamAliasMap[$adminUserId] : '',
                    'closed' => isset($er['closed']) ? (int)$er['closed'] : 0,
                ];
            }
        }
        foreach ($memberEventRows as $mr) {
            $eventId = (int)$mr['id'];
            if (!isset($seenEventIds[$eventId]) && !in_array($eventId, $adminEventIds)) {
                $seenEventIds[$eventId] = true;
                $adminUserId = (int)$mr['team_admin_user_id'];
                $events[] = [
                    'id' => $eventId,
                    'label' => isset($mr['comment']) ? trim((string)$mr['comment']) : '',
                    'team_admin_user_id' => $adminUserId,
                    'team_alias' => isset($teamAliasMap[$adminUserId]) ? $teamAliasMap[$adminUserId] : '',
                    'closed' => isset($mr['closed']) ? (int)$mr['closed'] : 0,
                ];
            }
        }

        $isTeamLead = !empty($adminEventIds);
        $isTeamMember = !empty($memberEventIds);

        return [
            'events' => $events,
            'teamAdminUserIds' => array_unique(array_column($adminEventRows, 'team_admin_user_id')),
            'adminEventIds' => $adminEventIds,
            'memberEventIds' => $memberEventIds,
            'isTeamLead' => $isTeamLead,
            'isTeamMember' => $isTeamMember,
        ];
    }

    /**
     * Build team stats payload for the team stats modal.
     */
    protected function buildTeamStatsPayload($teamAdminUserId, $teamEventLabel, array $extra = [])
    {
        $teamAdminUserId = (int)$teamAdminUserId;
        $teamEventLabel = $this->normalizeTeamEventLabel($teamEventLabel);
        if ($teamAdminUserId <= 0 || $teamEventLabel === '') {
            return [
                'spieltag' => '',
                'team_event_id' => 0,
                'team_event_closed' => false,
                'can_close_team_event' => false,
                'rows' => [],
                'total_sum' => '0.00',
                'active_members' => [],
                'guest_donations' => [],
                'extra_costs' => [],
                'can_manage_members' => false,
            ];
        }

        $dbAdapter = $this->getTeamEventDbAdapter();

        // Get selected team event ID from extra params first
        $selectedTeamEventId = isset($extra['team_event_id']) && $extra['team_event_id'] > 0
            ? (int)$extra['team_event_id']
            : 0;

        // Look up the team event
        $selectedTeamEvent = null;
        if ($selectedTeamEventId > 0) {
            $selectedTeamEvent = $dbAdapter->query(
                'SELECT id, comment, closed FROM drinks_teamevents WHERE id = ? LIMIT 1',
                [$selectedTeamEventId]
            )->current();
        }

        // Fallback: try to find by label
        if (!$selectedTeamEvent || !isset($selectedTeamEvent['id']) || $selectedTeamEvent['id'] <= 0) {
            $selectedTeamEvent = $this->getTeamEventByLabel($teamAdminUserId, $teamEventLabel);
            $selectedTeamEventId = $selectedTeamEvent && isset($selectedTeamEvent['id']) ? (int)$selectedTeamEvent['id'] : 0;
        }

        $isClosed = $this->isTeamEventClosedRow($selectedTeamEvent);

        // Get member user IDs from extra params or from the database
        $memberUserIds = isset($extra['member_user_ids']) && is_array($extra['member_user_ids'])
            ? $extra['member_user_ids']
            : ($selectedTeamEventId > 0 ? $this->getTeamEventMemberUserIds($selectedTeamEventId) : []);

        // Get order rows and total
        $stats = $this->getTeamEventOrderRowsAndTotal($teamAdminUserId, $teamEventLabel, $memberUserIds, $selectedTeamEventId);

        // Get active members with contributions
        $activeMembers = $selectedTeamEventId > 0
            ? $this->getTeamEventMembersWithContribution($teamAdminUserId, $selectedTeamEventId, $teamEventLabel)
            : [];

        // Use enriched rows from stats (contains relevant_members/share_per_member),
        // fall back to direct table reads only when stats did not populate them.
        $guestDonations = isset($stats['guest_donations']) && is_array($stats['guest_donations'])
            ? $stats['guest_donations']
            : ($selectedTeamEventId > 0 ? $this->getTeamEventGuestDonations($selectedTeamEventId) : []);

        $extraCosts = isset($stats['extra_costs']) && is_array($stats['extra_costs'])
            ? $stats['extra_costs']
            : ($selectedTeamEventId > 0 ? $this->getTeamEventExtraCosts($selectedTeamEventId) : []);

        // Get member candidates for management
        $canManageMembers = $selectedTeamEventId > 0;

        return array_merge([
            'spieltag' => $teamEventLabel,
            'team_event_id' => $selectedTeamEventId,
            'team_event_closed' => $isClosed,
            'can_close_team_event' => true,
            'rows' => $stats['rows'],
            'total_sum' => $stats['total_sum'],
            'active_members' => $activeMembers,
            'guest_donations' => $guestDonations,
            'extra_costs' => $extraCosts,
            'can_manage_members' => $canManageMembers,
        ], $extra);
    }
}
