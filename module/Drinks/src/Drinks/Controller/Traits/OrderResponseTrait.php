<?php

namespace Drinks\Controller\Traits;

use Drinks\Manager\DrinkManager;
use Drinks\Manager\DrinkOrderManager;

/**
 * Order endpoints shared by the main site and the Theke. Requires JsonResponseTrait and SessionUserTrait.
 */
trait OrderResponseTrait
{
    /**
     * AccountLedger::history() entries prepared for drinks.phtml: "Sonstiges" and transfers
     * without comment show the drink name; with $userManager deposits name their creator.
     */
    protected function drinksPageEntries(array $entries, $userManager = null)
    {
        foreach ($entries as $index => $entry) {
            if ($entry['type'] === 'order') {
                if (DrinkManager::isCustomPriceDrink($entry['drink_id']) && $entry['comment'] === '') {
                    $entries[$index]['comment'] = $entry['name'];
                }
            } elseif ($userManager && $entry['createdbyuserid'] > 0) {
                $creator = $userManager->get($entry['createdbyuserid'], false);
                $entries[$index]['createdby'] = $creator ? ($creator->get('alias') ?: $creator->get('name')) : null;
            }
        }
        return $entries;
    }

    /**
     * JSON response for a DrinkManager::addOrdersAndNotify() result.
     */
    protected function orderResultResponse(array $result)
    {
        if ($result['success']) {
            return $this->jsonResponse(['success' => true, 'balance' => $result['balance']]);
        }
        return $this->jsonError(400, $result['error']);
    }

    /**
     * Cancel the POSTed order_id of $user within the cancel window and notify by mail.
     */
    protected function dropOrderResponse($user)
    {
        $orderId = (int)$this->params()->fromPost('order_id');
        if (!$orderId) {
            return $this->getResponse()->setStatusCode(400);
        }
        try {
            $success = $this->getDrinkManager()->dropOrderAndNotify($orderId, $user, [$this, 't'], @$this->getServiceLocator());
        } catch (\RuntimeException $e) {
            list($status, $message) = DrinkOrderManager::describeDropOrderError($e);
            return $this->jsonResponse(['success' => false, 'error_message' => $message], $status);
        }
        if (!$success) {
            return $this->jsonResponse(['success' => false, 'error_message' => 'Bestellung nicht gefunden.'], 404);
        }
        return $this->jsonResponse(['success' => true]);
    }
}
