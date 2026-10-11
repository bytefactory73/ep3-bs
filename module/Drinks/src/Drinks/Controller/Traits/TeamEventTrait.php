<?php

namespace Drinks\Controller\Traits;

use Base\Service\MoneyCalculator;
use Drinks\Service\DbSchema;

/**
 * Spieltage (team events) of team accounts: members, costs, settlement. Requires MoneyTransferTrait.
 */
trait TeamEventTrait
{

    protected function getTeamEventSelectColumnsSql()
    {
        return $this->hasTeamEventClosedColumn() ? 'id, comment, closed' : 'id, comment';
    }

    protected function getTeamEventOpenFilterSql($includeClosed = false)
    {
        return ($this->hasTeamEventClosedColumn() && !(bool)$includeClosed)
            ? ' AND (closed IS NULL OR closed = 0)'
            : '';
    }

    protected function hasTeamEventClosedColumn()
    {
        return DbSchema::hasTeamEventClosedColumn($this->getTeamEventDbAdapter());
    }

    /**
     * Optional Spieltag tables (relevance, Zusatzkosten, Gastspenden) may be missing on older installations.
     */
    protected function hasTeamEventTable($table)
    {
        return DbSchema::hasTable($this->getTeamEventDbAdapter(), $table);
    }

    protected function isTeamEventClosedRow($teamEventRow)
    {
        if (!$this->hasTeamEventClosedColumn()) {
            return false;
        }
        if (!is_array($teamEventRow) && !($teamEventRow instanceof \ArrayAccess)) {
            return false;
        }
        return isset($teamEventRow['closed']) && (int)$teamEventRow['closed'] === 1;
    }

    protected function getTeamEventDbAdapter()
    {
        return @$this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
    }

