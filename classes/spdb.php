<?php

class SPDB_Seq
{
    const TABLE = _DB_PREFIX_ . 'scanpay_seq';

    static function mktable()
    {
        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
            `shopid` BIGINT UNSIGNED NOT NULL PRIMARY KEY UNIQUE,
            `seq`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `mtime`  BIGINT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        );
    }

    private static function mkrow($shopid)
    {
        $shopid = (int)$shopid;
        return Db::getInstance()->execute(
            'INSERT INTO ' . self::TABLE . "
            (`shopid`, `seq`)
            VALUES ($shopid, 0)
            ON DUPLICATE KEY UPDATE `mtime`=`mtime`"
        );
    }
    /* Seq */
    static function load($shopid)
    {
        $shopid = (int)$shopid;
        /* Load the current seq for the shop */
        $seqobj = Db::getInstance()->getRow(
            'SELECT * FROM ' . self::TABLE . "
            WHERE `shopid` = $shopid"
        );
        if (!$seqobj) {
            if (!self::mkrow($shopid)) {
                throw new \Exception('Unable to make row');
            }
            return self::load($shopid);
        }
        return $seqobj;
    }

    static function save($shopid, $seq, $updatemtime = true)
    {
        $shopid = (int)$shopid;
        $seq = (int)$seq;
        $now = (int)time();
        if (!$updatemtime) {
            return Db::getInstance()->execute(
                'UPDATE ' . self::TABLE . "
                SET
                `seq`   = $seq
                WHERE `shopid` = $shopid AND `seq` < $seq"
            );
        }
        return Db::getInstance()->execute(
            'UPDATE ' . self::TABLE . "
            SET
            `seq`   = $seq,
            `mtime` = $now
            WHERE `shopid` = $shopid AND `seq` < $seq"
        );
    }

    static function updatemtime($shopid)
    {
        $shopid = (int)$shopid;
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

    static function mktable()
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

    static function insert($cartid, $shopid)
    {
        $shopid = (int)$shopid;
        $cartid = (int)$cartid;
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
            if ((float)$row['authorized'] > 0) {
                return false;
            }
        }
        return true;
    }

    private static function getcurnum($str)
    {
        $num = explode(' ', $str)[0];
        $parts = explode('.', $num);
        $n = count($parts);
        if ($n !== 1 && $n !== 2) {
            throw new \Exception('invalid money value received from Scanpay ' . $str);
        }
        foreach ($parts as $p) {
            for ($i = 0; $i < strlen($p); $i++) {
                if ($p[$i] < '0' || $p[$i] > '9') {
                    throw new \Exception('invalid money value received from Scanpay ' . $str);
                }
            }
        }
        return $num;
    }

    static function load($cartid)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM ' . self::TABLE . "
            WHERE `cartid` = $cartid"
        );
    }

    static function update($cartid, $shopid, $change)
    {
        $cartid = (int)$cartid;
        $shopid = (int)$shopid;
        $trnid = (int)$change['id'];
        $orderid = (int)$change['orderid'];
        $rev   = (int)$change['rev'];
        $nacts = (int)count($change['acts']);
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
