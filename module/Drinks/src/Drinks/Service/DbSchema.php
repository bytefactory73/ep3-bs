<?php

namespace Drinks\Service;

/**
 * Schema probes for optional Drinks tables/columns (older installations may lack them).
 * Results are cached per request. Table and column names are internal constants, never input.
 */
class DbSchema
{
    private static $cache = [];

    public static function hasTable($dbAdapter, $table)
    {
        return self::probe('table:' . $table, function () use ($dbAdapter, $table) {
            return $dbAdapter->query("SHOW TABLES LIKE '" . $table . "'", [])->current();
        });
    }

    public static function hasColumn($dbAdapter, $table, $column)
    {
        return self::probe('column:' . $table . '.' . $column, function () use ($dbAdapter, $table, $column) {
            return $dbAdapter->query('SHOW COLUMNS FROM ' . $table . " LIKE '" . $column . "'", [])->current();
        });
    }

    /**
     * True if both drink_orders and drink_deposits carry transfer_reference.
     */
    public static function hasTransferReferenceColumns($dbAdapter)
    {
        return self::hasColumn($dbAdapter, 'drink_orders', 'transfer_reference')
            && self::hasColumn($dbAdapter, 'drink_deposits', 'transfer_reference');
    }

    public static function hasTeamEventClosedColumn($dbAdapter)
    {
        return self::hasColumn($dbAdapter, 'drinks_teamevents', 'closed');
    }

    /**
     * Switch the shared connection to utf8mb4 once (emoji in PayPal notes and comments).
     */
    public static function ensureUtf8mb4($dbAdapter)
    {
        if (!empty(self::$cache['utf8mb4'])) {
            return;
        }
        try {
            $dbAdapter->query('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci', []);
            self::$cache['utf8mb4'] = true;
        } catch (\Throwable $e) {
            // Keep legacy behavior if the DB user cannot run SET NAMES explicitly.
        }
    }

    private static function probe($key, callable $query)
    {
        if (!isset(self::$cache[$key])) {
            try {
                self::$cache[$key] = (bool)$query();
            } catch (\Throwable $e) {
                self::$cache[$key] = false;
            }
        }
        return self::$cache[$key];
    }
}
