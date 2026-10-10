<?php

namespace Drinks\Controller\Traits;

/**
 * Write endpoints of the Kostenübersicht modal (members, order relevance, Zusatzkosten,
 * Gastspenden, closing a Spieltag). Shared by the Theke (SimpleLoginController, team account
 * logged in) and the main site (DrinksController, teamlead or thekenadmin logged in); the
 * controllers differ only in resolveManagedTeamEvent().
 * Requires JsonResponseTrait and TeamEventTrait.
 */
trait TeamEventEndpointsTrait
{
    /**
     * Resolve the Spieltag a request acts on and authorise the caller for it.
     * Returns [teamAdminUserId, teamEventRow, actorUserId], or a JSON error response.
     */
    abstract protected function resolveManagedTeamEvent($teamEventId, $requireOpen);

    /**
     * Hook after a Spieltag was closed and settled.
     */
    protected function afterTeamEventClosed($teamAdminUserId, $teamEvent)
    {
    }

    protected function handleTeamMembersRequest()
    {
        if ($error = $this->rejectNonPost()) {
            return $error;
        }
        $context = $this->resolveManagedTeamEvent($this->params()->fromPost('team_event_id', 0), true);
        if (!is_array($context)) {
            return $context;
        }
        list($teamAdminUserId, $teamEvent, $actorUserId) = $context;

        $memberUserId = (int)$this->params()->fromPost('member_user_id', 0);
        $operation = trim((string)$this->params()->fromPost('operation', ''));
        if ($memberUserId <= 0 || ($operation !== 'add' && $operation !== 'remove')) {
            return $this->jsonError(400, 'Ungültige Eingabe.');
        }

        list($success, $error) = $this->processTeamEventMemberOperation($teamAdminUserId, (int)$teamEvent['id'], $memberUserId, $operation, $actorUserId);
        if (!$success) {
            return $this->jsonError(400, $error ?: 'Mitglieder konnten nicht aktualisiert werden.');
        }
        return $this->jsonResponse(['success' => true]);
    }

    protected function handleOrderRelevanceRequest()
    {
        if ($error = $this->rejectNonPost()) {
            return $error;
        }
        $context = $this->resolveManagedTeamEvent($this->params()->fromPost('team_event_id', 0), true);
        if (!is_array($context)) {
            return $context;
        }
        list($teamAdminUserId, $teamEvent) = $context;
        $teamEventId = (int)$teamEvent['id'];

        $drinkId = (int)$this->params()->fromPost('drink_id', 0);
        $unitPrice = (float)$this->params()->fromPost('unit_price', 0);
        if ($drinkId <= 0 || $unitPrice <= 0) {
            return $this->jsonError(400, 'Ungültige Eingabe.');
        }
        $memberUserIds = $this->filterTeamEventMemberIds($teamEventId, $this->params()->fromPost('member_user_ids', ''));

        try {
            $this->saveTeamEventOrderRelevance($teamAdminUserId, $teamEventId, $drinkId, $unitPrice, $memberUserIds);
        } catch (\Exception $e) {
            error_log('team order relevance: ' . $e->getMessage());
            return $this->jsonError(500, 'Relevanz konnte nicht gespeichert werden.');
        }
        return $this->teamEventStatsResponse($teamAdminUserId, $teamEvent);
    }

    /**
     * GET lists the Zusatzkosten of a Spieltag, POST adds one.
     */
    protected function handleExtraCostRequest()
    {
        $request = $this->getRequest();
        if ($request->isGet()) {
            $context = $this->resolveManagedTeamEvent($this->params()->fromQuery('team_event_id', 0), false);
            if (!is_array($context)) {
                return $context;
            }
            return $this->jsonResponse([
                'success' => true,
                'extra_costs' => $this->getTeamEventExtraCosts((int)$context[1]['id']),
            ]);
        }
        if ($error = $this->rejectNonPost()) {
            return $error;
        }
        return $this->saveExtraCostFromPost(false);
    }

    protected function handleUpdateExtraCostRequest()
    {
        if ($error = $this->rejectNonPost()) {
            return $error;
        }
        return $this->saveExtraCostFromPost(true);
    }

