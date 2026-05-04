<?php

namespace User\Controller\Traits;

trait TeamEventTrait
{
    protected function getTeamEventDbAdapter()
    {
        return $this->getServiceLocator()->get('Zend\\Db\\Adapter\\Adapter');
    }

    protected function normalizeTeamEventLabel($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        return mb_substr($value, 0, 100);
    }

    protected function getLatestTeamEventRow($teamAdminUserId)
    {
        return $this->getTeamEventDbAdapter()->query(
            'SELECT id, comment FROM drinks_teamevents WHERE team_admin_user_id = ? ORDER BY created_at DESC, id DESC LIMIT 1',
            [$teamAdminUserId]
        )->current();
    }

    protected function getTeamEventById($teamAdminUserId, $teamEventId)
    {
        $teamEventId = (int)$teamEventId;
        if ($teamEventId <= 0) {
            return null;
        }

        return $this->getTeamEventDbAdapter()->query(
            'SELECT id, comment FROM drinks_teamevents WHERE team_admin_user_id = ? AND id = ? LIMIT 1',
            [$teamAdminUserId, $teamEventId]
        )->current();
    }

    protected function getTeamEventByLabel($teamAdminUserId, $label)
    {
        $label = $this->normalizeTeamEventLabel($label);
        if ($label === '') {
            return null;
        }

        return $this->getTeamEventDbAdapter()->query(
            'SELECT id, comment FROM drinks_teamevents WHERE team_admin_user_id = ? AND comment = ? ORDER BY id DESC LIMIT 1',
            [$teamAdminUserId, $label]
        )->current();
    }

    protected function getOrCreateTeamEventByLabel($teamAdminUserId, $label)
    {
        $label = $this->normalizeTeamEventLabel($label);
        if ($label === '') {
            return null;
        }

        $row = $this->getTeamEventByLabel($teamAdminUserId, $label);
        if ($row) {
            return $row;
        }

        $this->getTeamEventDbAdapter()->query(
            'INSERT INTO drinks_teamevents (team_admin_user_id, comment) VALUES (?, ?)',
            [$teamAdminUserId, $label]
        );
        return $this->getTeamEventByLabel($teamAdminUserId, $label);
    }

    protected function getAvailableTeamEventLabels($teamAdminUserId)
    {
        $rows = $this->getTeamEventDbAdapter()->query(
            'SELECT comment AS team_event_label FROM drinks_teamevents WHERE team_admin_user_id = ? ORDER BY created_at DESC, id DESC',
            [$teamAdminUserId]
        )->toArray();
        $result = [];
        foreach ($rows as $row) {
            $teamEventLabel = isset($row['team_event_label']) ? trim((string)$row['team_event_label']) : '';
            if ($teamEventLabel !== '' && !in_array($teamEventLabel, $result, true)) {
                $result[] = $teamEventLabel;
            }
        }
        return $result;
    }

    protected function resolveSessionTeamEventSelection($teamAdminUserId, \Zend\Session\Container $session)
    {
        $selectedTeamEventLabel = $this->normalizeTeamEventLabel(isset($session->current_spieltag) ? $session->current_spieltag : '');
        $availableTeamEventLabels = $this->getAvailableTeamEventLabels($teamAdminUserId);
        if ($selectedTeamEventLabel === '' && empty($availableTeamEventLabels)) {
            $selectedTeamEventLabel = 'Spieltag ' . date('Y-m-d');
        }
        if ($selectedTeamEventLabel !== '') {
            $teamEvent = $this->getOrCreateTeamEventByLabel($teamAdminUserId, $selectedTeamEventLabel);
            if ($teamEvent) {
                $session->current_teamevent_id = (int)$teamEvent['id'];
            }
        }
        if ($selectedTeamEventLabel !== '' && !in_array($selectedTeamEventLabel, $availableTeamEventLabels, true)) {
            array_unshift($availableTeamEventLabels, $selectedTeamEventLabel);
        }
        if ($selectedTeamEventLabel === '' && !empty($availableTeamEventLabels)) {
            $selectedTeamEventLabel = $availableTeamEventLabels[0];
            $teamEvent = $this->getTeamEventByLabel($teamAdminUserId, $selectedTeamEventLabel);
            if ($teamEvent) {
                $session->current_teamevent_id = (int)$teamEvent['id'];
            }
        }
        $session->current_spieltag = $selectedTeamEventLabel;
        return [$selectedTeamEventLabel, $availableTeamEventLabels];
    }

    protected function resolveTeamEventForSelection($teamAdminUserId, $preferredTeamEventId = 0, $newTeamEventLabel = '')
    {
        $preferredTeamEventId = (int)$preferredTeamEventId;
        if ($preferredTeamEventId > 0) {
            $selectedTeamEvent = $this->getTeamEventById($teamAdminUserId, $preferredTeamEventId);
            if ($selectedTeamEvent) {
                return $selectedTeamEvent;
            }
            return null;
        }

        $newTeamEventLabel = $this->normalizeTeamEventLabel($newTeamEventLabel);
        if ($newTeamEventLabel !== '') {
            return $this->getOrCreateTeamEventByLabel($teamAdminUserId, $newTeamEventLabel);
        }

        return null;
    }
}