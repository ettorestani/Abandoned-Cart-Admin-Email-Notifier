{**
 * Notification log template
 *
 * @author    Ettore Stani
 * @copyright 2024 Ettore Stani
 * @license   http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 *}

<div class="panel">
    <div class="panel-heading">
        <i class="icon-list"></i> {l s='Notification Log' mod='abandonedcartadminnotifier'}
        <span class="badge">{$log_total|intval}</span>
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
                            <th class="text-right">{l s='Cart total' mod='abandonedcartadminnotifier'}</th>
                            <th>{l s='Date Sent' mod='abandonedcartadminnotifier'}</th>
                            <th>{l s='Status' mod='abandonedcartadminnotifier'}</th>
                            <th class="text-center">{l s='Attempts' mod='abandonedcartadminnotifier'}</th>
                            <th>{l s='Error' mod='abandonedcartadminnotifier'}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach from=$notifications item=notification}
                            <tr>
                                <td>
                                    <a href="{$notification.cart_url|escape:'html':'UTF-8'}" title="{l s='View cart' mod='abandonedcartadminnotifier'}">
                                        {$notification.id_cart|intval} <i class="icon-external-link"></i>
                                    </a>
                                </td>
                                <td>{$notification.firstname|escape:'html':'UTF-8'} {$notification.lastname|escape:'html':'UTF-8'}</td>
                                <td>{$notification.email|escape:'html':'UTF-8'}</td>
                                <td class="text-right">{if $notification.total_display}{$notification.total_display|escape:'html':'UTF-8'}{else}-{/if}</td>
                                <td>{$notification.date_sent_display|escape:'html':'UTF-8'}</td>
                                <td>
                                    {if $notification.email_status == 'success'}
                                        <span class="badge badge-success">{l s='Success' mod='abandonedcartadminnotifier'}</span>
                                    {else}
                                        <span class="badge badge-danger">{l s='Failed' mod='abandonedcartadminnotifier'}</span>
                                    {/if}
                                </td>
                                <td class="text-center">{$notification.attempts|intval}</td>
                                <td>
                                    {if $notification.error_message}
                                        <span class="text-danger"><span title="{$notification.error_message|escape:'html':'UTF-8'}">{$notification.error_message|truncate:50|escape:'html':'UTF-8'}</span></span>
                                    {else}
                                        -
                                    {/if}
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
            {if $log_pages > 1}
                <ul class="pagination">
                    {if $log_page > 1}
                        <li><a href="{$log_page_url|escape:'html':'UTF-8'}{($log_page - 1)|intval}">&laquo;</a></li>
                    {/if}
                    {for $p=1 to $log_pages}
                        <li{if $p == $log_page} class="active"{/if}><a href="{$log_page_url|escape:'html':'UTF-8'}{$p|intval}">{$p|intval}</a></li>
                    {/for}
                    {if $log_page < $log_pages}
                        <li><a href="{$log_page_url|escape:'html':'UTF-8'}{($log_page + 1)|intval}">&raquo;</a></li>
                    {/if}
                </ul>
            {/if}
            <form method="post" action="{$form_action|escape:'html':'UTF-8'}" class="panel-footer" onsubmit="return confirm('{l s='Are you sure you want to clear the notification log?' mod='abandonedcartadminnotifier' js=1}');">
                <button type="submit" name="clearNotificationLog" value="1" class="btn btn-default">
                    <i class="icon-trash"></i> {l s='Clear Log' mod='abandonedcartadminnotifier'}
                </button>
            </form>
        {else}
            <div class="alert alert-info">
                {l s='No notifications sent yet.' mod='abandonedcartadminnotifier'}
            </div>
        {/if}
    </div>
</div>
