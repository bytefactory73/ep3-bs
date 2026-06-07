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
        $teamLeadTeamUserId = 0;
        $teamLeadTeamAlias = '';
        $isTeamMember = false;

        if ($user) {
            $email = trim((string)$user->get('email'));
            if ($email !== '') {
                try {
                    $dbAdapter = $this->getServiceLocator()->get('Zend\\Db\\Adapter\\Adapter');

                    // Get ALL teams where the user is teamlead
                    $teamLeadRows = $dbAdapter->query(
                        'SELECT da.user_id, da.alias
                         FROM drink_aliases da
                         WHERE da.is_team = 1
                             AND FIND_IN_SET(LOWER(TRIM(?)), REPLACE(REPLACE(LOWER(COALESCE(da.teamlead_email, "")), " ", ""), ";", ",")) > 0
                         ORDER BY da.user_id ASC',
                        [$email]
                    )->toArray();

                    foreach ($teamLeadRows as $teamLeadRow) {
                        if (!empty($teamLeadRow['user_id'])) {
                            $teamLeadTeams[] = [
                                'user_id' => (int)$teamLeadRow['user_id'],
                                'alias' => isset($teamLeadRow['alias']) ? trim((string)$teamLeadRow['alias']) : '',
                            ];
                        }
                    }

                    // Use the first team for backward compatibility (button display)
                    if (!empty($teamLeadTeams)) {
                        $teamLeadTeamUserId = $teamLeadTeams[0]['user_id'];
                        $teamLeadTeamAlias = $teamLeadTeams[0]['alias'];
                    }

                    // Check if user is a team member of any team event
                    $userUid = (int)$user->get('uid');
                    if ($userUid > 0) {
                        $memberRow = $dbAdapter->query(
                            'SELECT COUNT(DISTINCT tm.team_event_id) AS cnt
                             FROM drinks_teamevent_members tm
                             INNER JOIN drinks_teamevents te ON tm.team_event_id = te.id
                             WHERE tm.user_id = ?
                             LIMIT 1',
                            [$userUid]
                        )->current();
                        $isTeamMember = ($memberRow && (int)$memberRow['cnt'] > 0);
                    }
                } catch (\Exception $e) {
                    $teamLeadTeamUserId = 0;
                    $teamLeadTeamAlias = '';
                    $isTeamMember = false;
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
            'teamLeadTeams' => $teamLeadTeams,
            'isTeamMember' => $isTeamMember,
        ));

        $viewModel->addChild($calendarViewModel);

        return $viewModel;
    }

}
