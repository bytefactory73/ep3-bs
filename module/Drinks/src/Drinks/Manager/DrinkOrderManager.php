<?php

namespace Drinks\Manager;

use RuntimeException;
use Zend\Db\Adapter\Adapter;

class DrinkOrderManager
{
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

    /**
     * Get statistics: total count of each drink consumed by a user
     */
    public function getDrinkStatsByUser($userId)
    {
        $sql = 'SELECT d.name, SUM(do.quantity) AS total_count
                FROM drink_orders do
                JOIN drinks d ON do.drink_id = d.id
                WHERE do.user_id = ?
                GROUP BY d.name
                ORDER BY d.name ASC';
        $statement = $this->dbAdapter->createStatement($sql, [$userId]);
        return $statement->execute();
    }
}
