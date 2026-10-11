<?php
/**
 * Calculation tests for the Drinks money logic (no database, no framework):
 * MoneyCalculator, Settlement and the static helpers of AccountLedger / TeamEventLedger.
 *
 *   docker run --rm -v "$PWD":/app -w /app php:8.5-cli php tests/accounting/run.php
 *
 * Exit code 0 if all checks pass.
 */

$root = dirname(__DIR__, 2);
require $root . '/module/Base/src/Base/Service/MoneyCalculator.php';
require $root . '/module/Drinks/src/Drinks/Accounting/AccountLedger.php';
require $root . '/module/Drinks/src/Drinks/Accounting/TeamEventLedger.php';
require $root . '/module/Drinks/src/Drinks/Accounting/Settlement.php';
require $root . '/module/Drinks/src/Drinks/Accounting/DrinkSummary.php';

use Base\Service\MoneyCalculator;
use Drinks\Accounting\AccountLedger;
use Drinks\Accounting\DrinkSummary;
use Drinks\Accounting\Settlement;
use Drinks\Accounting\TeamEventLedger;

$failures = 0;
$checks = 0;

function check($name, $actual, $expected)
{
    global $failures, $checks;
    $checks++;
    if ($actual !== $expected) {
        $failures++;
        echo 'FAIL  ' . $name . "\n      expected " . json_encode($expected) . "\n      got      " . json_encode($actual) . "\n";
    }
}

/**
 * Kostenübersicht row of $total (negative) split among the given member ids.
 */
function costRow($total, array $memberIds)
{
    return [
        'total_price' => $total,
        'share_per_member' => $total / count($memberIds),
        'relevant_members' => array_map(function ($uid) {
            return ['uid' => $uid];
        }, $memberIds),
    ];
}

function member($uid, $paid = 0.0, $refunded = 0.0, $isMember = true)
{
    return ['uid' => $uid, 'name' => 'User ' . $uid, 'is_member' => $isMember, 'total_paid' => $paid, 'total_refunded' => $refunded];
}

function requests(array $amountsByUid)
{
    $requests = [];
    foreach ($amountsByUid as $uid => $amount) {
        $requests[] = ['receiver_user_id' => $uid, 'amount' => $amount];
    }
    return $requests;
}

function amounts(array $allocation)
{
    return array_column($allocation['rows'], 'amount', 'receiver_user_id');
}

const TEAM = 900;

// ------------------------------------------------------------------ MoneyCalculator

check('0.10 + 0.20 = 0.30', MoneyCalculator::add(0.1, 0.2), 0.3);
check('0.30 deposit - (0.10 + 0.20) = 0.00', MoneyCalculator::subtract(0.3, MoneyCalculator::add(0.1, 0.2)), 0.0);
check('3 x 1.10 = 3.30', MoneyCalculator::multiply(1.1, 3), 3.3);
check('toCents(19.99)', MoneyCalculator::toCents(19.99), 1999);
check('fromCents(-1)', MoneyCalculator::fromCents(-1), -0.01);
check('roundAwayFromZero(-3.3333) = -3.34', MoneyCalculator::roundAwayFromZero(-10 / 3), -3.34);
check('roundAwayFromZero(3.331) = 3.34', MoneyCalculator::roundAwayFromZero(3.331), 3.34);
check('roundAwayFromZero(1.1) stays 1.10 (float noise)', MoneyCalculator::roundAwayFromZero(1.1), 1.1);
check('roundAwayFromZero(-2.2 / 2) = -1.10', MoneyCalculator::roundAwayFromZero(-2.2 / 2), -1.1);
check('roundAwayFromZero(0) = 0', MoneyCalculator::roundAwayFromZero(0), 0.0);
check('no negative zero (shown as "-0,00 €")', [(string)MoneyCalculator::roundMoney(-0.001), (string)MoneyCalculator::fromCents(-0.0), (string)MoneyCalculator::roundMoney(-0.0)], ['0', '0', '0']);

// ------------------------------------------------------------------ AccountLedger helpers

check('lineTotal 3 x 1.10', AccountLedger::lineTotal(3, 1.1), 3.3);
check('lineTotal 7 x 0.70', AccountLedger::lineTotal(7, 0.7), 4.9);
check('order exactly at the limit fits', AccountLedger::fitsOrderLimit(5.0, 5.0), true);
check('order one cent over the limit does not fit', AccountLedger::fitsOrderLimit(5.01, 5.0), false);
check('order with float noise at the limit fits', AccountLedger::fitsOrderLimit(0.1 + 0.2, 0.3), true);
check('no limit', AccountLedger::fitsOrderLimit(1000, null), true);
check('negative limit blocks every order', AccountLedger::fitsOrderLimit(0.01, -0.5), false);

