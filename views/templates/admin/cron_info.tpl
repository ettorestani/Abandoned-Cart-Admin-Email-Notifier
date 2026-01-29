{**
 * Cron job information template
 *
 * @author    Ettore Stani
 * @copyright 2024 Ettore Stani
 * @license   http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 *}

<div class="panel">
    <div class="panel-heading">
        <i class="icon-cogs"></i> {l s='Cron Job Configuration' mod='abandonedcartadminnotifier'}
    </div>
    <div class="panel-body">
        <div class="alert alert-info">
            <p><strong>{l s='Cron URL:' mod='abandonedcartadminnotifier'}</strong></p>
            <code>{$cron_url|escape:'html':'UTF-8'}</code>
        </div>

        <div class="alert alert-warning">
            <p><strong>{l s='Important:' mod='abandonedcartadminnotifier'}</strong></p>
            <p>{l s='Configure your server cron to call this URL once every 24 hours. Example crontab entry:' mod='abandonedcartadminnotifier'}</p>
            <code>0 2 * * * wget -q -O /dev/null "{$cron_url|escape:'html':'UTF-8'}"</code>
        </div>

        <div class="well">
            <p><strong>{l s='Secure Token:' mod='abandonedcartadminnotifier'}</strong></p>
            <code>{$secure_token|escape:'html':'UTF-8'}</code>
            <p class="help-block">{l s='Keep this token secret. It protects the cron job from unauthorized access.' mod='abandonedcartadminnotifier'}</p>
        </div>
    </div>
</div>
