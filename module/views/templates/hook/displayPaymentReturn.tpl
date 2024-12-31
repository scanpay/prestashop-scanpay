{**
 * @author    Scanpay <contact@scanpay.dk>
 * @copyright Scanpay ApS. All rights reserved.
 * @license   https://opensource.org/licenses/MIT MIT License
 *}

<section id="scanpay-displayPaymentReturn">
  <p>
    <h3>{l s='Payment Successful' mod='scanpay' }</h3>
  </p>
  <p>
    {l s='Your payment of %auth% was successful, and the amount has been reserved on your %card% ending in %last4%. The reserved amount will be captured once your order has been fully processed.' js="true" mod='scanpay' sprintf=['%auth%' => $auth, '%last4%' => $last4, '%card%' => $brand]}
  </p>
  <p>
    {l s='This transaction was securely handled by Scanpay. Your transaction reference is %trnid%.' js="true" mod='scanpay' sprintf=['%trnid%' => $trnid]}
  </p>
  <p>
    <a class="btn btn-primary" href="{$link->getPageLink('history', true)|escape:'html':'UTF-8'}">
      {l s='Go to orders' mod='scanpay'}
    </a>
  </p>
</section>