    private function saveExtraCostFromPost($isUpdate)
    {
        $context = $this->resolveManagedTeamEvent($this->params()->fromPost('team_event_id', 0), true);
        if (!is_array($context)) {
            return $context;
        }
        list($teamAdminUserId, $teamEvent) = $context;
        $teamEventId = (int)$teamEvent['id'];

        $extraCostId = (int)$this->params()->fromPost('extra_cost_id', 0);
        $payerUserId = (int)$this->params()->fromPost('payer_user_id', 0);
        $amount = (float)$this->params()->fromPost('amount', 0);
        $comment = trim((string)$this->params()->fromPost('comment', ''));
        if (($isUpdate && $extraCostId <= 0) || $payerUserId <= 0 || $amount <= 0) {
            return $this->jsonError(400, 'Ungültige Eingabe.');
        }
        $memberUserIds = $this->filterTeamEventMemberIds($teamEventId, $this->params()->fromPost('relevant_member_ids', ''));

        try {
            if ($isUpdate) {
                $this->updateTeamEventExtraCost($extraCostId, $teamEventId, $payerUserId, $amount, $comment, $memberUserIds);
            } else {
                $this->saveTeamEventExtraCost($teamEventId, $payerUserId, $amount, $comment, $memberUserIds);
            }
        } catch (\Exception $e) {
            error_log('team extra cost: ' . $e->getMessage());
            return $this->jsonError(500, 'Extrakosten konnten nicht gespeichert werden.');
        }
        return $this->teamEventStatsResponse($teamAdminUserId, $teamEvent);
    }

    protected function handleDeleteExtraCostRequest()
    {
        return $this->handleDeleteRequest('extra_cost_id', 'deleteTeamEventExtraCost', 'Extrakosten konnten nicht gelöscht werden.');
    }

    /**
     * GET lists the Gastspenden of a Spieltag, POST adds one.
     */
    protected function handleGuestDonationRequest()
    {
        $request = $this->getRequest();
        if ($request->isGet()) {
            $context = $this->resolveManagedTeamEvent($this->params()->fromQuery('team_event_id', 0), false);
            if (!is_array($context)) {
                return $context;
            }
            return $this->jsonResponse([
                'success' => true,
                'guest_donations' => $this->getTeamEventGuestDonations((int)$context[1]['id']),
            ]);
        }
        if ($error = $this->rejectNonPost()) {
            return $error;
        }
        return $this->saveGuestDonationFromPost(false);
    }

    protected function handleUpdateGuestDonationRequest()
    {
        if ($error = $this->rejectNonPost()) {
            return $error;
        }
        return $this->saveGuestDonationFromPost(true);
    }

    private function saveGuestDonationFromPost($isUpdate)
    {
        $context = $this->resolveManagedTeamEvent($this->params()->fromPost('team_event_id', 0), true);
        if (!is_array($context)) {
            return $context;
        }
        list($teamAdminUserId, $teamEvent) = $context;
        $teamEventId = (int)$teamEvent['id'];

        $guestDonationId = (int)$this->params()->fromPost('guest_donation_id', 0);
        $receiverUserId = (int)$this->params()->fromPost('receiver_user_id', 0);
        $amount = (float)$this->params()->fromPost('amount', 0);
        $comment = trim((string)$this->params()->fromPost('comment', ''));
        if (($isUpdate && $guestDonationId <= 0) || $receiverUserId <= 0 || $amount <= 0) {
            return $this->jsonError(400, 'Ungültige Eingabe.');
        }
        if (!in_array($receiverUserId, $this->getTeamEventMemberUserIds($teamEventId), true)) {
            return $this->jsonError(400, 'Empfänger ist kein aktiver Teilnehmer.');
        }

        try {
            if ($isUpdate) {
                $this->updateTeamEventGuestDonation($guestDonationId, $teamEventId, $receiverUserId, $amount, $comment);
            } else {
                $this->saveTeamEventGuestDonation($teamEventId, $receiverUserId, $amount, $comment);
            }
        } catch (\Exception $e) {
            error_log('team guest donation: ' . $e->getMessage());
            return $this->jsonError(500, 'Gastspende konnte nicht gespeichert werden.');
        }
        return $this->teamEventStatsResponse($teamAdminUserId, $teamEvent);
    }

    protected function handleDeleteGuestDonationRequest()
    {
        return $this->handleDeleteRequest('guest_donation_id', 'deleteTeamEventGuestDonation', 'Gastspende konnte nicht gelöscht werden.');
    }

