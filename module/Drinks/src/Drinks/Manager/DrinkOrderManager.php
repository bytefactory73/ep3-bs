<?php
namespace Drinks\Manager;

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

    public function addOrder($userId, $drinkId, $quantity, $addedByUserId = null, $isAutoOrder = 0, $comment = null, $customPrice = null)
    {
        if ($drinkId == 1) {
            // For "Sonstiges" (custom entry), use the provided price, allow 0 as valid
            $price = ($customPrice !== null) ? (float)$customPrice : 0.0;
        } else {
            $sql = 'SELECT price FROM drinks WHERE id = ?';
            $statement = $this->dbAdapter->createStatement($sql, [$drinkId]);
            $row = $statement->execute()->current();
            if (!$row) {
                throw new \RuntimeException('Drink not found');
            }
            $price = (float)$row['price'];
        }
        if ($addedByUserId === null) {
            // Try to get current user from session if not provided
            if (isset($_SESSION['user_id'])) {
                $addedByUserId = $_SESSION['user_id'];
            }
        }
        if ($addedByUserId !== null) {
            $sql = 'INSERT INTO drink_orders (user_id, drink_id, quantity, price, comment, user_id_added, is_auto_order) VALUES (?, ?, ?, ?, ?, ?, ?)';
            $params = [$userId, $drinkId, $quantity, $price, $comment, $addedByUserId, $isAutoOrder];
        } else {
            $sql = 'INSERT INTO drink_orders (user_id, drink_id, quantity, price, comment, is_auto_order) VALUES (?, ?, ?, ?, ?, ?)';
            $params = [$userId, $drinkId, $quantity, $price, $comment, $isAutoOrder];
        }
        $statement = $this->dbAdapter->createStatement($sql, $params);
        return $statement->execute();
    }

    public function dropOrder($orderId, $userId = null, $deletedByUserId = null)
    {
        if ($userId) {
            $sql = 'SELECT order_time, deleted FROM drink_orders WHERE id = ? AND user_id = ?';
            $params = [$orderId, $userId];
        } else {
            $sql = 'SELECT order_time, deleted FROM drink_orders WHERE id = ?';
            $params = [$orderId];
        }
        $statement = $this->dbAdapter->createStatement($sql, $params);
        $row = $statement->execute()->current();
        if (!$row) {
            throw new \RuntimeException('Order not found');
        }
        if (!empty($row['deleted'])) {
            throw new \RuntimeException('Order already deleted');
        }
        $orderTime = strtotime($row['order_time']);
        if (time() - $orderTime > self::CANCEL_WINDOW_SECONDS) {
            throw new \RuntimeException('Order can only be deleted within 10 minutes');
        }
        // Set deleted=1 and user_id_deleted
        if ($deletedByUserId === null) {
            if (isset($_SESSION['user_id'])) {
                $deletedByUserId = $_SESSION['user_id'];
            } else if ($userId !== null) {
                $deletedByUserId = $userId;
            } else {
                $deletedByUserId = null;
            }
        }
        if ($userId) {
            $sql = 'UPDATE drink_orders SET deleted = 1, user_id_deleted = ? WHERE id = ? AND user_id = ?';
            $params = [$deletedByUserId, $orderId, $userId];
        } else {
            $sql = 'UPDATE drink_orders SET deleted = 1, user_id_deleted = ? WHERE id = ?';
            $params = [$deletedByUserId, $orderId];
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
