<?php

namespace Drinks\Accounting;

use Base\Service\MoneyCalculator;
use Zend\Db\Adapter\Adapter;

/**
 * Drink account money: the one place that computes balances and order totals.
 * A balance is the sum of all deposits minus the sum of all orders (quantity x price), both
 * without cancelled rows. MySQL sums the DECIMAL columns exactly; results are rounded to cents.
 */
class AccountLedger
{
    const TRANSFER_DRINK_ID = -1;

    protected $dbAdapter;

    public function __construct(Adapter $dbAdapter)
    {
        $this->dbAdapter = $dbAdapter;
    }

    /**
     * Total of one order line: quantity x unit price, in euros rounded to cents.
     */
    public static function lineTotal($quantity, $price)
    {
        return MoneyCalculator::multiply($price, $quantity);
    }

    /**
     * Current balance of one account.
     */
    public function balance($userId)
    {
        $row = $this->dbAdapter->query(
            'SELECT
                (SELECT COALESCE(SUM(amount), 0) FROM drink_deposits
                 WHERE user_id = ? AND (deleted IS NULL OR deleted = 0)) AS deposit_sum,
                (SELECT COALESCE(SUM(quantity * price), 0) FROM drink_orders
                 WHERE user_id = ? AND (deleted IS NULL OR deleted = 0)) AS order_sum',
            [(int)$userId, (int)$userId]
        )->current();
        return MoneyCalculator::subtract($row['deposit_sum'], $row['order_sum']);
    }

