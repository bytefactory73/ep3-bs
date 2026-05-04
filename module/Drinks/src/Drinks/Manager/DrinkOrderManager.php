<?php
namespace Drinks\Manager;

use Zend\Db\Adapter\Adapter;

class DrinkOrderManager
{
    const CANCEL_WINDOW_SECONDS = 600; // 10 minutes
    protected $dbAdapter;
    protected $hasTransferReferenceColumns = null;

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

    public function addOrder($userId, $drinkId, $quantity, $addedByUserId = null, $isAutoOrder = 0, $comment = null, $customPrice = null, $teamEventId = null)
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
            $sql = 'INSERT INTO drink_orders (user_id, drink_id, quantity, price, comment, teamevent_id, user_id_added, is_auto_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
            $params = [$userId, $drinkId, $quantity, $price, $comment, $teamEventId, $addedByUserId, $isAutoOrder];
        } else {
            $sql = 'INSERT INTO drink_orders (user_id, drink_id, quantity, price, comment, teamevent_id, is_auto_order) VALUES (?, ?, ?, ?, ?, ?, ?)';
            $params = [$userId, $drinkId, $quantity, $price, $comment, $teamEventId, $isAutoOrder];
        }
        $statement = $this->dbAdapter->createStatement($sql, $params);
        return $statement->execute();
    }

    protected function canUseTransferReferenceColumns()
    {
        if ($this->hasTransferReferenceColumns !== null) {
            return $this->hasTransferReferenceColumns;
        }

        try {
            $orderCol = $this->dbAdapter->query("SHOW COLUMNS FROM drink_orders LIKE 'transfer_reference'", [])->current();
            $depositCol = $this->dbAdapter->query("SHOW COLUMNS FROM drink_deposits LIKE 'transfer_reference'", [])->current();
            $this->hasTransferReferenceColumns = (bool)$orderCol && (bool)$depositCol;
        } catch (\Exception $e) {
            $this->hasTransferReferenceColumns = false;
        }

        return $this->hasTransferReferenceColumns;
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
        $result = $statement->execute();

        // Keep transfer counterpart (deposit) in sync when a transfer order is cancelled.
        if ($result->getAffectedRows() > 0 && $this->canUseTransferReferenceColumns()) {
            try {
                $transferRow = $this->dbAdapter->query('SELECT transfer_reference FROM drink_orders WHERE id = ?', [$orderId])->current();
                $transferReference = $transferRow && isset($transferRow['transfer_reference'])
                    ? trim((string)$transferRow['transfer_reference'])
                    : '';
                if ($transferReference !== '') {
                    $this->dbAdapter->query(
                        'UPDATE drink_deposits SET deleted = 1, user_id_deleted = ? WHERE transfer_reference = ?',
                        [$deletedByUserId, $transferReference]
                    );
                }
            } catch (\Exception $e) {
                // Do not fail primary order cancellation if counterpart sync fails.
            }
        }

        return $result;
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
