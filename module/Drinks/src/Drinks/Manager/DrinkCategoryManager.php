<?php
namespace Drinks\Manager;

use Zend\Db\Adapter\Adapter;

class DrinkCategoryManager
{
    protected $dbAdapter;

    public function __construct(Adapter $dbAdapter)
    {
        $this->dbAdapter = $dbAdapter;
    }

    public function getAll()
    {
        $sql = 'SELECT * FROM drink_categories ORDER BY sort_priority, name';
        $statement = $this->dbAdapter->query($sql);
        return iterator_to_array($statement->execute());
    }
}