    private function handleDeleteRequest($idParam, $deleteMethod, $failureMessage)
    {
        if ($error = $this->rejectNonPost()) {
            return $error;
        }
        $context = $this->resolveManagedTeamEvent($this->params()->fromPost('team_event_id', 0), true);
        if (!is_array($context)) {
            return $context;
        }
        list($teamAdminUserId, $teamEvent) = $context;

        $entryId = (int)$this->params()->fromPost($idParam, 0);
        if ($entryId <= 0) {
            return $this->jsonError(400, 'Ungültige Eingabe.');
        }

        try {
            $this->$deleteMethod($entryId, true, (int)$teamEvent['id']);
        } catch (\Exception $e) {
            error_log($deleteMethod . ': ' . $e->getMessage());
            return $this->jsonError(500, $failureMessage);
        }
        return $this->teamEventStatsResponse($teamAdminUserId, $teamEvent);
    }

    /**
     * Close a Spieltag, then pay out / collect the settlement transfers the modal sent.
     */
    protected function handleCloseTeamEventRequest()
    {
        if ($error = $this->rejectNonPost()) {
            return $error;
        }
        $context = $this->resolveManagedTeamEvent($this->params()->fromPost('team_event_id', 0), false);
        if (!is_array($context)) {
            return $context;
        }
        list($teamAdminUserId, $teamEvent) = $context;
        $teamEventId = (int)$teamEvent['id'];

        $closeResult = $this->closeTeamEvent($teamAdminUserId, $teamEventId);
        if (empty($closeResult['success'])) {
            $error = isset($closeResult['error']) ? $closeResult['error'] : '';
            if ($error === 'feature_unavailable') {
                return $this->jsonError(500, 'Team-Event Schließen ist noch nicht verfügbar.');
            }
            if ($error === 'not_found') {
                return $this->jsonError(404, 'Spieltag nicht gefunden.');
            }
            return $this->jsonError(400, 'Ungültiger Spieltag.');
        }

        $settlementRefunds = $this->params()->fromPost('settlement_refunds', '');
        if (is_string($settlementRefunds)) {
            $settlementRefunds = trim($settlementRefunds) !== '' ? json_decode($settlementRefunds, true) : [];
        }
        $settlementResult = ['success' => true, 'total_refund' => 0.0, 'transfers' => []];
        if (!empty($settlementRefunds) && is_array($settlementRefunds)) {
            $settlementResult = $this->processTeamEventSettlementRefunds($teamAdminUserId, $teamEventId, $settlementRefunds, true);
            if (empty($settlementResult['success'])) {
                $errorCode = isset($settlementResult['error']) ? (string)$settlementResult['error'] : '';
                $messages = [
                    'insufficient_settlement_balance' => 'Spieltagssaldo reicht für die gewünschten Ausgleichszahlungen nicht aus.',
                    'insufficient_team_balance' => 'Nicht genügend Guthaben für die Ausgleichszahlungen vorhanden.',
                    'transfer_failed' => 'Mindestens eine Ausgleichszahlung ist fehlgeschlagen.',
                ];
                return $this->jsonError(
                    400,
                    isset($messages[$errorCode]) ? $messages[$errorCode] : 'Ausgleichszahlungen konnten nicht vollständig ausgeführt werden.',
                    [
                        'error_code' => $errorCode !== '' ? $errorCode : 'settlement_failed',
                        'settlement' => $settlementResult,
                    ]
                );
            }
        }

        $this->afterTeamEventClosed($teamAdminUserId, $teamEvent);

        return $this->jsonResponse([
            'success' => true,
            'already_closed' => !empty($closeResult['already_closed']),
            'settlement' => $settlementResult,
        ]);
    }

    /**
     * Keep only ids of current participants of the Spieltag.
     */
    protected function filterTeamEventMemberIds($teamEventId, $rawMemberIds)
    {
        $allowedLookup = array_flip($this->getTeamEventMemberUserIds($teamEventId));
        $filtered = [];
        foreach ($this->parseTeamEventMemberIds($rawMemberIds) as $memberUserId) {
            if (isset($allowedLookup[$memberUserId])) {
                $filtered[] = $memberUserId;
            }
        }
        return $filtered;
    }

    /**
     * Fresh modal data for the Spieltag after a write.
     */
    protected function teamEventStatsResponse($teamAdminUserId, $teamEvent)
    {
        $teamEventLabel = isset($teamEvent['comment']) ? trim((string)$teamEvent['comment']) : '';
        return $this->jsonResponse(array_merge([
            'success' => true,
            'spieltage' => $this->getAvailableTeamEventLabels($teamAdminUserId, true),
            'open_spieltage' => $this->getAvailableTeamEventLabels($teamAdminUserId, false),
        ], $this->buildTeamStatsPayload($teamAdminUserId, $teamEventLabel, ['team_event_id' => (int)$teamEvent['id']])));
    }
}
