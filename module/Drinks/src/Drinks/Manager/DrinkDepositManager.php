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

    public function getByUser($userId, $includeDeleted = false)
    {
        $sql = 'SELECT * FROM drink_deposits WHERE user_id = ?';
        $params = [$userId];
        if (!$includeDeleted) {
            // deleted is optional/nullable, treat NULL or 0 as not deleted
            $sql .= ' AND (deleted IS NULL OR deleted = 0)';
        }
        $sql .= ' ORDER BY deposit_time DESC';
        $statement = $this->dbAdapter->createStatement($sql, $params);
        return $statement->execute();
    }

    public function addDeposit($userId, $amount, $comment = null, $createdByUserId = null, $userIdDeleted = null)
    {
        // Both deleted and user_id_deleted are optional/nullable
        if ($userIdDeleted === null) {
            $sql = 'INSERT INTO drink_deposits (user_id, amount, comment, createdbyuserid) VALUES (?, ?, ?, ?)';
            $params = [$userId, $amount, $comment, $createdByUserId];
        } else {
            $sql = 'INSERT INTO drink_deposits (user_id, amount, comment, createdbyuserid, user_id_deleted) VALUES (?, ?, ?, ?, ?)';
            $params = [$userId, $amount, $comment, $createdByUserId, $userIdDeleted];
        }
        $statement = $this->dbAdapter->createStatement($sql, $params);
        return $statement->execute();
    }
}
