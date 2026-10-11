<?php

namespace Drinks\Accounting;

use Base\Service\MoneyCalculator;

/**
 * Table of the Gesamtauswertung (pure, no DB): orders per period, user and drink with row and
 * column totals. Amounts are added up in cents.
 */
class DrinkSummary
{
    const WEEKDAY_NAMES = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

    /**
     * $orderRows: ['grp' (period label; a date for 'date' and 'weekday'), 'user_id', 'drink_id',
     * 'quantity', 'total_amount']; $drinks: ['id', 'name', ...] in column order; $userAliases:
     * [uid => alias] in row order (only these users get rows when $showUsers).
     * $group 'weekday' adds up all dates per weekday (Sunday first), 'date' labels add the weekday.
     * Without $showEmptyColumns only drinks with orders get a column.
     * Returns ['drinks' => columns, 'rows' => [['label', 'user' (alias or null), 'cells' =>
     * [drink id => value, only non-zero], 'total']], 'column_totals' => [drink id => value],
     * 'total']; values are counts, or euros with $amountMode.
     */
    public static function table(array $orderRows, array $drinks, array $userAliases, $group, $amountMode, $showUsers, $showEmptyColumns)
    {
        // [period key => ['label', 'users' => [uid => [drink id => count or cents]]]]
        $periods = [];
        $drinkTotals = [];
        foreach ($orderRows as $row) {
            $value = $amountMode ? MoneyCalculator::toCents($row['total_amount']) : (int)$row['quantity'];
            if ($value == 0) {
                continue;
            }
            list($key, $label) = self::period((string)$row['grp'], $group);
            if ($key === null) {
                continue;
            }
            $uid = (int)$row['user_id'];
            $drinkId = (int)$row['drink_id'];
            if (!isset($periods[$key])) {
                $periods[$key] = ['label' => $label, 'users' => []];
            }
            $periods[$key]['users'][$uid][$drinkId] = (isset($periods[$key]['users'][$uid][$drinkId]) ? $periods[$key]['users'][$uid][$drinkId] : 0) + $value;
            $drinkTotals[$drinkId] = (isset($drinkTotals[$drinkId]) ? $drinkTotals[$drinkId] : 0) + $value;
        }
        if ($group === 'weekday') {
            ksort($periods);
        } else {
            krsort($periods);
        }

        $columns = array_values(array_filter($drinks, function ($drink) use ($drinkTotals, $showEmptyColumns) {
            return $showEmptyColumns || !empty($drinkTotals[(int)$drink['id']]);
        }));
        $columnIds = array_map(function ($drink) {
            return (int)$drink['id'];
        }, $columns);

        $rows = [];
        $columnTotals = array_fill_keys($columnIds, 0);
        $addRow = function ($label, $user, array $usersCells) use (&$rows, &$columnTotals, $columnIds, $amountMode) {
            $cells = [];
            foreach ($usersCells as $userCells) {
                foreach ($columnIds as $drinkId) {
                    if (!empty($userCells[$drinkId])) {
                        $cells[$drinkId] = (isset($cells[$drinkId]) ? $cells[$drinkId] : 0) + $userCells[$drinkId];
                    }
                }
            }
            if (empty($cells)) {
                return;
            }
            foreach ($cells as $drinkId => $value) {
                $columnTotals[$drinkId] += $value;
            }
            $rows[] = ['label' => $label, 'user' => $user, 'cells' => self::values($cells, $amountMode), 'total' => self::value(array_sum($cells), $amountMode)];
        };
        foreach ($periods as $period) {
            if ($showUsers) {
                foreach ($userAliases as $uid => $alias) {
                    if (isset($period['users'][$uid])) {
                        $addRow($period['label'], $alias, [$period['users'][$uid]]);
                    }
                }
            } else {
                $addRow($period['label'], null, $period['users']);
            }
        }

        return [
            'drinks' => $columns,
            'rows' => $rows,
            'column_totals' => self::values($columnTotals, $amountMode),
            'total' => self::value(array_sum($columnTotals), $amountMode),
        ];
    }

    /**
     * [key, label] of the period of a row label, [null, null] for an unusable weekday date.
     */
    private static function period($label, $group)
    {
        $date = \DateTime::createFromFormat('!Y-m-d', substr($label, 0, 10));
        if ($group === 'weekday') {
            return $date ? [(int)$date->format('w'), self::WEEKDAY_NAMES[(int)$date->format('w')]] : [null, null];
        }
        if ($group === 'date' && $date) {
            return [$label, $label . ' (' . self::WEEKDAY_NAMES[(int)$date->format('w')] . ')'];
        }
        return [$label, $label];
    }

    private static function value($value, $amountMode)
    {
        return $amountMode ? MoneyCalculator::fromCents($value) : (int)$value;
    }

    private static function values(array $values, $amountMode)
    {
        return array_map(function ($value) use ($amountMode) {
            return self::value($value, $amountMode);
        }, $values);
    }
}
