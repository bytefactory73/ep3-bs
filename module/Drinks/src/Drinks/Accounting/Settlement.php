<?php

namespace Drinks\Accounting;

use Base\Service\MoneyCalculator;

/**
 * Settlement of a Spieltag (pure, no DB): what each member has to pay, what is left against
 * what they paid, and the transfers that settle it. Charges round up and payouts are capped
 * by the Spieltag balance, so a Spieltag never ends below 0 €.
 */
class Settlement
{
    /**
     * Shares and remainders of the active members, and the proposed transfers.
     * $costRows: Kostenübersicht rows ('total_price' negative, 'share_per_member',
     * 'relevant_members' => [['uid'], ...]); $guestDonations: ['receiver_user_id', 'amount'];
     * $members: ['uid', 'name', 'is_member', 'total_paid', 'total_refunded'].
     * A row is split evenly among its relevant members. Guest donations reduce every share by
     * the factor (costs - donations) / costs; each receiver additionally owes the donation.
     * Shares round away from zero to cents (a member never pays a cent too little).
     * Returns ['members' => [uid => ['due' (negative = to pay), 'rest' (due + paid - refunded)]],
     * 'requests' => [['receiver_user_id', 'name', 'amount'], ...]]: refunds (rest > 0, not to
     * the team account itself) first, then charges (rest < 0).
     */
    public static function proposal(array $costRows, array $guestDonations, array $members, $teamUserId)
    {
        $shares = [];
        $costTotal = 0.0;
        foreach ($costRows as $row) {
            $costTotal += (float)$row['total_price'];
            foreach ($row['relevant_members'] as $member) {
                $uid = (int)$member['uid'];
                $shares[$uid] = (isset($shares[$uid]) ? $shares[$uid] : 0.0) + (float)$row['share_per_member'];
            }
        }

        $donationTotal = 0.0;
        foreach ($guestDonations as $donation) {
            $donationTotal += abs((float)$donation['amount']);
        }
        if ($donationTotal > 0) {
            $costTotal = abs($costTotal);
            // Without costs there are no shares to reduce (and no factor: 0 / 0)
            $factor = $costTotal > 0 ? ($costTotal - $donationTotal) / $costTotal : 1.0;
            foreach ($shares as $uid => $share) {
                $shares[$uid] = $share * $factor;
            }
            foreach ($guestDonations as $donation) {
                $uid = (int)$donation['receiver_user_id'];
                $shares[$uid] = (isset($shares[$uid]) ? $shares[$uid] : 0.0) - abs((float)$donation['amount']);
            }
        }

        $result = [];
        $refunds = [];
        $charges = [];
        foreach ($members as $member) {
            if (empty($member['is_member'])) {
                continue;
            }
            $uid = (int)$member['uid'];
            $due = MoneyCalculator::roundAwayFromZero(isset($shares[$uid]) ? $shares[$uid] : 0.0);
            $netPaid = MoneyCalculator::subtract($member['total_paid'], abs((float)$member['total_refunded']));
            $rest = MoneyCalculator::add($due, $netPaid);
            $result[$uid] = ['due' => $due, 'rest' => $rest];

            $request = ['receiver_user_id' => $uid, 'name' => (string)$member['name'], 'amount' => $rest];
            if ($rest > 0 && $uid !== (int)$teamUserId) {
                $refunds[] = $request;
            } elseif ($rest < 0) {
                $charges[] = $request;
            }
        }

        return ['members' => $result, 'requests' => array_merge($refunds, $charges)];
    }

    /**
     * Transfers for the requested amounts ([['receiver_user_id', 'amount'], ...], positive =
     * pay out to the member, negative = charge the member). Charges are booked in full; payouts
     * are capped at the Spieltag balance plus the charges and split in proportion to the
     * requests, in whole cents (largest remainder).
     * Returns ['rows' => [['receiver_user_id', 'requested', 'amount'], ...] in request order,
     * 'requested_refund', 'requested_charge', 'payout_total', 'collected_total', 'day_balance',
     * 'remaining' (Spieltag balance afterwards)].
     */
    public static function allocate($dayBalance, array $requests)
    {
        $requestedCents = [];
        $refundCents = 0;
        $chargeCents = 0;
        foreach (array_values($requests) as $index => $request) {
            $cents = MoneyCalculator::toCents($request['amount']);
            $requestedCents[$index] = $cents;
            if ($cents > 0) {
                $refundCents += $cents;
            } else {
                $chargeCents -= $cents;
            }
        }

        $allocatedCents = [];
        $payoutCents = min($refundCents, max(0, MoneyCalculator::toCents($dayBalance) + $chargeCents));
        if ($payoutCents > 0) {
            $parts = [];
            $usedCents = 0;
            foreach ($requestedCents as $index => $cents) {
                if ($cents > 0) {
                    $exact = $cents * $payoutCents / $refundCents;
                    $floor = (int)floor($exact + 1e-9);
                    $usedCents += $floor;
                    $parts[] = ['index' => $index, 'cents' => $floor, 'fraction' => $exact - $floor];
                }
            }
            usort($parts, function ($a, $b) {
                return ($b['fraction'] <=> $a['fraction']) ?: ($a['index'] <=> $b['index']);
            });
            for ($i = 0; $i < count($parts) && $usedCents < $payoutCents; $i++) {
                $parts[$i]['cents']++;
                $usedCents++;
            }
            foreach ($parts as $part) {
                $allocatedCents[$part['index']] = $part['cents'];
            }
        }

        $rows = [];
        $collectedCents = 0;
        foreach (array_values($requests) as $index => $request) {
            $cents = $requestedCents[$index] < 0 ? $requestedCents[$index] : (isset($allocatedCents[$index]) ? $allocatedCents[$index] : 0);
            if ($cents < 0) {
                $collectedCents -= $cents;
            }
            $rows[] = [
                'receiver_user_id' => (int)$request['receiver_user_id'],
                'requested' => MoneyCalculator::fromCents($requestedCents[$index]),
                'amount' => MoneyCalculator::fromCents($cents),
            ];
        }

        return [
            'rows' => $rows,
            'requested_refund' => MoneyCalculator::fromCents($refundCents),
            'requested_charge' => MoneyCalculator::fromCents($chargeCents),
            'payout_total' => MoneyCalculator::fromCents($payoutCents),
            'collected_total' => MoneyCalculator::fromCents($collectedCents),
            'day_balance' => MoneyCalculator::roundMoney($dayBalance),
            'remaining' => MoneyCalculator::fromCents(MoneyCalculator::toCents($dayBalance) + $collectedCents - $payoutCents),
        ];
    }
}
