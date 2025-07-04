<?php

namespace Drinks\Manager;

use RuntimeException;
use Zend\Db\Adapter\Adapter;

class DrinkManager
{
    protected $dbAdapter;

    public function __construct(Adapter $dbAdapter)
    {
        $this->dbAdapter = $dbAdapter;
    }

    public function getAll($userId = null)
    {
        if ($userId) {
            $sql = 'SELECT d.*, COALESCE(SUM(do.quantity), 0) AS user_total_count '
                . 'FROM drinks d '
                . 'LEFT JOIN drink_orders do ON d.id = do.drink_id AND do.user_id = ? '
                . 'LEFT JOIN drink_categories c ON d.category = c.id '
                . 'GROUP BY d.id '
                . 'ORDER BY c.sort_priority ASC, c.name ASC, d.name ASC';
            $statement = $this->dbAdapter->createStatement($sql, [$userId]);
        } else {
            $sql = 'SELECT d.*, 0 AS user_total_count FROM drinks d '
                . 'LEFT JOIN drink_categories c ON d.category = c.id '
                . 'ORDER BY c.sort_priority ASC, c.name ASC, d.name ASC';
            $statement = $this->dbAdapter->query($sql);
        }
        return $statement->execute();
    }

    public function get($id)
    {
        $sql = 'SELECT * FROM drinks WHERE id = ?';
        $statement = $this->dbAdapter->createStatement($sql, [$id]);
        $result = $statement->execute();
        return $result->current();
    }
}