    /**
     * PayPal payments assigned to the account but not yet credited as deposit.
     */
    public function pendingPaypal($userId)
    {
        try {
            $row = $this->dbAdapter->query(
                'SELECT COALESCE(SUM(amount), 0) AS total FROM drinks_paypal WHERE linked_user_id = ? AND linked_deposit_id IS NULL AND state != ?',
                [(int)$userId, 'ignored']
            )->current();
            return MoneyCalculator::roundMoney($row ? $row['total'] : 0);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    /**
     * How much the account may still order: balance plus pending PayPal payments down to the
     * minimum balance. Below zero no order is possible.
     */
    public function orderLimit($userId, $minimumBalance)
    {
        return MoneyCalculator::subtract(
            MoneyCalculator::add($this->balance($userId), $this->pendingPaypal($userId)),
            $minimumBalance
        );
    }

    /**
     * Whether an order of $orderTotal fits into $orderLimit (null = no limit).
     */
    public static function fitsOrderLimit($orderTotal, $orderLimit)
    {
        return $orderLimit === null || MoneyCalculator::toCents($orderTotal) <= MoneyCalculator::toCents($orderLimit);
    }

    /**
     * Deposits and orders of one account, oldest first, each with the balance after it.
     * Cancelled orders are included (deleted = 1, the balance does not change), cancelled
     * deposits only with 'cancelled_deposits' => true.
     * Options: 'cancelled_deposits' (bool); 'team_event_label' with optional 'team_event_id'
     * keeps only the entries of that Spieltag (by id, or legacy rows without id whose comment
     * is the label) and makes the balance the balance of these entries.
     * Entry keys: type ('deposit' | 'order'), id, datetime, deleted, amount (change of the
     * balance: + deposit, - order), total (positive: deposit amount or order line total),
     * balance_after, comment, teamevent_id, transfer_reference; deposits also createdbyuserid,
     * orders also drink_id, name, quantity, price.
     * Returns ['entries' => [...], 'balance' => float].
     */
    public function history($userId, array $options = [])
    {
        $filterSql = '';
        $filterParams = [];
        $teamEventLabel = isset($options['team_event_label']) ? trim((string)$options['team_event_label']) : '';
        if ($teamEventLabel !== '') {
            $teamEventId = isset($options['team_event_id']) ? (int)$options['team_event_id'] : 0;
            $filterSql = ' AND (%1$steamevent_id = ? OR ((%1$steamevent_id IS NULL OR %1$steamevent_id = 0) AND TRIM(COALESCE(%1$scomment, "")) = ?))';
            $filterParams = [$teamEventId > 0 ? $teamEventId : -1, $teamEventLabel];
        }

        $depositSql = 'SELECT * FROM drink_deposits WHERE user_id = ?';
        if (empty($options['cancelled_deposits'])) {
            $depositSql .= ' AND (deleted IS NULL OR deleted = 0)';
        }
        $depositRows = $this->dbAdapter->query(
            $depositSql . sprintf($filterSql, ''),
            array_merge([(int)$userId], $filterParams)
        )->toArray();
        $orderRows = $this->dbAdapter->query(
            'SELECT do.*, d.name FROM drink_orders do JOIN drinks d ON do.drink_id = d.id WHERE do.user_id = ?'
                . sprintf($filterSql, 'do.'),
            array_merge([(int)$userId], $filterParams)
        )->toArray();

        $entries = [];
        foreach ($depositRows as $row) {
            $amount = MoneyCalculator::roundMoney($row['amount']);
            $entries[] = [
                'type' => 'deposit',
                'id' => (int)$row['id'],
                'datetime' => (string)$row['deposit_time'],
                'deleted' => empty($row['deleted']) ? 0 : 1,
                'amount' => $amount,
                'total' => $amount,
                'comment' => isset($row['comment']) ? trim((string)$row['comment']) : '',
                'teamevent_id' => isset($row['teamevent_id']) ? (int)$row['teamevent_id'] : 0,
                'transfer_reference' => isset($row['transfer_reference']) ? (string)$row['transfer_reference'] : '',
                'createdbyuserid' => isset($row['createdbyuserid']) ? (int)$row['createdbyuserid'] : 0,
            ];
        }
        foreach ($orderRows as $row) {
            $total = self::lineTotal($row['quantity'], $row['price']);
            $entries[] = [
                'type' => 'order',
                'id' => (int)$row['id'],
                'datetime' => (string)$row['order_time'],
                'deleted' => empty($row['deleted']) ? 0 : 1,
                'amount' => 0.0 - $total,
                'total' => $total,
                'comment' => isset($row['comment']) ? trim((string)$row['comment']) : '',
                'teamevent_id' => isset($row['teamevent_id']) ? (int)$row['teamevent_id'] : 0,
                'transfer_reference' => isset($row['transfer_reference']) ? (string)$row['transfer_reference'] : '',
                'drink_id' => (int)$row['drink_id'],
                'name' => (string)$row['name'],
                'quantity' => (int)$row['quantity'],
                'price' => MoneyCalculator::roundMoney($row['price']),
            ];
        }
        usort($entries, function ($a, $b) {
            return strcmp($a['datetime'], $b['datetime']) ?: strcmp($a['type'], $b['type']) ?: ($a['id'] <=> $b['id']);
        });

        $balanceCents = 0;
        foreach ($entries as $index => $entry) {
            if (!$entry['deleted']) {
                $balanceCents += MoneyCalculator::toCents($entry['amount']);
            }
            $entries[$index]['balance_after'] = MoneyCalculator::fromCents($balanceCents);
        }
        return ['entries' => $entries, 'balance' => MoneyCalculator::fromCents($balanceCents)];
    }

    /**
     * History entries (oldest first, as history() returns them, extra keys are kept) grouped by
     * day, oldest first: [['date', 'entries', 'total' => sum of the day, 'balance' => at day end]].
     */
    public static function dayGroups(array $entries)
    {
        $days = [];
        foreach ($entries as $entry) {
            $date = substr($entry['datetime'], 0, 10);
            if (!isset($days[$date])) {
                $days[$date] = ['date' => $date, 'entries' => [], 'total' => 0.0, 'balance' => 0.0];
            }
            $days[$date]['entries'][] = $entry;
            if (!$entry['deleted']) {
                $days[$date]['total'] = MoneyCalculator::add($days[$date]['total'], $entry['amount']);
            }
            $days[$date]['balance'] = $entry['balance_after'];
        }
        return array_values($days);
    }

    /**
     * History entries (oldest first) as shown on the drinks page, newest first: a group per day
     * of orders ['type' => 'day', 'date', 'orders' (newest first), 'quantity', 'total',
     * 'balance'] and each deposit ['type' => 'deposit', 'entry', 'balance']. A day sits at the
     * time of its last order; balances are those after its last order or after the deposit.
     * $withDeposits false leaves out deposits; $ordersSince (timestamp) leaves out older orders.
     */
    public static function purchaseGroups(array $entries, $withDeposits = true, $ordersSince = null)
    {
        $groups = [];
        $days = [];
        foreach ($entries as $entry) {
            if ($entry['type'] === 'deposit') {
                if ($withDeposits && !$entry['deleted']) {
                    $groups[] = ['type' => 'deposit', 'datetime' => $entry['datetime'], 'entry' => $entry, 'balance' => $entry['balance_after']];
                }
                continue;
            }
            if ($ordersSince !== null && strtotime($entry['datetime']) < $ordersSince) {
                continue;
            }
            $date = substr($entry['datetime'], 0, 10);
            if (!isset($days[$date])) {
                $days[$date] = ['type' => 'day', 'date' => $date, 'datetime' => '', 'orders' => [], 'quantity' => 0, 'total' => 0.0, 'balance' => 0.0];
            }
            array_unshift($days[$date]['orders'], $entry);
            if (!$entry['deleted']) {
                $days[$date]['quantity'] += (int)$entry['quantity'];
                $days[$date]['total'] = MoneyCalculator::add($days[$date]['total'], $entry['total']);
            }
            $days[$date]['datetime'] = $entry['datetime'];
            $days[$date]['balance'] = $entry['balance_after'];
        }
        $groups = array_merge($groups, array_values($days));
        usort($groups, function ($a, $b) {
            return strcmp($b['datetime'], $a['datetime']);
        });
        return $groups;
    }

    /**
     * All accounts with at least one deposit or order (cancelled ones included), keyed by
     * user id: ['balance', 'orders_total', 'last_deposit', 'last_order'].
     * orders_total and last_order leave out money transfers; last_* are null without entries.
     */
    public function accountSummaries()
    {
        $summaries = [];
        $entry = function ($userId) use (&$summaries) {
            if (!isset($summaries[$userId])) {
                $summaries[$userId] = [
                    'deposit_sum' => 0.0,
                    'order_sum' => 0.0,
                    'orders_total' => 0.0,
                    'last_deposit' => null,
                    'last_order' => null,
                ];
            }
        };

        $depositRows = $this->dbAdapter->query(
            'SELECT user_id,
                COALESCE(SUM(CASE WHEN deleted IS NULL OR deleted = 0 THEN amount END), 0) AS deposit_sum,
                MAX(CASE WHEN deleted IS NULL OR deleted = 0 THEN deposit_time END) AS last_deposit
             FROM drink_deposits
             GROUP BY user_id',
            []
        )->toArray();
        foreach ($depositRows as $row) {
            $userId = (int)$row['user_id'];
            $entry($userId);
            $summaries[$userId]['deposit_sum'] = (float)$row['deposit_sum'];
            $summaries[$userId]['last_deposit'] = $row['last_deposit'];
        }

        $orderRows = $this->dbAdapter->query(
            'SELECT user_id,
                COALESCE(SUM(CASE WHEN deleted IS NULL OR deleted = 0 THEN quantity * price END), 0) AS order_sum,
                COALESCE(SUM(CASE WHEN (deleted IS NULL OR deleted = 0) AND drink_id <> ? THEN quantity * price END), 0) AS orders_total,
                MAX(CASE WHEN (deleted IS NULL OR deleted = 0) AND drink_id <> ? THEN order_time END) AS last_order
             FROM drink_orders
             GROUP BY user_id',
            [self::TRANSFER_DRINK_ID, self::TRANSFER_DRINK_ID]
        )->toArray();
        foreach ($orderRows as $row) {
            $userId = (int)$row['user_id'];
            $entry($userId);
            $summaries[$userId]['order_sum'] = (float)$row['order_sum'];
            $summaries[$userId]['orders_total'] = MoneyCalculator::roundMoney($row['orders_total']);
            $summaries[$userId]['last_order'] = $row['last_order'];
        }

        foreach ($summaries as $userId => $summary) {
            $summaries[$userId] = [
                'balance' => MoneyCalculator::subtract($summary['deposit_sum'], $summary['order_sum']),
                'orders_total' => $summary['orders_total'],
                'last_deposit' => $summary['last_deposit'],
                'last_order' => $summary['last_order'],
            ];
        }
        return $summaries;
    }
}
