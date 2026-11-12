{**
 * Cron status template: last run and stale-cron warning
 *
 * @author    Ettore Stani
 * @copyright 2024 Ettore Stani
 * @license   http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 *}

<div class="panel">
    <div class="panel-heading">
        <i class="icon-time"></i> {l s='Cron status' mod='abandonedcartadminnotifier'}
    </div>
    {if !$cron_has_run}
        <div class="alert alert-warning">
            {l s='The cron job has never run since this check was added. Set it up on the server with the URL shown below.' mod='abandonedcartadminnotifier'}
        </div>
    {else}
        <div class="alert {if $cron_is_stale}alert-danger{else}alert-success{/if}">
            {if $cron_is_stale}
                <p><strong>{l s='The cron job has not run for more than %d hours: check it on the server.' sprintf=[$cron_alert_hours] mod='abandonedcartadminnotifier'}</strong></p>
            {/if}
            <p>
                {l s='Last run:' mod='abandonedcartadminnotifier'}
                <strong>{$cron_last_run|escape:'html':'UTF-8'}</strong>
                ({l s='%s ago' sprintf=[$cron_idle_for] mod='abandonedcartadminnotifier'}, {$cron_source|escape:'html':'UTF-8'})
            </p>
            <p>
                {l s='Carts processed:' mod='abandonedcartadminnotifier'} {$cron_processed|intval}
                &middot; {l s='Notifications sent:' mod='abandonedcartadminnotifier'} {$cron_sent|intval}
                &middot; {l s='Errors:' mod='abandonedcartadminnotifier'} {$cron_errors|intval}
            </p>
            {if $cron_message}
                <p>{$cron_message|escape:'html':'UTF-8'}</p>
            {/if}
        </div>
    {/if}
    <form method="post" action="{$form_action|escape:'html':'UTF-8'}" class="panel-footer">
        <button type="submit" name="sendTestEmail" value="1" class="btn btn-default">
            <i class="icon-envelope"></i> {l s='Send a test email' mod='abandonedcartadminnotifier'}
        </button>
        <span class="help-block">{l s='Sends the notification with a sample cart to the saved recipients. Nothing is logged.' mod='abandonedcartadminnotifier'}</span>
    </form>
</div>
