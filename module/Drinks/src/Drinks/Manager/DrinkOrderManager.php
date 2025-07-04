<?php

namespace Drinks\Manager;

use RuntimeException;
use Zend\Db\Adapter\Adapter;

class DrinkOrderManager
{
    const CANCEL_WINDOW_SECONDS = 600; // 10 minutes
    protected $dbAdapter;

    public function __construct(Adapter $dbAdapter)
    {
        $this->dbAdapter = $dbAdapter;
    }

    public function getByUser($userId)
    {
        $sql = 'SELECT do.*, d.name FROM drink_orders do JOIN drinks d ON do.drink_id = d.id WHERE do.user_id = ? ORDER BY do.order_time DESC';
        $statement = $this->dbAdapter->createStatement($sql, [$userId]);
        return $statement->execute();
    }

    public function addOrder($userId, $drinkId, $quantity)
    {
        // Fetch the current price from the drinks table
        $sql = 'SELECT price FROM drinks WHERE id = ?';
        $statement = $this->dbAdapter->createStatement($sql, [$drinkId]);
        $result = $statement->execute();
        $row = $result->current();
        if (!$row) {
            throw new \RuntimeException('Drink not found');
        }
        $price = $row['price'];
        // Insert order with price at time of order
        $sql = 'INSERT INTO drink_orders (user_id, drink_id, quantity, price) VALUES (?, ?, ?, ?)';
        $statement = $this->dbAdapter->createStatement($sql, [$userId, $drinkId, $quantity, $price]);
        return $statement->execute();
    }

    public function dropOrder($orderId, $userId = null)
    {
        // Fetch order time and deleted status
        if ($userId) {
            $sql = 'SELECT order_time, deleted FROM drink_orders WHERE id = ? AND user_id = ?';
            $params = [$orderId, $userId];
        } else {
            $sql = 'SELECT order_time, deleted FROM drink_orders WHERE id = ?';
            $params = [$orderId];
        }
        $statement = $this->dbAdapter->createStatement($sql, $params);
        $result = $statement->execute();
        $row = $result->current();
        if (!$row) {
            throw new \RuntimeException('Order not found');
        }
        if (!empty($row['deleted'])) {
            throw new \RuntimeException('Order already deleted');
        }
        $orderTime = strtotime($row['order_time']);
        $now = time();
        if ($now - $orderTime > self::CANCEL_WINDOW_SECONDS) {
            throw new \RuntimeException('Order can only be deleted within 10 minutes');
        }
        // Soft-delete the order
        if ($userId) {
            $sql = 'UPDATE drink_orders SET deleted = 1 WHERE id = ? AND user_id = ?';
            $params = [$orderId, $userId];
        } else {
            $sql = 'UPDATE drink_orders SET deleted = 1 WHERE id = ?';
            $params = [$orderId];
        }
        $statement = $this->dbAdapter->createStatement($sql, $params);
        return $statement->execute();
    }

    /**
     * Get statistics: total count of each drink consumed by a user
     */
    public function getDrinkStatsByUser($userId)
    {
        $sql = 'SELECT d.id, d.name, SUM(do.quantity) AS total_count
                FROM drink_orders do
                JOIN drinks d ON do.drink_id = d.id
                WHERE do.user_id = ? AND do.deleted = 0
                GROUP BY d.id, d.name
                ORDER BY total_count DESC, d.name ASC';
        $statement = $this->dbAdapter->createStatement($sql, [$userId]);
        return $statement->execute();
    }
}
