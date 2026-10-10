<?php
namespace Drinks\Manager;

use Drinks\Service\DbSchema;
use Zend\Db\Adapter\Adapter;

class DrinkOrderManager
{
    const CANCEL_WINDOW_SECONDS = 600; // 10 minutes
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

    public function addOrder($userId, $drinkId, $quantity, $addedByUserId = null, $isAutoOrder = 0, $comment = null, $customPrice = null, $teamEventId = null)
    {
        if (DrinkManager::isCustomPriceDrink($drinkId)) {
            // For "Sonstiges" (1) and money transfer (-1), use provided custom price.
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
        $columns = ['user_id', 'drink_id', 'quantity', 'price', 'comment', 'teamevent_id', 'is_auto_order'];
        $params = [$userId, $drinkId, $quantity, $price, $comment, $teamEventId, $isAutoOrder];
        if ($addedByUserId !== null) {
            $columns[] = 'user_id_added';
            $params[] = $addedByUserId;
        }
        $sql = 'INSERT INTO drink_orders (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $statement = $this->dbAdapter->createStatement($sql, $params);
        return $statement->execute();
    }

    /**
     * Map a dropOrder() RuntimeException to [HTTP status, user message].
     */
    public static function describeDropOrderError(\RuntimeException $e)
    {
        switch ($e->getMessage()) {
            case 'Order can only be deleted within 10 minutes':
                return [409, 'Stornieren ist nur innerhalb von 10 Minuten nach der Bestellung möglich.'];
            case 'Order already deleted':
                return [409, 'Diese Bestellung wurde bereits storniert.'];
            case 'Order not found':
                return [404, 'Bestellung nicht gefunden.'];
            default:
                return [400, 'Stornierung nicht möglich.'];
        }
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
        if ($result->getAffectedRows() > 0 && DbSchema::hasTransferReferenceColumns($this->dbAdapter)) {
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