$entries = [
    ['type' => 'deposit', 'datetime' => '2026-10-01 10:00:00', 'deleted' => 0, 'amount' => 10.0, 'balance_after' => 10.0],
    ['type' => 'order', 'datetime' => '2026-10-01 18:00:00', 'deleted' => 0, 'amount' => -2.5, 'balance_after' => 7.5],
    ['type' => 'order', 'datetime' => '2026-10-01 19:00:00', 'deleted' => 1, 'amount' => -1.5, 'balance_after' => 7.5],
    ['type' => 'order', 'datetime' => '2026-10-02 18:00:00', 'deleted' => 0, 'amount' => -1.5, 'balance_after' => 6.0],
];
$days = AccountLedger::dayGroups($entries);
check('dayGroups: one group per day', array_column($days, 'date'), ['2026-10-01', '2026-10-02']);
check('dayGroups: day total without cancelled entries', array_column($days, 'total'), [7.5, -1.5]);
check('dayGroups: balance at day end', array_column($days, 'balance'), [7.5, 6.0]);

// ------------------------------------------------------------------ TeamEventLedger helpers

check('Spieltag condition params', TeamEventLedger::rowConditionParams(17, ' Spieltag 1 '), [17, 'Spieltag 1']);
check('without Spieltag id only legacy rows match', TeamEventLedger::rowConditionParams(0, 'Spieltag 1'), [-1, 'Spieltag 1']);

// ------------------------------------------------------------------ Settlement::proposal

$proposal = Settlement::proposal([costRow(-2.2, [1, 2])], [], [member(1), member(2)], TEAM);
check('2.20 / 2: due 1.10 each', $proposal['members'], [1 => ['due' => -1.1, 'rest' => -1.1], 2 => ['due' => -1.1, 'rest' => -1.1]]);
check('2.20 / 2: both are charged', $proposal['requests'], [
    ['receiver_user_id' => 1, 'name' => 'User 1', 'amount' => -1.1],
    ['receiver_user_id' => 2, 'name' => 'User 2', 'amount' => -1.1],
]);

$proposal = Settlement::proposal([costRow(-10.0, [1, 2, 3])], [], [member(1), member(2), member(3)], TEAM);
check('10.00 / 3: due 3.34 each (rounded up)', array_column($proposal['members'], 'due'), [-3.34, -3.34, -3.34]);
$collected = Settlement::allocate(-10.0, requests([1 => -3.34, 2 => -3.34, 3 => -3.34]));
check('10.00 / 3: Spieltag ends at +0.02', $collected['remaining'], 0.02);

$proposal = Settlement::proposal([costRow(-10.0, [1, 2])], [], [member(1, 8.0), member(2, 0.0)], TEAM);
check('paid more than the share: refund, otherwise charge', $proposal['requests'], [
    ['receiver_user_id' => 1, 'name' => 'User 1', 'amount' => 3.0],
    ['receiver_user_id' => 2, 'name' => 'User 2', 'amount' => -5.0],
]);

$proposal = Settlement::proposal([costRow(-10.0, [1, 2])], [], [member(1, 10.0, 5.0), member(2, 5.0)], TEAM);
check('refunds already paid out count', $proposal['members'][1]['rest'], 0.0);
check('settled Spieltag: no requests', $proposal['requests'], []);

$proposal = Settlement::proposal([costRow(-6.0, [1]), costRow(-4.0, [1, 2])], [], [member(1), member(2)], TEAM);
check('rows only for their relevant members', array_column($proposal['members'], 'due'), [-8.0, -2.0]);

$proposal = Settlement::proposal([costRow(-4.0, [1, 2])], [], [member(1, 0.0), member(2), member(3, 9.0, 0.0, false)], TEAM);
check('non-members have no share and get no request', array_keys($proposal['members']), [1, 2]);

$proposal = Settlement::proposal([costRow(-4.0, [TEAM, 2])], [], [member(TEAM, 10.0), member(2)], TEAM);
check('no refund to the team account itself', array_column($proposal['requests'], 'receiver_user_id'), [2]);

$donations = [['receiver_user_id' => 1, 'amount' => 10.0]];
$proposal = Settlement::proposal([costRow(-25.0, [1, 2])], $donations, [member(1), member(2)], TEAM);
check('guest donation 10 of 25: shares x 0.6, receiver owes the donation too', array_column($proposal['members'], 'due'), [-17.5, -7.5]);

$proposal = Settlement::proposal([], $donations, [member(1), member(2)], TEAM);
check('guest donation without costs: only the receiver owes it', array_column($proposal['members'], 'due'), [-10.0, 0.0]);

$proposal = Settlement::proposal([], [], [], TEAM);
check('empty Spieltag', $proposal, ['members' => [], 'requests' => []]);

// ------------------------------------------------------------------ Settlement::allocate

$allocation = Settlement::allocate(10.0, requests([1 => 4.0, 2 => 6.0]));
check('enough money: payouts as requested', amounts($allocation), [1 => 4.0, 2 => 6.0]);
check('enough money: remaining', $allocation['remaining'], 0.0);

$allocation = Settlement::allocate(5.0, requests([1 => 4.0, 2 => 6.0]));
check('payouts capped at the balance, in proportion', amounts($allocation), [1 => 2.0, 2 => 3.0]);
check('capped: Spieltag ends at 0', $allocation['remaining'], 0.0);
check('capped: requested refund reported', $allocation['requested_refund'], 10.0);

