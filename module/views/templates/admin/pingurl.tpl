{*
 * @author    Scanpay <contact@scanpay.dk>
 * @copyright Scanpay ApS. All rights reserved.
 * @license   https://opensource.org/licenses/MIT MIT License
 *}

<div class="scanpay--pingurl">
    <div class="scanpay--pingurl--status {$pingclass}">
        <div class="scanpay--pingurl--ok">
            <h4>Ok!</h4>
            <div>
                Received last ping {$pingdt_desc}
            </div>
        </div>
        <div class="scanpay--pingurl--warning">
            <h4>Warning!</h4>
            <div>
                Received last ping {$pingdt_desc}, please check that the URL above matches the URL set in
                <a href="https://dashboard.scanpay.dk/settings/api">Scanpay dashboard</a>
            </div>
        </div>
        <div class="scanpay--pingurl--error">
            <h4>Error!</h4>
            <div>
                Received last ping {$pingdt_desc}, please check that the URL above matches the URL set in
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
    <input type="text" id="scanpay--pingurl--input" value="{$pingurl}" readonly>
</div>
