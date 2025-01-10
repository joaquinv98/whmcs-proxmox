{* clientarea.tpl *}

<!-- Include necessary stylesheets and scripts -->
<link rel="stylesheet" href="{$WEB_ROOT}/templates/{$template}/css/bootstrap.min.css">
<script src="{$WEB_ROOT}/templates/{$template}/js/jquery.min.js"></script>
<script src="{$WEB_ROOT}/templates/{$template}/js/bootstrap.min.js"></script>

{if $errorMessage}
    <div class="alert alert-danger">{$errorMessage}</div>
{/if}

<div class="container">
    <h2>Server Details</h2>
    <table class="table table-bordered">
        <tr>
            <th>Status</th>
            <td>{$vmStatus}</td>
        </tr>
        <tr>
            <th>CPU Usage</th>
            <td>{$cpuUsage}%</td>
        </tr>
        <tr>
            <th>RAM Usage</th>
            <td>{$ramUsage} MB / {$ramTotal} MB</td>
        </tr>
        <tr>
            <th>Public IP</th>
            <td>{$publicIP}</td>
        </tr>
    </table>

    <div class="btn-group" role="group" aria-label="Server Controls">
        <form method="post" action="clientarea.php?action=productdetails&id={$serviceid}">
            <input type="hidden" name="modop" value="custom">
            <input type="hidden" name="a" value="Start">
            <button type="submit" class="btn btn-success">Start VM</button>
        </form>
        <form method="post" action="clientarea.php?action=productdetails&id={$serviceid}">
            <input type="hidden" name="modop" value="custom">
            <input type="hidden" name="a" value="Stop">
            <button type="submit" class="btn btn-warning">Stop VM</button>
        </form>
        <form method="post" action="clientarea.php?action=productdetails&id={$serviceid}">
            <input type="hidden" name="modop" value="custom">
            <input type="hidden" name="a" value="Reboot">
            <button type="submit" class="btn btn-primary">Reboot VM</button>
        </form>
        <form method="post" action="clientarea.php?action=productdetails&id={$serviceid}" onsubmit="return confirm('Are you sure you want to reinstall your server? This action cannot be undone.');">
            <input type="hidden" name="modop" value="custom">
            <input type="hidden" name="a" value="Reinstall">
            <button type="submit" class="btn btn-danger">Reinstall Server</button>
        </form>
    </div>
</div>

<!-- No VNC console scripts or elements are included -->
