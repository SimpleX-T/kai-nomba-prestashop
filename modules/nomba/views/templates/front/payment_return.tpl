<div class="nomba-payment-return">
  {if isset($nomba_status) && $nomba_status == 'SUCCESS'}
    <div class="alert alert-success">
      <p>{l s='Your Nomba payment was successful. Thank you!' mod='nomba'}</p>
      {if isset($nomba_order_reference)}
        <p><small>{l s='Reference: ' mod='nomba'}{$nomba_order_reference}</small></p>
      {/if}
    </div>
    <p><a href="index.php?controller=history" class="btn btn-primary">
      {l s='View your orders' mod='nomba'}</a></p>
  {elseif isset($nomba_status) && $nomba_status == 'ORDER_FAILED'}
    <div class="alert alert-warning">
      <p>{l s='Your Nomba payment was successful, but we could not create your order. Please contact support and provide your reference.' mod='nomba'}</p>
      {if isset($nomba_order_reference)}
        <p><small>{l s='Reference: ' mod='nomba'}{$nomba_order_reference}</small></p>
      {/if}
    </div>
    <p><a href="index.php?controller=contact" class="btn btn-primary">
      {l s='Contact support' mod='nomba'}</a></p>
  {elseif isset($nomba_status) && $nomba_status == 'PENDING'}
    <div class="alert alert-info">
      <p>{l s='Your Nomba payment is being confirmed. We will email you shortly.' mod='nomba'}</p>
      {if isset($nomba_order_reference)}
        <p><small>{l s='Reference: ' mod='nomba'}{$nomba_order_reference}</small></p>
      {/if}
    </div>
    <p><a href="index.php?controller=order&step=1" class="btn btn-secondary">
      {l s='Return to checkout' mod='nomba'}</a></p>
  {else}
    <div class="alert alert-warning">
      <p>{l s='We could not confirm your Nomba payment. If you were charged, contact support.' mod='nomba'}</p>
      {if isset($nomba_order_reference)}
        <p><small>{l s='Reference: ' mod='nomba'}{$nomba_order_reference}</small></p>
      {/if}
    </div>
    <p><a href="index.php?controller=order&step=1" class="btn btn-secondary">
      {l s='Try again' mod='nomba'}</a></p>
  {/if}
</div>
