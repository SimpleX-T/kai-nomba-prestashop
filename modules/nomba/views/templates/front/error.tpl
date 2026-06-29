<div class="nomba-error alert alert-danger">
  {if isset($errors) && $errors}
    {foreach from=$errors item=error}<p>{$error}</p>{/foreach}
  {else}
    <p>{l s='Something went wrong starting your Nomba payment.' mod='nomba'}</p>
  {/if}
  <p><a href="index.php?controller=order&step=1" class="btn btn-secondary">
    {l s='Return to checkout' mod='nomba'}</a></p>
</div>
