<?php
/**
 * @author    Scanpay <contact@scanpay.dk>
 * @copyright Scanpay ApS. All rights reserved.
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SPDB_Seq
{
    const TABLE = _DB_PREFIX_ . 'scanpay_seq';

    public static function mktable(): bool
    {
        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
            `shopid` BIGINT UNSIGNED NOT NULL PRIMARY KEY UNIQUE,
            `seq`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `mtime`  BIGINT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        );
    }

    private static function mkrow(int $shopid): bool
    {
        return Db::getInstance()->execute(
            'INSERT INTO ' . self::TABLE . "
            (`shopid`, `seq`)
            VALUES ($shopid, 0)
            ON DUPLICATE KEY UPDATE `mtime`=`mtime`"
        );
    }

    /* Seq */
    public static function load(int $shopid): array
    {
        $seqobj = Db::getInstance()->getRow(
            'SELECT * FROM ' . self::TABLE . "
            WHERE `shopid` = $shopid"
        );
        if (!$seqobj) {
            if (!self::mkrow($shopid)) {
                throw new Exception('Unable to make row');
            }

            return self::load($shopid);
        }

        return $seqobj;
    }

    public static function save(int $shopid, int $seq, bool $update = true): bool
    {
        if (!$update) {
            return Db::getInstance()->execute(
                'UPDATE ' . self::TABLE . "
                SET `seq` = $seq
                WHERE `shopid` = $shopid AND `seq` < $seq"
            );
        }
        $now = time();
        return Db::getInstance()->execute(
            'UPDATE ' . self::TABLE . "
            SET `seq` = $seq, `mtime` = $now
            WHERE `shopid` = $shopid AND `seq` < $seq"
        );
    }

    public static function updatemtime(int $shopid): void
    {
        $now = time();
        Db::getInstance()->execute(
            'UPDATE ' . self::TABLE . "
            SET `mtime` = $now
            WHERE `shopid` = $shopid"
        );
    }
}

class SPDB_Carts
{
    const TABLE = _DB_PREFIX_ . 'scanpay_carts';

    public static function mktable(): bool
    {
        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
            `cartid`     BIGINT UNSIGNED NOT NULL PRIMARY KEY UNIQUE,
            `shopid`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `trnid`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `orderid`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `rev`        BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `nacts`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `authorized` decimal(20,6) NOT NULL DEFAULT "0.00",
            `captured`   decimal(20,6) NOT NULL DEFAULT "0.00",
            `refunded`   decimal(20,6) NOT NULL DEFAULT "0.00",
            `voided`     decimal(20,6) NOT NULL DEFAULT "0.00"
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        );
    }

    public static function insert(int $cartid, int $shopid): bool
    {
        $inserted = Db::getInstance()->execute(
            'INSERT IGNORE INTO ' . self::TABLE .
                "(`cartid`, `shopid`)
            VALUES ($cartid, $shopid)"
        );
        if (!$inserted) {
            $row = Db::getInstance()->getRow(
                'SELECT * FROM ' . self::TABLE . "
                WHERE `cartid` = $cartid"
            );
            if ((float) $row['authorized'] > 0) {
                return false;
            }
        }

        return true;
    }

    private static function getcurnum(string $str): string
    {
        $num = explode(' ', $str)[0];
        $parts = explode('.', $num);
        $n = count($parts);
        if ($n !== 1 && $n !== 2) {
            throw new Exception('invalid money value received from Scanpay ' . $str);
        }
        foreach ($parts as $p) {
            for ($i = 0; $i < strlen($p); ++$i) {
                if ($p[$i] < '0' || $p[$i] > '9') {
                    throw new Exception('invalid money value received from Scanpay ' . $str);
                }
            }
        }

        return $num;
    }

    public static function load(int $cartid): bool
    {
        return Db::getInstance()->getRow('SELECT * FROM ' . self::TABLE . " WHERE `cartid` = $cartid");
    }

    public static function update(int $cartid, int $shopid, array $change): void
    {
        $trnid = (int) $change['id'];
        $orderid = (int) $change['orderid'];
        $rev = (int) $change['rev'];
        $nacts = (int) count($change['acts']);
        $authorized = self::getcurnum($change['totals']['authorized']);
        $captured = self::getcurnum($change['totals']['captured']);
        $refunded = self::getcurnum($change['totals']['refunded']);
        $voided = '0.00'; /* self::getcurnum($change['totals']['voided']); */
        Db::getInstance()->execute(
            'UPDATE ' . self::TABLE . "
            SET
            `trnid`      = $trnid,
            `orderid`    = $orderid,
            `rev`        = $rev,
            `nacts`      = $nacts,
            `authorized` = $authorized,
            `captured`   = $captured,
            `refunded`   = $refunded,
            `voided`     = $voided
            WHERE `cartid` = $cartid AND `shopid` = $shopid AND `rev` < $rev AND `nacts` <= $nacts"
        );
    }
}
