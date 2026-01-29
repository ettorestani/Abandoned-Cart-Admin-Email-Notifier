{**
 * Notification log template
 *
 * @author    Ettore Stani
 * @copyright 2024 Ettore Stani
 * @license   http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 *}

<div class="panel">
    <div class="panel-heading">
        <i class="icon-list"></i> {l s='Notification Log (Last 50)' mod='abandonedcartadminnotifier'}
    </div>
    <div class="panel-body">
        {if $notifications && count($notifications) > 0}
            <div class="table-responsive">
                <table class="table table-striped table-bordered">
                    <thead>
                        <tr>
                            <th>{l s='Cart ID' mod='abandonedcartadminnotifier'}</th>
                            <th>{l s='Customer' mod='abandonedcartadminnotifier'}</th>
                            <th>{l s='Customer Email' mod='abandonedcartadminnotifier'}</th>
                            <th>{l s='Date Sent' mod='abandonedcartadminnotifier'}</th>
                            <th>{l s='Status' mod='abandonedcartadminnotifier'}</th>
                            <th>{l s='Error' mod='abandonedcartadminnotifier'}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach from=$notifications item=notification}
                            <tr>
                                <td>{$notification.id_cart|intval}</td>
                                <td>{$notification.firstname|escape:'html':'UTF-8'} {$notification.lastname|escape:'html':'UTF-8'}</td>
                                <td>{$notification.email|escape:'html':'UTF-8'}</td>
                                <td>{$notification.date_sent|escape:'html':'UTF-8'}</td>
                                <td>
                                    {if $notification.email_status == 'success'}
                                        <span class="badge badge-success">{l s='Success' mod='abandonedcartadminnotifier'}</span>
                                    {else}
                                        <span class="badge badge-danger">{l s='Failed' mod='abandonedcartadminnotifier'}</span>
                                    {/if}
                                </td>
                                <td>
                                    {if $notification.error_message}
                                        <span class="text-danger">{$notification.error_message|escape:'html':'UTF-8'|truncate:50}</span>
                                    {else}
                                        -
                                    {/if}
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
            <div class="panel-footer">
                <a href="{$clear_log_url|escape:'html':'UTF-8'}" class="btn btn-default" onclick="return confirm('{l s='Are you sure you want to clear the notification log?' mod='abandonedcartadminnotifier'}');">
                    <i class="icon-trash"></i> {l s='Clear Log' mod='abandonedcartadminnotifier'}
                </a>
            </div>
        {else}
            <div class="alert alert-info">
                {l s='No notifications sent yet.' mod='abandonedcartadminnotifier'}
            </div>
        {/if}
    </div>
</div>
