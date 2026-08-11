<?php
namespace Drinks\Manager;

use RuntimeException;
use Zend\Db\Adapter\Adapter;

class DrinkDepositManager
{
    protected $dbAdapter;

    /** @var bool */
    private $connectionUtf8mb4Initialized = false;

    public function __construct(Adapter $dbAdapter)
    {
        $this->dbAdapter = $dbAdapter;
        $this->ensureUtf8mb4Connection();
    }

    private function ensureUtf8mb4Connection()
    {
        if ($this->connectionUtf8mb4Initialized) {
            return;
        }

        try {
            $this->dbAdapter->query('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci', []);
            $this->connectionUtf8mb4Initialized = true;
        } catch (\Throwable $e) {
            $this->connectionUtf8mb4Initialized = false;
        }
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
        // Both deleted and user_id_deleted are optional/nullable
        if ($userIdDeleted === null) {
            if ($depositTime !== null) {
                $sql = 'INSERT INTO drink_deposits (user_id, amount, comment, teamevent_id, createdbyuserid, deposit_time) VALUES (?, ?, ?, ?, ?, ?)';
                $params = [$userId, $amount, $comment, $teamEventId, $createdByUserId, $depositTime];
            } else {
                $sql = 'INSERT INTO drink_deposits (user_id, amount, comment, teamevent_id, createdbyuserid) VALUES (?, ?, ?, ?, ?)';
                $params = [$userId, $amount, $comment, $teamEventId, $createdByUserId];
            }
        } else {
            if ($depositTime !== null) {
                $sql = 'INSERT INTO drink_deposits (user_id, amount, comment, teamevent_id, createdbyuserid, user_id_deleted, deposit_time) VALUES (?, ?, ?, ?, ?, ?, ?)';
                $params = [$userId, $amount, $comment, $teamEventId, $createdByUserId, $userIdDeleted, $depositTime];
            } else {
                $sql = 'INSERT INTO drink_deposits (user_id, amount, comment, teamevent_id, createdbyuserid, user_id_deleted) VALUES (?, ?, ?, ?, ?, ?)';
                $params = [$userId, $amount, $comment, $teamEventId, $createdByUserId, $userIdDeleted];
            }
        }
        $statement = $this->dbAdapter->createStatement($sql, $params);
        return $statement->execute();
    }
}
