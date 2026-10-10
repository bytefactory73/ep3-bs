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

        $teamLeadTeams = [];
        $isTeamMember = false;
        if ($user) {
            try {
                $drinkManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkManager');
                $teamLeadTeams = $drinkManager->getLedTeams($user->get('email'));
                $isTeamMember = $drinkManager->isTeamEventMember($user->get('uid'));
            } catch (\Exception $e) {
                $teamLeadTeams = [];
                $isTeamMember = false;
            }
        }
        // The first led team drives the button for backward compatibility
        $teamLeadTeamUserId = !empty($teamLeadTeams) ? $teamLeadTeams[0]['user_id'] : 0;
        $teamLeadTeamAlias = !empty($teamLeadTeams) ? $teamLeadTeams[0]['alias'] : '';

        $this->redirectBack()->setOrigin('frontend');

        $viewModel = new ViewModel(array(
            'dateStart' => $dateStart,
            'dateNow' => $dateNow,
            'squaresFilter' => $squaresFilter,
            'user' => $user,
            'teamLeadTeamUserId' => $teamLeadTeamUserId,
            'teamLeadTeamAlias' => $teamLeadTeamAlias,
            'teamLeadTeams' => $teamLeadTeams,
            'isTeamMember' => $isTeamMember,
        ));

        $viewModel->addChild($calendarViewModel);

        return $viewModel;
    }

}
