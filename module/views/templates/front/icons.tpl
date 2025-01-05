{**
 * @author    Scanpay <contact@scanpay.dk>
 * @copyright Scanpay ApS. All rights reserved.
 * @license   https://opensource.org/licenses/MIT MIT License
 *}

<div class="scanpay-icons">
    {foreach from=$icons item=ico}
        <img src="{$ico|escape:'html':'UTF-8'}" class="scanpay-ico">
    {/foreach}
</div>
