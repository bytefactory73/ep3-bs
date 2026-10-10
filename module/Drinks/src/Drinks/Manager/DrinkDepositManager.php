<?php
namespace Drinks\Manager;

use Drinks\Service\DbSchema;
use Zend\Db\Adapter\Adapter;

class DrinkDepositManager
{
    protected $dbAdapter;

    public function __construct(Adapter $dbAdapter)
    {
        $this->dbAdapter = $dbAdapter;
        DbSchema::ensureUtf8mb4($dbAdapter);
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

    public function addDeposit($userId, $amount, $comment = null, $createdByUserId = null, $userIdDeleted = null, $teamEventId = null, $depositTime = null)
    {
        $columns = ['user_id', 'amount', 'comment', 'teamevent_id', 'createdbyuserid'];
        $params = [$userId, $amount, $comment, $teamEventId, $createdByUserId];
        // user_id_deleted and deposit_time keep their column defaults unless given
        if ($userIdDeleted !== null) {
            $columns[] = 'user_id_deleted';
            $params[] = $userIdDeleted;
        }
        if ($depositTime !== null) {
            $columns[] = 'deposit_time';
            $params[] = $depositTime;
        }
        $sql = 'INSERT INTO drink_deposits (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $statement = $this->dbAdapter->createStatement($sql, $params);
        return $statement->execute();
    }
}
