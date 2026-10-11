<?php

namespace Drinks\Accounting;

use Base\Service\MoneyCalculator;
use Zend\Db\Adapter\Adapter;

/**
 * Spieltag money: the one place that computes it. Everything comes from the bookings on the
 * team account marked with the Spieltag (deposits, drink orders, money transfers): the balance,
 * the cost lines and who contributed or got money back. So the Kostenübersicht total
 * (costs + contributions - refunds) equals the Spieltag balance by construction.
 */
class TeamEventLedger
{
    protected $dbAdapter;

    public function __construct(Adapter $dbAdapter)
    {
        $this->dbAdapter = $dbAdapter;
    }

    /**
     * SQL condition for rows of a Spieltag: its teamevent_id, or legacy rows without one whose
     * comment is the Spieltag label. Binds [teamEventId, teamEventLabel].
     */
    public static function rowConditionSql($tableAlias = '')
    {
        $p = $tableAlias !== '' ? $tableAlias . '.' : '';
        return '(' . $p . 'teamevent_id = ? OR ((' . $p . 'teamevent_id IS NULL OR ' . $p . 'teamevent_id = 0)'
            . ' AND TRIM(COALESCE(' . $p . 'comment, "")) = ?))';
    }

    /**
     * Parameters for rowConditionSql(); without a Spieltag id only legacy rows can match.
     */
    public static function rowConditionParams($teamEventId, $teamEventLabel)
    {
        return [(int)$teamEventId > 0 ? (int)$teamEventId : -1, trim((string)$teamEventLabel)];
    }

    /**
     * Balance of a Spieltag: its deposits minus its orders (drinks and money transfers) on the
     * team account.
     */
    public function balance($teamUserId, $teamEventId, $teamEventLabel)
    {
        $params = self::rowConditionParams($teamEventId, $teamEventLabel);
        $row = $this->dbAdapter->query(
            'SELECT
                (SELECT COALESCE(SUM(amount), 0) FROM drink_deposits
                 WHERE user_id = ? AND (deleted IS NULL OR deleted = 0) AND ' . self::rowConditionSql() . ') AS deposit_sum,
                (SELECT COALESCE(SUM(quantity * price), 0) FROM drink_orders
                 WHERE user_id = ? AND (deleted IS NULL OR deleted = 0) AND ' . self::rowConditionSql() . ') AS order_sum',
            array_merge([(int)$teamUserId], $params, [(int)$teamUserId], $params)
        )->current();
        return MoneyCalculator::subtract($row['deposit_sum'], $row['order_sum']);
    }

    /**
     * Drink orders of a Spieltag on the team account, one line per drink and unit price:
     * [['drink_id', 'article', 'category', 'category_sort', 'unit_price', 'quantity', 'total']].
     */
    public function orderLines($teamUserId, $teamEventId, $teamEventLabel)
    {
        $rows = $this->dbAdapter->query(
            'SELECT o.drink_id, d.name AS article, COALESCE(c.name, "") AS category, COALESCE(c.sort_priority, 0) AS category_sort,
                    o.price AS unit_price, SUM(o.quantity) AS quantity, SUM(o.quantity * o.price) AS total
             FROM drink_orders o
             JOIN drinks d ON d.id = o.drink_id
             LEFT JOIN drink_categories c ON c.id = d.category
             WHERE o.user_id = ?
               AND (o.deleted IS NULL OR o.deleted = 0)
               AND o.drink_id <> ?
               AND ' . self::rowConditionSql('o') . '
             GROUP BY o.drink_id, d.name, o.price, c.name, c.sort_priority
             ORDER BY category_sort ASC, category ASC, d.name ASC, o.price ASC',
            array_merge([(int)$teamUserId, AccountLedger::TRANSFER_DRINK_ID], self::rowConditionParams($teamEventId, $teamEventLabel))
        )->toArray();

        $lines = [];
        foreach ($rows as $row) {
            $lines[] = [
                'drink_id' => (int)$row['drink_id'],
                'article' => (string)$row['article'],
                'category' => (string)$row['category'],
                'category_sort' => (int)$row['category_sort'],
                'unit_price' => MoneyCalculator::roundMoney($row['unit_price']),
                'quantity' => (int)$row['quantity'],
                'total' => MoneyCalculator::roundMoney($row['total']),
            ];
        }
        return $lines;
    }

    /**
     * Money that came into and went out of a Spieltag per user, keyed by user id:
     * ['paid', 'refunded', 'comment' => comment of the first deposit].
     * Paid: deposits on the team account, from the sender of a money transfer, otherwise from
     * whoever booked the deposit (the team account itself if unknown).
     * Refunded: money transfers from the team account, to the receiver on the other side.
     */
    public function contributions($teamUserId, $teamEventId, $teamEventLabel)
    {
        $teamUserId = (int)$teamUserId;
        $params = self::rowConditionParams($teamEventId, $teamEventLabel);
        $result = [];
        $add = function ($userId, $key, $amount, $comment = '') use (&$result, $teamUserId) {
            $userId = (int)$userId > 0 ? (int)$userId : $teamUserId;
            if (!isset($result[$userId])) {
                $result[$userId] = ['paid' => 0.0, 'refunded' => 0.0, 'comment' => ''];
            }
            $result[$userId][$key] = MoneyCalculator::add($result[$userId][$key], $amount);
            if ($result[$userId]['comment'] === '') {
                $result[$userId]['comment'] = $comment;
            }
        };

        $depositRows = $this->dbAdapter->query(
            'SELECT d.amount, d.comment, d.createdbyuserid, o.user_id AS sender_user_id
             FROM drink_deposits d
             LEFT JOIN drink_orders o ON o.transfer_reference = d.transfer_reference
                AND o.drink_id = ? AND (o.deleted IS NULL OR o.deleted = 0)
             WHERE d.user_id = ?
               AND (d.deleted IS NULL OR d.deleted = 0)
               AND ' . self::rowConditionSql('d') . '
             ORDER BY d.deposit_time ASC, d.id ASC',
            array_merge([AccountLedger::TRANSFER_DRINK_ID, $teamUserId], $params)
        )->toArray();
        foreach ($depositRows as $row) {
            $payer = $row['sender_user_id'] !== null ? $row['sender_user_id'] : $row['createdbyuserid'];
            $add($payer, 'paid', $row['amount'], trim((string)$row['comment']));
        }

        $refundRows = $this->dbAdapter->query(
            'SELECT o.quantity * o.price AS amount, d.user_id AS receiver_user_id
             FROM drink_orders o
             LEFT JOIN drink_deposits d ON d.transfer_reference = o.transfer_reference
                AND (d.deleted IS NULL OR d.deleted = 0)
             WHERE o.user_id = ?
               AND o.drink_id = ?
               AND (o.deleted IS NULL OR o.deleted = 0)
               AND ' . self::rowConditionSql('o'),
            array_merge([$teamUserId, AccountLedger::TRANSFER_DRINK_ID], $params)
        )->toArray();
        foreach ($refundRows as $row) {
            $add($row['receiver_user_id'], 'refunded', $row['amount']);
        }

        return $result;
    }
}