$allocation = Settlement::allocate(0.02, requests([1 => 1.0, 2 => 1.0, 3 => 1.0]));
check('leftover cents go to the largest remainders, then in order', amounts($allocation), [1 => 0.01, 2 => 0.01, 3 => 0.0]);

$allocation = Settlement::allocate(1.0, requests([1 => 5.0, 2 => -3.0]));
check('charges raise the payout budget', amounts($allocation), [1 => 4.0, 2 => -3.0]);
check('charges: collected and paid out', [$allocation['collected_total'], $allocation['payout_total'], $allocation['remaining']], [3.0, 4.0, 0.0]);

$allocation = Settlement::allocate(-4.0, requests([1 => 6.0, 2 => -3.0]));
check('negative balance: no payout', amounts($allocation), [1 => 0.0, 2 => -3.0]);
check('negative balance: Spieltag stays below 0 (closing is refused)', $allocation['remaining'], -1.0);

$allocation = Settlement::allocate(-4.0, requests([1 => -4.0]));
check('charge settles a negative balance', $allocation['remaining'], 0.0);

foreach ([[7.13, [3.0, 2.5, 9.99]], [0.0, [1.0]], [100.0, [33.33, 33.33, 33.34]], [0.05, [0.01, 0.02, 0.03, 0.04]]] as list($balance, $refunds)) {
    $allocation = Settlement::allocate($balance, requests(array_combine(range(1, count($refunds)), $refunds)));
    check(sprintf('balance %.2f: never below 0', $balance), $allocation['remaining'] >= 0, true);
    check(sprintf('balance %.2f: rows add up to the payout', $balance), MoneyCalculator::fromCents(array_sum(array_map([MoneyCalculator::class, 'toCents'], amounts($allocation)))), $allocation['payout_total']);
}

check('no requests', Settlement::allocate(3.0, []), [
    'rows' => [], 'requested_refund' => 0.0, 'requested_charge' => 0.0, 'payout_total' => 0.0,
    'collected_total' => 0.0, 'day_balance' => 3.0, 'remaining' => 3.0,
]);

// ------------------------------------------------------------------ DrinkSummary

$drinks = [['id' => 1, 'name' => 'Cola'], ['id' => 2, 'name' => 'Bier'], ['id' => 3, 'name' => 'Wasser']];
$orderRow = function ($grp, $uid, $drinkId, $quantity, $total) {
    return ['grp' => $grp, 'user_id' => $uid, 'drink_id' => $drinkId, 'quantity' => $quantity, 'total_amount' => $total];
};
$orderRows = [
    $orderRow('2026-10-05', 1, 1, 3, '0.30'),   // Monday
    $orderRow('2026-10-05', 2, 1, 1, '0.10'),
    $orderRow('2026-10-06', 1, 2, 2, '0.20'),   // Tuesday
    $orderRow('2026-10-12', 2, 2, 1, '2.50'),   // Monday
];
$users = [2 => 'Anna', 1 => 'Bernd'];

$table = DrinkSummary::table($orderRows, $drinks, $users, 'date', true, true, false);
check('summary: drinks without orders have no column', array_column($table['drinks'], 'id'), [1, 2]);
check('summary: newest date first, users in given order', array_map(function ($row) {
    return [$row['label'], $row['user']];
}, $table['rows']), [['2026-10-12 (Mo)', 'Anna'], ['2026-10-06 (Di)', 'Bernd'], ['2026-10-05 (Mo)', 'Anna'], ['2026-10-05 (Mo)', 'Bernd']]);
check('summary: amounts added in cents (0.10 + 0.20 + 0.30)', [$table['column_totals'], $table['total']], [[1 => 0.4, 2 => 2.7], 3.1]);

$table = DrinkSummary::table($orderRows, $drinks, $users, 'date', false, false, true);
check('summary: counts without users, empty columns kept', [array_column($table['drinks'], 'id'), array_column($table['rows'], 'total'), $table['total']], [[1, 2, 3], [1, 2, 4], 7]);
check('summary: column total of an empty column', $table['column_totals'][3], 0);

$table = DrinkSummary::table($orderRows, $drinks, $users, 'weekday', true, false, false);
check('summary: weekday adds up all dates, Sunday first', array_map(function ($row) {
    return [$row['label'], $row['cells'], $row['total']];
}, $table['rows']), [['Mo', [1 => 0.4, 2 => 2.5], 2.9], ['Di', [2 => 0.2], 0.2]]);

$table = DrinkSummary::table([$orderRow('2026-10', 1, 1, 2, '3.00'), $orderRow('2025-10', 1, 1, 1, '1.50')], $drinks, $users, 'month', true, true, false);
check('summary: same month of different years stays apart', array_column($table['rows'], 'label'), ['2026-10', '2025-10']);
check('summary: users not in the list get no row', DrinkSummary::table([$orderRow('2026-10-05', 9, 1, 1, '1.00')], $drinks, $users, 'date', true, true, false)['rows'], []);

echo $failures === 0 ? "OK    $checks checks\n" : "$failures of $checks checks failed\n";
exit($failures === 0 ? 0 : 1);
