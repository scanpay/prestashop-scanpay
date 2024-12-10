<?php
if (!function_exists('fmtDeltaTime')) {
    function fmtDeltaTime($dt)
    {
        $minute = 60;
        $hour = $minute * 60;
        $day = $hour * 24;
        if ($dt <= 1) {
            return '1 second ago';
        } else if ($dt < $minute) {
            return (string)$dt . ' seconds ago';
        } else if ($dt < $minute + 30) {
            return '1 minute ago';
        } else if ($dt < $hour) {
            return (string)round((float)$dt / $minute) . ' minutes ago';
        } else if ($dt < $hour + 30 * $minute) {
            return '1 hour ago';
        } else if ($dt < $day) {
            return (string)round((float)$dt / $hour) . ' hours ago';
        } else if ($dt < $day + 12 * $hour) {
            return '1 day ago';
        } else {
            return (string)round((float)$dt / $day) . ' days ago';
        }
    }
}

if (!function_exists('getPingUrlStatus')) {
    function getPingUrlStatus($mtime)
    {
        $t = time();
        if ($mtime > $t) {
            error_log('last modified time is in the future');
            return;
        }

        $status = '';
        if ($t < $mtime + 900) {
            return 'ok';
        } else if ($t < $mtime + 3600) {
            return 'warning';
        } else if ($mtime > 0) {
            return 'error';
        } else {
            return 'never--pinged';
        }
    }
}

$pingclass = 'scanpay--pingurl--' . getPingUrlStatus($lastpingtime);
$pingdt_desc = fmtDeltaTime(time() - $lastpingtime);

?>
<div class="scanpay--pingurl">
    <div class="scanpay--pingurl--status <?php echo $pingclass ?>">
        <div class="scanpay--pingurl--ok">
            <h4>Ok!</h4>
            <div>
                Received last ping <?php echo $pingdt_desc ?>
            </div>
        </div>
        <div class="scanpay--pingurl--warning">
            <h4>Warning!</h4>
            <div>
                Received last ping <?php echo $pingdt_desc ?>, please check that the URL above matches the URL set in
                <a href="https://dashboard.scanpay.dk/settings/api">Scanpay dashboard</a>
            </div>
        </div>
        <div class="scanpay--pingurl--error">
            <h4>Error!</h4>
            <div>
                Received last ping <?php echo $pingdt_desc ?>, please check that the URL above matches the URL set in
                <a href="https://dashboard.scanpay.dk/settings/api">Scanpay dashboard</a>
                and check your error logs.
            </div>
        </div>
        <div class="scanpay--pingurl--never--pinged">
            <h4>Awaiting pings!</h4>
            <div>
                Never received any pings from Scanpay, please check that the URL above matches the URL set in
                <a href="https://dashboard.scanpay.dk/settings/api">Scanpay dashboard</a>
                and check your error logs.
            </div>
        </div>
    </div>
</div>
<div>
    <input type="text" id="scanpay--pingurl--input" value="<?php echo $pingurl ?>" readonly>
</div>
