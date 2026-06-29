<div class="panel nomba-refund-panel">
  <div class="panel-heading">{l s='Nomba Payment' mod='nomba'}</div>
  <div class="panel-body">
    <table class="table">
      <tr>
        <td>{l s='Transaction ID' mod='nomba'}</td>
        <td>{$nomba_transaction.transaction_id}</td>
      </tr>
      <tr>
        <td>{l s='Order Reference' mod='nomba'}</td>
        <td>{$nomba_transaction.order_reference}</td>
      </tr>
      <tr>
        <td>{l s='Amount' mod='nomba'}</td>
        <td>{$nomba_transaction.amount|escape} {$nomba_transaction.currency|escape}</td>
      </tr>
      <tr>
        <td>{l s='Refunded' mod='nomba'}</td>
        <td>{$nomba_refunded_amount|escape} {$nomba_transaction.currency|escape}</td>
      </tr>
      <tr>
        <td>{l s='Remaining' mod='nomba'}</td>
        <td>{$nomba_remaining_amount|escape} {$nomba_transaction.currency|escape}</td>
      </tr>
    </table>

    {if $nomba_remaining_amount > 0}
      <form method="post" action="" class="form-inline" style="margin-top: 15px;">
        <div class="form-group">
          <label for="nomba_refund_amount">{l s='Refund amount (leave empty for full)' mod='nomba'}</label>
          <input type="number" step="0.01" min="0.01" max="{$nomba_remaining_amount|escape}"
                 name="nomba_refund_amount" id="nomba_refund_amount" class="form-control"
                 placeholder="{$nomba_remaining_amount|escape}">
        </div>
        <button type="submit" name="submitNombaRefund" class="btn btn-primary">
          {l s='Refund via Nomba' mod='nomba'}
        </button>
      </form>
    {else}
      <p class="alert alert-info">{l s='This transaction has been fully refunded.' mod='nomba'}</p>
    {/if}
  </div>
</div>