    /**
     * @return \Drinks\Accounting\TeamEventLedger
     */
    protected function getTeamEventLedger()
    {
        return @$this->getServiceLocator()->get('Drinks\Accounting\TeamEventLedger');
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

    /**
     * Returns ['success' => true, 'already_closed' => bool, 'event' => row] or
     * ['success' => false, 'error' => 'invalid_input'|'feature_unavailable'|'not_found'].
     */
    protected function closeTeamEvent($teamAdminUserId, $teamEventId)
    {
        $teamAdminUserId = (int)$teamAdminUserId;
        $teamEventId = (int)$teamEventId;
        if ($teamAdminUserId <= 0 || $teamEventId <= 0) {
            return ['success' => false, 'error' => 'invalid_input'];
        }
        if (!$this->hasTeamEventClosedColumn()) {
            return ['success' => false, 'error' => 'feature_unavailable'];
        }

        $eventRow = $this->getTeamEventById($teamAdminUserId, $teamEventId);
        if (!$eventRow) {
            return ['success' => false, 'error' => 'not_found'];
        }
        if ($this->isTeamEventClosedRow($eventRow)) {
            return ['success' => true, 'already_closed' => true, 'event' => $eventRow];
        }

        $this->getTeamEventDbAdapter()->query(
            'UPDATE drinks_teamevents SET closed = 1 WHERE team_admin_user_id = ? AND id = ?',
            [$teamAdminUserId, $teamEventId]
        );
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
        if ($teamEventId <= 0 || !$this->hasTeamEventTable('drinks_teamevent_order_relevance')) {
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
        if (!$this->hasTeamEventTable('drinks_teamevent_order_relevance')) {
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
        $usedUids = $teamEventId > 0 ? array_flip($this->getTeamEventMemberUserIds($teamEventId)) : [];

        $result = [];
        foreach ($rows as $row) {
            $uid = isset($row['uid']) ? (int)$row['uid'] : 0;
            if ($uid <= 0 || ($excludeUserId > 0 && $uid === $excludeUserId) || isset($usedUids[$uid])) {
                continue;
            }
            $alias = isset($row['alias']) ? trim((string)$row['alias']) : '';
            $result[] = [
                'uid' => $uid,
                'name' => $alias !== '' ? $alias : ('User ' . $uid),
                'email' => isset($row['email']) ? trim((string)$row['email']) : '',
            ];
        }
        return $result;
    }

    /**
     * Members and contributors of a Spieltag (members first, then by name; payers of extra costs
     * last): [['uid', 'name', 'email', 'total_paid', 'total_refunded', 'is_member', 'deposit_comment']].
     * Contributions and refunds come from TeamEventLedger; payers of extra costs (money paid
     * outside the booking system) count as contributors too.
     */
    protected function getTeamEventMembersWithContribution($teamAdminUserId, $teamEventId, $teamEventLabel)
    {
        $teamAdminUserId = (int)$teamAdminUserId;
        $teamEventId = (int)$teamEventId;
        $teamEventLabel = $this->normalizeTeamEventLabel($teamEventLabel);
        if ($teamAdminUserId <= 0 || $teamEventId <= 0 || $teamEventLabel === '') {
            return [];
        }

        $dbAdapter = $this->getTeamEventDbAdapter();
        $memberRows = $dbAdapter->query(
            'SELECT m.user_id AS uid, u.alias, u.email
             FROM drinks_teamevent_members m
             JOIN bs_users u ON u.uid = m.user_id
             WHERE m.team_event_id = ?',
            [$teamEventId]
        )->toArray();
        $memberUids = [];
        $userInfoByUid = [];
        foreach ($memberRows as $row) {
            $memberUids[(int)$row['uid']] = true;
            $userInfoByUid[(int)$row['uid']] = ['alias' => trim((string)$row['alias']), 'email' => trim((string)$row['email'])];
        }

        $contributions = $this->getTeamEventLedger()->contributions($teamAdminUserId, $teamEventId, $teamEventLabel);

        $extraPaidByUid = [];
        foreach ($this->getTeamEventExtraCosts($teamEventId) as $extraCost) {
            $payerUid = isset($extraCost['payer_user_id']) ? (int)$extraCost['payer_user_id'] : 0;
            $amount = isset($extraCost['amount']) ? (float)$extraCost['amount'] : 0.0;
            if ($payerUid > 0 && $amount > 0) {
                $extraPaidByUid[$payerUid] = MoneyCalculator::add(isset($extraPaidByUid[$payerUid]) ? $extraPaidByUid[$payerUid] : 0.0, $amount);
            }
        }

        $allUids = array_values(array_unique(array_merge(array_keys($contributions), array_keys($memberUids))));
        $extraOnlyUids = array_values(array_diff(array_keys($extraPaidByUid), $allUids));

        $missingUids = array_values(array_diff(array_merge($allUids, $extraOnlyUids), array_keys($userInfoByUid)));
        if (!empty($missingUids)) {
            $missingRows = $dbAdapter->query(
                'SELECT uid, alias, email FROM bs_users WHERE uid IN (' . implode(',', array_fill(0, count($missingUids), '?')) . ')',
                $missingUids
            )->toArray();
            foreach ($missingRows as $row) {
                $userInfoByUid[(int)$row['uid']] = ['alias' => trim((string)$row['alias']), 'email' => trim((string)$row['email'])];
            }
        }
        // A payer of extra costs without user row is left out; a contributor is shown as "User <uid>".
        $extraOnlyUids = array_values(array_filter($extraOnlyUids, function ($uid) use ($userInfoByUid) {
            return isset($userInfoByUid[$uid]);
        }));

        usort($allUids, function ($a, $b) use ($memberUids, $userInfoByUid) {
            $am = isset($memberUids[$a]) ? 1 : 0;
            $bm = isset($memberUids[$b]) ? 1 : 0;
            if ($am !== $bm) {
                return $bm <=> $am;
            }
            $aa = isset($userInfoByUid[$a]) ? strtolower($userInfoByUid[$a]['alias']) : '';
            $ba = isset($userInfoByUid[$b]) ? strtolower($userInfoByUid[$b]['alias']) : '';
            return $aa !== $ba ? strcmp($aa, $ba) : ($a <=> $b);
        });

        $result = [];
        foreach (array_merge($allUids, $extraOnlyUids) as $uid) {
            $info = isset($userInfoByUid[$uid]) ? $userInfoByUid[$uid] : ['alias' => '', 'email' => ''];
            $contribution = isset($contributions[$uid]) ? $contributions[$uid] : ['paid' => 0.0, 'refunded' => 0.0, 'comment' => ''];
            $isExtraOnly = !isset($contributions[$uid]) && !isset($memberUids[$uid]);
            $result[] = [
                'uid' => $uid,
                'name' => $info['alias'] !== '' ? $info['alias'] : ('User ' . $uid),
                'email' => $info['email'],
                'total_paid' => MoneyCalculator::add($contribution['paid'], isset($extraPaidByUid[$uid]) ? $extraPaidByUid[$uid] : 0.0),
                'total_refunded' => $contribution['refunded'],
                'is_member' => isset($memberUids[$uid]),
                'deposit_comment' => $isExtraOnly ? 'Extrakosten' : (isset($memberUids[$uid]) ? '' : $contribution['comment']),
            ];
        }
        return $result;
    }

    /**
     * The Spieltag by id (it may belong to another team for member-only views), else by label
     * within the team.
     */
    protected function findTeamEventRow($teamAdminUserId, $teamEventId, $teamEventLabel)
    {
        $row = null;
        if ((int)$teamEventId > 0) {
            $row = $this->getTeamEventDbAdapter()->query(
                'SELECT ' . $this->getTeamEventSelectColumnsSql() . ' FROM drinks_teamevents WHERE id = ? LIMIT 1',
                [(int)$teamEventId]
            )->current();
        }
        return $row ?: $this->getTeamEventByLabel($teamAdminUserId, $teamEventLabel);
    }

    /**
     * Members a cost row is split between: the stored selection as far as it names active
     * members, otherwise all active members. Returns [memberIds, [['uid', 'name'], ...]].
     */
    private function resolveRelevantMembers($storedMemberIds, array $activeMemberNamesById)
    {
        $memberIds = [];
        foreach ((array)$storedMemberIds as $memberId) {
            $memberId = (int)$memberId;
            if ($memberId > 0 && isset($activeMemberNamesById[$memberId])) {
                $memberIds[] = $memberId;
            }
        }
        $memberIds = array_values(array_unique($memberIds));
        if (empty($memberIds)) {
            $memberIds = array_keys($activeMemberNamesById);
        }
        $members = [];
        foreach ($memberIds as $memberId) {
            $members[] = ['uid' => $memberId, 'name' => $activeMemberNamesById[$memberId]];
        }
        return [$memberIds, $members];
    }

    /**
     * Cost rows of a Spieltag. $contributionRows: result of getTeamEventMembersWithContribution()
     * for that Spieltag (the caller needs it too).
     */
    protected function getTeamEventOrderRowsAndTotal($teamAdminUserId, $teamEventLabel, $selectedTeamEventId, array $contributionRows)
    {
        $teamAdminUserId = (int)$teamAdminUserId;
        $teamEventLabel = $this->normalizeTeamEventLabel($teamEventLabel);
        $selectedTeamEventId = (int)$selectedTeamEventId;
        if ($teamAdminUserId <= 0 || $teamEventLabel === '') {
            return ['rows' => [], 'total_sum' => 0.0, 'extra_costs' => [], 'guest_donations' => []];
        }

        $activeMemberNamesById = [];
        foreach ($contributionRows as $memberRow) {
            $memberUid = isset($memberRow['uid']) ? (int)$memberRow['uid'] : 0;
            if (!empty($memberRow['is_member']) && $memberUid > 0) {
                $activeMemberNamesById[$memberUid] = isset($memberRow['name']) ? (string)$memberRow['name'] : ('User ' . $memberUid);
            }
        }
        $storedRelevanceByRowKey = $selectedTeamEventId > 0 ? $this->getTeamEventOrderRelevanceMap($selectedTeamEventId) : [];

        $rows = [];
        foreach ($this->getTeamEventLedger()->orderLines($teamAdminUserId, $selectedTeamEventId, $teamEventLabel) as $line) {
            $totalPrice = 0.0 - $line['total'];
            $rowKey = $line['drink_id'] . '|' . number_format($line['unit_price'], 2, '.', '');
            list(, $rowRelevantMembers) = $this->resolveRelevantMembers(
                isset($storedRelevanceByRowKey[$rowKey]) ? $storedRelevanceByRowKey[$rowKey] : [],
                $activeMemberNamesById
            );
            $rowRelevantMemberCount = count($rowRelevantMembers);
            $rows[] = [
                'row_type' => 'order',
                'drink_id' => $line['drink_id'],
                'unit_price' => $line['unit_price'],
                'category' => $line['category'],
                'article' => $line['article'],
                'quantity' => $line['quantity'],
                'single_price' => 0.0 - $line['unit_price'],
                'total_price' => $totalPrice,
                'relevant_members' => $rowRelevantMembers,
                'relevant_member_count' => $rowRelevantMemberCount,
                'share_per_member' => $rowRelevantMemberCount > 0 ? ($totalPrice / $rowRelevantMemberCount) : 0.0,
            ];
        }

        $extraCosts = [];
        foreach ($selectedTeamEventId > 0 ? $this->getTeamEventExtraCosts($selectedTeamEventId) : [] as $extraCost) {
            $extraCostId = isset($extraCost['id']) ? (int)$extraCost['id'] : 0;
            $amount = isset($extraCost['amount']) ? (float)$extraCost['amount'] : 0.0;
            if ($extraCostId <= 0 || $amount <= 0) {
                continue;
            }
            $signedAmount = MoneyCalculator::roundMoney(0.0 - abs($amount));
            list($selectedRelevantIds, $rowRelevantMembers) = $this->resolveRelevantMembers(
                isset($extraCost['relevant_member_ids']) ? $extraCost['relevant_member_ids'] : [],
                $activeMemberNamesById
            );
            $rowRelevantMemberCount = count($rowRelevantMembers);
            $sharePerMember = $rowRelevantMemberCount > 0 ? ((float)$signedAmount / (float)$rowRelevantMemberCount) : 0.0;
            $comment = isset($extraCost['comment']) ? trim((string)$extraCost['comment']) : '';
            $payerName = isset($extraCost['payer_name']) ? trim((string)$extraCost['payer_name']) : '';
            $articleLabel = 'Extrakosten' . ($comment !== '' ? ': ' . $comment : '') . ($payerName !== '' ? ' (' . $payerName . ')' : '');

            $extraCosts[] = [
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
                'share_per_member' => $sharePerMember,
            ];
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
                'share_per_member' => $sharePerMember,
            ];
        }

        $guestDonations = [];
        foreach ($selectedTeamEventId > 0 ? $this->getTeamEventGuestDonations($selectedTeamEventId) : [] as $guestDonation) {
            $guestDonationId = isset($guestDonation['id']) ? (int)$guestDonation['id'] : 0;
            $amount = isset($guestDonation['amount']) ? (float)$guestDonation['amount'] : 0.0;
            $receiverUserId = isset($guestDonation['receiver_user_id']) ? (int)$guestDonation['receiver_user_id'] : 0;
            if ($guestDonationId <= 0 || $amount <= 0 || !isset($activeMemberNamesById[$receiverUserId])) {
                continue;
            }
            $receiverName = isset($guestDonation['receiver_name']) ? trim((string)$guestDonation['receiver_name']) : '';
            $guestDonations[] = [
                'id' => $guestDonationId,
                'team_event_id' => $selectedTeamEventId,
                'receiver_user_id' => $receiverUserId,
                'receiver_name' => $receiverName !== '' ? $receiverName : $activeMemberNamesById[$receiverUserId],
                'comment' => isset($guestDonation['comment']) ? trim((string)$guestDonation['comment']) : '',
                'amount' => MoneyCalculator::roundMoney(abs($amount)),
                // Receiver has to transfer this amount additionally to the account.
                'due_amount' => MoneyCalculator::roundMoney(0.0 - abs($amount)),
            ];
        }

        $totalSum = 0.0;
        foreach ($rows as $row) {
            $totalSum = MoneyCalculator::add($totalSum, $row['total_price']);
        }

        return [
            'rows' => $rows,
            'total_sum' => $totalSum,
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
            if ($teamEvent && !$this->isTeamEventClosedRow($teamEvent)) {
                $session->current_teamevent_id = (int)$teamEvent['id'];
            } else {
                // Clear a stale or closed session selection before choosing an open event.
                $selectedTeamEventLabel = '';
                $session->current_spieltag = '';
                $session->current_teamevent_id = 0;
            }
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
        } else {
            $newTeamEventLabel = $this->normalizeTeamEventLabel($newTeamEventLabel);
            if ($newTeamEventLabel !== '') {
                return $this->getOrCreateTeamEventByLabel($teamAdminUserId, $newTeamEventLabel);
            }
        }

        // The preferred one is closed or unknown, or none was given: the latest Spieltag if it is open
        $latestEvent = $this->getLatestTeamEventRow($teamAdminUserId);
        if ($latestEvent && !$this->isTeamEventClosedRow($latestEvent)) {
            return $latestEvent;
        }

        return null;
    }

    /**
     * Balance of a Spieltag, see TeamEventLedger::balance().
     */
    protected function calculateTeamEventBalance($teamAdminUserId, $teamEventId, $teamEventLabel)
    {
        $teamEventLabel = $this->normalizeTeamEventLabel($teamEventLabel);
        if ((int)$teamAdminUserId <= 0 || (int)$teamEventId <= 0 || $teamEventLabel === '') {
            return 0.0;
        }
        return $this->getTeamEventLedger()->balance($teamAdminUserId, $teamEventId, $teamEventLabel);
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

        $teamEventLabel = isset($teamEvent['comment']) ? trim((string)$teamEvent['comment']) : '';
        $eventBalance = 0.0;
        if ($teamEventLabel !== '') {
            $eventBalance = (float)$this->calculateTeamEventBalance($teamAdminUserId, $teamEventId, $teamEventLabel);
        }
        $totalRefund = 0.0;
        $totalCharge = 0.0;
        foreach ($validRefunds as $refundRow) {
            $amount = MoneyCalculator::roundMoney((float)$refundRow['amount']);
            if ($amount > 0) {
                $totalRefund = MoneyCalculator::add($totalRefund, $amount);
            } else {
                $totalCharge = MoneyCalculator::add($totalCharge, abs($amount));
            }
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
            ];
        }

        // Settlement always goes through: closed Spieltag, members may drop below the minimum balance
        $settlementOptions = ['allow_closed_event' => true, 'ignore_minimum_balance' => true];
        $transfers = [];
        foreach ($validRefunds as $refundRow) {
            $receiverUserId = (int)$refundRow['receiver_user_id'];
            $amount = MoneyCalculator::roundMoney((float)$refundRow['amount']);
            if ($amount > 0) {
                $transferResult = $this->executeMoneyTransfer($teamAdminUserId, $receiverUserId, $amount, $teamEventId, $settlementOptions);
                $direction = 'team_to_member';
                $effectiveAmount = $amount;
            } else {
                $transferResult = $this->executeMoneyTransfer($receiverUserId, $teamAdminUserId, abs($amount), $teamEventId, $settlementOptions);
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
            'transfers' => $transfers,
        ];
    }

    /**
     * Spieltage of one or more team accounts with their balance, newest first:
     * [['id', 'label', 'balance', 'closed', 'team_admin_user_id'], ...].
     */
    protected function getTeamEventsWithBalances($teamAdminUserIds, $includeClosed = false)
    {
        $teamAdminUserIds = array_values(array_filter(array_map('intval', (array)$teamAdminUserIds)));
        if (empty($teamAdminUserIds)) {
            return [];
        }
        $rows = $this->getTeamEventDbAdapter()->query(
            'SELECT ' . $this->getTeamEventSelectColumnsSql() . ', team_admin_user_id FROM drinks_teamevents'
            . ' WHERE team_admin_user_id IN (' . implode(',', array_fill(0, count($teamAdminUserIds), '?')) . ')'
            . $this->getTeamEventOpenFilterSql($includeClosed) . ' ORDER BY created_at DESC, id DESC',
            $teamAdminUserIds
        )->toArray();

        $result = [];
        foreach ($rows as $row) {
            $teamEventId = (int)$row['id'];
            $teamEventLabel = trim((string)$row['comment']);
            $teamAdminUserId = (int)$row['team_admin_user_id'];
            if ($teamEventId <= 0 || $teamEventLabel === '') {
                continue;
            }

            try {
                $balance = $this->calculateTeamEventBalance($teamAdminUserId, $teamEventId, $teamEventLabel);
            } catch (\Exception $e) {
                $balance = 0.0;
            }

            $result[] = [
                'id' => $teamEventId,
                'label' => $teamEventLabel,
                'balance' => $balance,
                'closed' => $this->isTeamEventClosedRow($row) ? 1 : 0,
                'team_admin_user_id' => $teamAdminUserId,
            ];
        }

        return $result;
    }

    protected function getTeamEventExtraCosts($teamEventId, $includeSoftDeleted = false)
    {
        if (!$this->hasTeamEventTable('drinks_teamevent_extra_costs')) {
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
        if (!$this->hasTeamEventTable('drinks_teamevent_extra_costs') || !$this->hasTeamEventTable('drinks_teamevent_extra_cost_relevance')) {
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
        if (!$this->hasTeamEventTable('drinks_teamevent_extra_costs')) {
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
        if (!$this->hasTeamEventTable('drinks_teamevent_extra_costs')) {
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
        if (!$this->hasTeamEventTable('drinks_teamevent_extra_costs') || !$this->hasTeamEventTable('drinks_teamevent_extra_cost_relevance')) {
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

    protected function deleteTeamEventExtraCost($extraCostId, $softDelete = true, $teamEventId = 0)
    {
        $this->deleteTeamEventEntry('drinks_teamevent_extra_costs', 'Extra cost', $extraCostId, $softDelete, $teamEventId, 'drinks_teamevent_extra_cost_relevance', 'extra_cost_id');
    }

    protected function getTeamEventGuestDonations($teamEventId, $includeSoftDeleted = false)
    {
        if (!$this->hasTeamEventTable('drinks_teamevent_guest_donations')) {
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
        if (!$this->hasTeamEventTable('drinks_teamevent_guest_donations')) {
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
        if (!$this->hasTeamEventTable('drinks_teamevent_guest_donations')) {
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

    protected function deleteTeamEventGuestDonation($guestDonationId, $softDelete = true, $teamEventId = 0)
    {
        $this->deleteTeamEventEntry('drinks_teamevent_guest_donations', 'Guest donation', $guestDonationId, $softDelete, $teamEventId);
    }

    /**
     * Soft or hard delete of a Zusatzkosten / Gastspenden row (hard delete also removes its rows in
     * $childTable). With $teamEventId only a row of that Spieltag: callers have authorised that Spieltag.
     */
    private function deleteTeamEventEntry($table, $what, $entryId, $softDelete, $teamEventId, $childTable = null, $childColumn = null)
    {
        if (!$this->hasTeamEventTable($table)) {
            throw new \Exception($what . ' table not available');
        }
        $entryId = (int)$entryId;
        if ($entryId <= 0) {
            throw new \Exception('Invalid ' . strtolower($what) . ' ID');
        }

        $dbAdapter = $this->getTeamEventDbAdapter();
        if ((int)$teamEventId > 0
            && !$dbAdapter->query('SELECT id FROM ' . $table . ' WHERE id = ? AND team_event_id = ?', [$entryId, (int)$teamEventId])->current()
        ) {
            throw new \Exception($what . ' not found for this team event');
        }
        if ($softDelete) {
            $dbAdapter->query('UPDATE ' . $table . ' SET deleted = 1 WHERE id = ?', [$entryId]);
            return;
        }
        if ($childTable !== null) {
            $dbAdapter->query('DELETE FROM ' . $childTable . ' WHERE ' . $childColumn . ' = ?', [$entryId]);
        }
        $dbAdapter->query('DELETE FROM ' . $table . ' WHERE id = ?', [$entryId]);
    }

    /**
     * Display names (bs_users.alias) of team accounts, keyed by user id. Never expose
     * drink_aliases.alias for a team: that is its Theken-ID, the account's login credential.
     */
    protected function getTeamDisplayNames(array $teamUserIds)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $teamUserIds), function ($id) {
            return $id > 0;
        })));
        $names = [];
        if (empty($ids)) {
            return $names;
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $rows = $this->getTeamEventDbAdapter()->query(
            'SELECT uid, alias FROM bs_users WHERE uid IN (' . $placeholders . ')',
            $ids
        )->toArray();
        foreach ($rows as $row) {
            $name = isset($row['alias']) ? trim((string)$row['alias']) : '';
            if ($name !== '') {
                $names[(int)$row['uid']] = $name;
            }
        }
        foreach ($ids as $id) {
            if (!isset($names[$id])) {
                $names[$id] = 'Mannschaft #' . $id;
            }
        }
        return $names;
    }

    /**
     * Spieltage shown in the Kostenübersicht: all of the teams in $managedTeamUids (editable)
     * plus those $memberUserId takes part in (read-only), newest first.
     */
    protected function getVisibleTeamEvents(array $managedTeamUids, $memberUserId)
    {
        $dbAdapter = $this->getTeamEventDbAdapter();
        $columns = 'te.id, te.comment, te.team_admin_user_id' . ($this->hasTeamEventClosedColumn() ? ', te.closed' : '');
        $managedTeamUids = array_values(array_unique(array_filter(array_map('intval', $managedTeamUids))));

        $rowsById = [];
        $managedEventIds = [];
        if (!empty($managedTeamUids)) {
            $rows = $dbAdapter->query(
                'SELECT ' . $columns . ' FROM drinks_teamevents te WHERE te.team_admin_user_id IN (' . implode(', ', array_fill(0, count($managedTeamUids), '?')) . ')',
                $managedTeamUids
            )->toArray();
            foreach ($rows as $row) {
                $rowsById[(int)$row['id']] = $row;
                $managedEventIds[(int)$row['id']] = true;
            }
        }
        if ((int)$memberUserId > 0) {
            $rows = $dbAdapter->query(
                'SELECT DISTINCT ' . $columns . '
                 FROM drinks_teamevents te
                 JOIN drinks_teamevent_members tm ON tm.team_event_id = te.id
                 WHERE tm.user_id = ?',
                [(int)$memberUserId]
            )->toArray();
            foreach ($rows as $row) {
                if (!isset($rowsById[(int)$row['id']])) {
                    $rowsById[(int)$row['id']] = $row;
                }
            }
        }
        krsort($rowsById);

        $teamNames = $this->getTeamDisplayNames(array_column($rowsById, 'team_admin_user_id'));
        $events = [];
        foreach ($rowsById as $eventId => $row) {
            $label = trim((string)$row['comment']);
            if ($label === '') {
                continue;
            }
            $adminUserId = (int)$row['team_admin_user_id'];
            $canManage = isset($managedEventIds[$eventId]);
            $events[] = [
                'id' => $eventId,
                'label' => $label,
                'team_alias' => isset($teamNames[$adminUserId]) ? $teamNames[$adminUserId] : '',
                'team_admin_user_id' => $adminUserId,
                'can_manage_members' => $canManage ? 1 : 0,
                'role' => $canManage ? 'Mannschaftsführer' : 'Mitglied',
                'closed' => isset($row['closed']) ? (int)$row['closed'] : 0,
            ];
        }
        return $events;
    }

    /**
     * Full Kostenübersicht payload for a list of Spieltage from getVisibleTeamEvents(): selects
     * the requested one (by id, else by label, else the newest) and adds its stats.
     */
    protected function buildTeamStatsModalPayload(array $events, $requestedEventId, $requestedLabel)
    {
        $selected = null;
        foreach ($events as $event) {
            if ((int)$requestedEventId > 0 && $event['id'] === (int)$requestedEventId) {
                $selected = $event;
                break;
            }
        }
        if (!$selected && trim((string)$requestedLabel) !== '') {
            foreach ($events as $event) {
                if ($event['label'] === trim((string)$requestedLabel)) {
                    $selected = $event;
                    break;
                }
            }
        }
        if (!$selected && !empty($events)) {
            $selected = $events[0];
        }

        $openSpieltage = [];
        foreach ($events as $event) {
            if (empty($event['closed'])) {
                $openSpieltage[] = $event['label'];
            }
        }
        $isEditable = in_array(1, array_column($events, 'can_manage_members'), true) ? 1 : 0;
        $payload = [
            'spieltage' => array_column($events, 'label'),
            'open_spieltage' => $openSpieltage,
            'events' => $events,
            'is_editable' => $isEditable,
            'user_role' => $isEditable ? 'Mannschaftsführer' : 'Mitglied',
            'team_alias' => $selected ? $selected['team_alias'] : '',
            'member_candidates' => [],
            'account_balance' => 0.0,
        ];
        if (!$selected) {
            return array_merge($payload, $this->buildTeamStatsPayload(0, ''));
        }

        $teamAdminUserId = $selected['team_admin_user_id'];
        $serviceManager = $this->getServiceLocator();
        $payload['account_balance'] = (float)$serviceManager->get('Drinks\Manager\DrinkManager')->calculateUserDrinkBalance($teamAdminUserId, $serviceManager);
        try {
            $payload['member_candidates'] = $this->getTeamEventMemberCandidates($teamAdminUserId, $selected['id']);
        } catch (\Throwable $e) {
            // Member search stays empty
        }
        $stats = $this->buildTeamStatsPayload($teamAdminUserId, $selected['label'], ['team_event_id' => $selected['id']]);
        $stats['members'] = $stats['active_members'];
        return array_merge($payload, $stats);
    }

    /**
     * Stats of one Spieltag for the Kostenübersicht modal.
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

        $selectedTeamEvent = $this->findTeamEventRow($teamAdminUserId, isset($extra['team_event_id']) ? $extra['team_event_id'] : 0, $teamEventLabel);
        $selectedTeamEventId = $selectedTeamEvent ? (int)$selectedTeamEvent['id'] : 0;

        $contributionRows = $selectedTeamEventId > 0
            ? $this->getTeamEventMembersWithContribution($teamAdminUserId, $selectedTeamEventId, $teamEventLabel)
            : [];
        $stats = $this->getTeamEventOrderRowsAndTotal($teamAdminUserId, $teamEventLabel, $selectedTeamEventId, $contributionRows);

        return array_merge([
            'spieltag' => $teamEventLabel,
            'team_event_id' => $selectedTeamEventId,
            'team_event_closed' => $this->isTeamEventClosedRow($selectedTeamEvent),
            'can_close_team_event' => true,
            'rows' => $stats['rows'],
            'total_sum' => $stats['total_sum'],
            'active_members' => $contributionRows,
            'guest_donations' => $stats['guest_donations'],
            'extra_costs' => $stats['extra_costs'],
            'can_manage_members' => $selectedTeamEventId > 0,
        ], $extra);
    }
}
