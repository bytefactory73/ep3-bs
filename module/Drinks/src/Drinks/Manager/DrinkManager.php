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

    public function getAll()
    {
        $sql = 'SELECT d.* FROM drinks d '
            . 'LEFT JOIN drink_categories c ON d.category = c.id '
            . 'ORDER BY c.sort_priority ASC, c.name ASC, d.name ASC';
        $statement = $this->dbAdapter->query($sql);
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
