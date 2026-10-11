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
