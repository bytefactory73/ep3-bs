<?php

namespace Base\Service;

final class MoneyCalculator
{
    private static $serializationConfigured = false;

    public static function roundMoney($amount, $precision = 2)
    {
        if (!self::$serializationConfigured) {
            ini_set('serialize_precision', '-1');
            self::$serializationConfigured = true;
        }
        return round((float)$amount, (int)$precision);
    }

    public static function floorMoney($amount, $precision = 2)
    {
        $precision = (int)$precision;
        $factor = pow(10, $precision);
        return floor(((float)$amount) * $factor) / $factor;
    }

    public static function splitAwayFromZero($amount, $parts)
    {
        $parts = (int)$parts;
        if ($parts <= 0) {
            return self::roundMoney($amount, 2);
        }

        $absoluteAmount = abs((float)$amount);
        $absoluteCents = (int)ceil($absoluteAmount * 100);
        $shareCents = (int)ceil($absoluteCents / $parts);
        $share = self::fromCents($shareCents);

        return ((float)$amount) < 0 ? (0.0 - $share) : $share;
    }

    public static function toCents($amount)
    {
        return (int)round(self::roundMoney($amount, 2) * 100);
    }

    public static function fromCents($cents)
    {
        return self::roundMoney(((float)$cents) / 100, 2);
    }

    public static function add($left, $right)
    {
        return self::roundMoney(((float)$left) + ((float)$right), 2);
    }

    public static function subtract($left, $right)
    {
        return self::roundMoney(((float)$left) - ((float)$right), 2);
    }

    public static function multiply($amount, $factor)
    {
        return self::roundMoney(((float)$amount) * ((float)$factor), 2);
    }
}