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

    public function addDeposit($userId, $amount, $comment = null, $createdByUserId = null)
    {
        $sql = 'INSERT INTO drink_deposits (user_id, amount, comment, createdbyuserid) VALUES (?, ?, ?, ?)';
        $statement = $this->dbAdapter->createStatement($sql, [$userId, $amount, $comment, $createdByUserId]);
        return $statement->execute();
    }
}
