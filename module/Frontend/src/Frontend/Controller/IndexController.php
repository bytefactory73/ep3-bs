<?php

namespace Frontend\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class IndexController extends AbstractActionController
{

    public function indexAction()
    {
        $calendarViewModel = $this->forward()->dispatch('Calendar\Controller\Calendar', ['action' => 'index']);
        $calendarViewModel->setCaptureTo('calendar');

        $dateStart = $calendarViewModel->getVariable('dateStart');
        $dateNow = $calendarViewModel->getVariable('dateNow');
        $squaresFilter = $calendarViewModel->getVariable('squaresFilter');
        $user = $calendarViewModel->getVariable('user');
        $teamLeadTeamUserId = 0;
        $teamLeadTeamAlias = '';

        if ($user) {
            $email = trim((string)$user->get('email'));
            if ($email !== '') {
                try {
                    $dbAdapter = $this->getServiceLocator()->get('Zend\\Db\\Adapter\\Adapter');
                    $teamLeadRow = $dbAdapter->query(
                        'SELECT da.user_id, da.alias FROM drink_aliases da WHERE da.is_team = 1 AND LOWER(TRIM(COALESCE(da.teamlead_email, ""))) = LOWER(TRIM(?)) ORDER BY da.user_id ASC LIMIT 1',
                        [$email]
                    )->current();
                    if ($teamLeadRow && !empty($teamLeadRow['user_id'])) {
                        $teamLeadTeamUserId = (int)$teamLeadRow['user_id'];
                        $teamLeadTeamAlias = isset($teamLeadRow['alias']) ? trim((string)$teamLeadRow['alias']) : '';
                    }
                } catch (\Exception $e) {
                    $teamLeadTeamUserId = 0;
                    $teamLeadTeamAlias = '';
                }
            }
        }

        $this->redirectBack()->setOrigin('frontend');

        $viewModel = new ViewModel(array(
            'dateStart' => $dateStart,
            'dateNow' => $dateNow,
            'squaresFilter' => $squaresFilter,
            'user' => $user,
            'teamLeadTeamUserId' => $teamLeadTeamUserId,
            'teamLeadTeamAlias' => $teamLeadTeamAlias,
        ));

        $viewModel->addChild($calendarViewModel);

        return $viewModel;
    }

}
