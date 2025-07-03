<?php
namespace Drinks\Manager;

use RuntimeException;
use Zend\Db\Adapter\Adapter;

class DrinkDepositManager
{
    protected $dbAdapter;

    public function __construct(Adapter $dbAdapter)
    {
        $this->dbAdapter = $dbAdapter;
    }

    public function getByUser($userId)
    {
        $sql = 'SELECT * FROM drink_deposits WHERE user_id = ? ORDER BY deposit_time DESC';
        $statement = $this->dbAdapter->createStatement($sql, [$userId]);
        return $statement->execute();
    }

    public function addDeposit($userId, $amount)
    {
        $sql = 'INSERT INTO drink_deposits (user_id, amount) VALUES (?, ?)';
        $statement = $this->dbAdapter->createStatement($sql, [$userId, $amount]);
        return $statement->execute();
    }
}
