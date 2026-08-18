<?php

namespace Drinks\Controller\Traits;

trait EditDrinksAliasTrait
{
    /**
     * Prepare and handle the edit drinks alias form for user settings
     * 
     * @param object $user The current user
     * @param object $serviceManager Service manager
     * @param string $editParam The edit parameter from request
     * @return object|null The form object or null if user doesn't have drinks enabled
     */
    protected function prepareDrinksAliasForm($user, $serviceManager, $editParam)
    {
        $formElementManager = $serviceManager->get('FormElementManager');
        $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
        
        $userId = $user->need('uid');
        
        // Check if user has drinks enabled
        $drinksAliasRow = $dbAdapter->query('SELECT alias, order_email_option FROM drink_aliases WHERE user_id = ? AND enabled = 1', [$userId])->current();
        
        if (!$drinksAliasRow) {
            return null;
        }
        
        $editDrinksAliasForm = $formElementManager->get('Drinks\Form\EditDrinksAliasForm');

        if ($this->getRequest()->isPost() && $editParam == 'drinks-alias') {
            $editDrinksAliasForm->setData($this->params()->fromPost());

            if ($editDrinksAliasForm->isValid()) {
                $data = $editDrinksAliasForm->getData();

                $alias = $data['edaf-alias'];
                $orderEmail = $data['edaf-order-email'] ?? 'order';

                // Check uniqueness before update
                $existing = $dbAdapter->query('SELECT user_id FROM drink_aliases WHERE alias = ? AND user_id != ?', [$alias, $userId])->current();
                if ($existing) {
                    $editDrinksAliasForm->get('edaf-alias')->setMessages(array($this->t('Diese Theken-ID ist bereits vergeben.')));
                } else {
                    $dbAdapter->query(
                        'UPDATE drink_aliases SET alias = ?, order_email_option = ? WHERE user_id = ?',
                        [$alias, $orderEmail, $userId]
                    );

                    $this->flashMessenger()->addSuccessMessage($this->t('Theken-ID und Bestell-Email-Option wurden gespeichert.'));

                    return $this->redirect()->toRoute('user/settings');
                }
            }
        } else {
            $editDrinksAliasForm->get('edaf-alias')->setValue($drinksAliasRow['alias']);
            // If order_email_option is empty, set default to 'order'
            $orderEmailValue = ($drinksAliasRow['order_email_option'] === null || $drinksAliasRow['order_email_option'] === '') ? 'order' : $drinksAliasRow['order_email_option'];
            $editDrinksAliasForm->get('edaf-order-email')->setValue($orderEmailValue);
        }

        return $editDrinksAliasForm;
    }
}
