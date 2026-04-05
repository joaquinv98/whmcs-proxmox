<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

// Use WHMCS's database facade
use WHMCS\Database\Capsule;
    // Import necessary classes
    use WHMCS\ClientArea;

function proxmox_custom_MetaData()
{
    return [
        'DisplayName' => 'Proxmox Custom Provisioning Module with API Token',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
    ];
}

function proxmox_custom_ConfigOptions()
{
    return [
        'TemplateID' => [
            'Type' => 'text',
            'Size' => '5',
            'Default' => '100',
            'Description' => 'The VMID of the template VM to clone',
        ],
        'Node' => [
            'Type' => 'text',
            'Size' => '20',
            'Default' => 'proxmox-node',
            'Description' => 'The Proxmox node name',
        ],
        // CPU Cores
        'CPUCores' => [
            'Type' => 'dropdown',
            'Options' => [
                '1' => '1 Core',
                '2' => '2 Cores',
                '4' => '4 Cores',
                '8' => '8 Cores',
            ],
            'Default' => '2',
            'Description' => 'Number of CPU cores',
        ],
        // RAM
        'RAM' => [
            'Type' => 'dropdown',
            'Options' => [
                '2048' => '2 GB',
                '4096' => '4 GB',
                '8192' => '8 GB',
                '16384' => '16 GB',
            ],
            'Default' => '2048',
            'Description' => 'Amount of RAM in MB',
        ],
        // Disk Size
        'DiskSize' => [
            'Type' => 'dropdown',
            'Options' => [
                '20' => '20 GB',
                '40' => '40 GB',
                '80' => '80 GB',
                '160' => '160 GB',
            ],
            'Default' => '20',
            'Description' => 'Disk size in GB',
        ],
        'NetworkSpeed' => [
            'Type' => 'dropdown',
            'Options' => [
                '10'    => '10 Mbps',
                '20'    => '20 Mbps',
                '100'   => '100 Mbps',
                '500'   => '500 Mbps',
                '1000' => '1 Gbps',
            ],
            'Default' => '20',
            'Description' => 'Network speed in Mbps',
        ],
        'EnableConsole' => [
            'Type' => 'yesno',
            'Description' => 'Allow clients to access the VM console from the client area',
        ],
        'HostnameSuffix' => [
            'Type' => 'text',
            'Size' => '30',
            'Default' => '.vps.example.com',
            'Description' => 'Hostname suffix for VMs, e.g. .vps.example.com',
        ],
    ];
}

function proxmox_custom_TestConnection(array $params)
{
    $hostname       = $params['serverhostname'];
    $apiTokenID     = $params['serverusername'];
    $apiTokenSecret = $params['serverpassword'];

    if (!preg_match("~^https?://~i", $hostname)) {
         $hostname = "https://" . $hostname;
    }

    $url = rtrim($hostname, '/') . "/api2/json/version";

    try {
        $headers = [
            "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false, // NOT recommended for production.
            CURLOPT_SSL_VERIFYHOST => false, // NOT recommended for production.
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            logModuleCall(
                'proxmox_custom',
                __FUNCTION__,
                ['URL' => $url, 'TokenID' => $apiTokenID, 'Secret' => '***'],
                ['cURL Error' => $curlError],
                null
            );
            return ['error' => 'Connection failed. cURL Error: ' . $curlError];
        }

        $data = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            if (isset($data['data']['version'])) {
                $version = $data['data']['version'];
                $release = isset($data['data']['release']) ? $data['data']['release'] : 'N/A';
                return [
                    'success' => true,
                    'message' => "Connection Successful! Proxmox VE {$version} ({$release}) detected.",
                ];
            } else {
                logModuleCall(
                    'proxmox_custom',
                    __FUNCTION__,
                    ['URL' => $url, 'TokenID' => $apiTokenID, 'Secret' => '***'],
                    ['HTTP Code' => $httpCode, 'Response' => $response],
                    'Unexpected response format from Proxmox API /version endpoint.'
                );
                return ['error' => 'Connection successful, but failed to parse version information. Check Module Log.'];
            }
        } elseif ($httpCode == 401) {
            return ['error' => 'Authentication failed. Check API Token ID and Secret. (HTTP 401 Unauthorized)'];
        } else {
            $errorMessage = "Connection failed with HTTP Status Code: {$httpCode}.";
             if (is_array($data) && isset($data['message'])) {
                 $errorMessage .= " API Message: " . $data['message'];
             } elseif (!empty($response)) {
                 $errorMessage .= " Raw Response: " . (strlen($response) > 150 ? substr($response, 0, 150) . '...' : $response);
             }
             logModuleCall(
                 'proxmox_custom',
                 __FUNCTION__,
                 ['URL' => $url, 'TokenID' => $apiTokenID, 'Secret' => '***'],
                 ['HTTP Code' => $httpCode, 'Response' => $response],
                 $errorMessage
             );
            return ['error' => $errorMessage];
        }

    } catch (Exception $e) {
        logModuleCall(
            'proxmox_custom',
            __FUNCTION__,
            ['URL' => $url, 'TokenID' => $apiTokenID, 'Secret' => '***'],
            $e->getMessage(),
            $e->getTraceAsString()
        );
        return ['error' => 'An unexpected error occurred: ' . $e->getMessage()];
    }
}

function proxmox_custom_CreateAccount(array $params)
{
    // No need to set a long time limit — we return quickly now
    $serviceId      = $params['serviceid'];
    $serverId       = $params['serverid'];
    $serverHostname = $params['serverhostname'];
    $apiTokenID     = $params['serverusername'];
    $apiTokenSecret = $params['serverpassword'];
    $node           = $params['configoption2'];
    $password       = $params['password'];
    $userId         = $params['userid'];

    $cpuCores     = proxmox_custom_GetOption($params, 'CPUCores', 1);
    $ramGB        = proxmox_custom_GetOption($params, 'RAM', 1);
    $diskSizeGB   = proxmox_custom_GetOption($params, 'DiskSize', 20);
    $networkSpeed = proxmox_custom_GetOption($params, 'NetworkSpeed', '100');
    $templateId   = proxmox_custom_GetOption($params, 'TemplateID', '101');

    $ramMB = $ramGB * 1024;
    $vmName = 'vm' . $serviceId;

    try {
        logModuleCall('proxmox_custom', __FUNCTION__, 'Starting account creation (synchronous)', null, null);

        // 1. Use Service ID as VMID — prefix with '9' if under 100 (Proxmox requires VMID >= 100)
        $newVMID = ($serviceId < 100) ? (int)('9' . $serviceId) : $serviceId;
        if (proxmox_custom_vmidExists($serverHostname, $apiTokenID, $apiTokenSecret, $newVMID)) {
            throw new Exception("VMID {$newVMID} (Service ID) already exists in Proxmox. Cannot provision.");
        }
        logModuleCall('proxmox_custom', __FUNCTION__, 'Using Service ID as VMID', ['newVMID' => $newVMID], null);

        // 2. Clone VM (synchronous — waits for completion)
        proxmox_custom_cloneVM($serverHostname, $apiTokenID, $apiTokenSecret, $node, $templateId, $newVMID, $vmName);
        logModuleCall('proxmox_custom', __FUNCTION__, 'Clone completed', ['templateId' => $templateId, 'newVMID' => $newVMID], null);

        // 3. Save VM details so WHMCS tracks the VMID
        proxmox_custom_saveVMDetails($serviceId, $newVMID);
        logModuleCall('proxmox_custom', __FUNCTION__, 'Saved VM Details', ['serviceId' => $serviceId, 'newVMID' => $newVMID], null);

        // 4. Stop VM (cloned VMs may already be stopped, handle gracefully)
        try {
            $vmStatus = proxmox_custom_getVMStatus($serverHostname, $apiTokenID, $apiTokenSecret, $node, $newVMID);
            if (!isset($vmStatus['status']) || $vmStatus['status'] !== 'stopped') {
                proxmox_custom_stopVM($serverHostname, $apiTokenID, $apiTokenSecret, $node, $newVMID);
            }
            logModuleCall('proxmox_custom', __FUNCTION__, 'VM stopped/already stopped', ['vmid' => $newVMID], null);
        } catch (Exception $e) {
            logModuleCall('proxmox_custom', __FUNCTION__, 'Stop VM non-fatal', $e->getMessage(), null);
        }

        // 5. Create Proxmox user with a random internal password
        //    (client never sees this — console auto-login handles auth)
        $proxmoxUserID = 'client' . $userId . '@pve';
        $pvePassword = bin2hex(random_bytes(16));

        $userExists = proxmox_custom_userExists($serverHostname, $apiTokenID, $apiTokenSecret, $proxmoxUserID);
        if (!$userExists) {
            proxmox_custom_createUser($serverHostname, $apiTokenID, $apiTokenSecret, $proxmoxUserID, $pvePassword);
            logModuleCall('proxmox_custom', __FUNCTION__, 'Created Proxmox user', ['proxmoxUserID' => $proxmoxUserID], null);
        } else {
            proxmox_custom_changeUserPassword($serverHostname, $apiTokenID, $apiTokenSecret, $proxmoxUserID, $pvePassword);
        }
        proxmox_custom_savePVEPassword($serviceId, $pvePassword);

        // 6. Assign permissions to the user for the VM
        $path   = "/vms/{$newVMID}";
        $roleid = 'PVEVMUser';
        proxmox_custom_assignPermissions($serverHostname, $apiTokenID, $apiTokenSecret, $proxmoxUserID, $path, $roleid);
        logModuleCall('proxmox_custom', __FUNCTION__, 'Assigned Permissions', ['proxmoxUserID' => $proxmoxUserID], null);

        // 7. Reserve MAC address, Public IP, Bridge and MTU
        list($macAddress, $publicIP, $bridge, $mtu) = proxmox_custom_getAvailableMAC($serverId, $serverHostname, $apiTokenID, $apiTokenSecret, $node);
        logModuleCall('proxmox_custom', __FUNCTION__, 'Reserved Network Details', ['macAddress' => $macAddress, 'publicIP' => $publicIP, 'bridge' => $bridge, 'mtu' => $mtu], null);

        // 8. Save Dedicated IP and service details
        $hostnameSuffix = proxmox_custom_GetOption($params, 'HostnameSuffix', '.vps.example.com');
        if (!empty($publicIP)) {
            proxmox_custom_saveDedicatedIP($serviceId, $publicIP);
            proxmox_custom_updateServiceDetails($serviceId, $userId, $publicIP, $hostnameSuffix);
            logModuleCall('proxmox_custom', __FUNCTION__, 'Saved IP and service details', ['publicIP' => $publicIP], null);
        }

        // 9. Resize disk
        $baseDiskSize = 10;
        $increaseSize = $diskSizeGB - $baseDiskSize;
        if ($increaseSize > 0) {
            try {
                proxmox_custom_resizeDisk($serverHostname, $apiTokenID, $apiTokenSecret, $node, $newVMID, 'scsi0', $increaseSize);
                logModuleCall('proxmox_custom', __FUNCTION__, 'Disk resized', ['increaseSize' => $increaseSize], null);
            } catch (Exception $e) {
                logModuleCall('proxmox_custom', __FUNCTION__, 'Disk resize failed (non-fatal)', $e->getMessage(), null);
            }
        }

        // 10. Configure VM (CPU, RAM, cloud-init, network)
        proxmox_custom_configureVM(
            $serverHostname, $apiTokenID, $apiTokenSecret, $node, $newVMID,
            $cpuCores, $ramMB, $userId, $password,
            $macAddress, $bridge, $mtu
        );
        logModuleCall('proxmox_custom', __FUNCTION__, 'VM configured', ['vmid' => $newVMID], null);

        // 11. Set network speed
        proxmox_custom_setNetworkSpeed($serverHostname, $apiTokenID, $apiTokenSecret, $node, $newVMID, $networkSpeed);
        logModuleCall('proxmox_custom', __FUNCTION__, 'Network speed set', ['networkSpeed' => $networkSpeed], null);

        // 12. Start VM
        proxmox_custom_startVM($serverHostname, $apiTokenID, $apiTokenSecret, $node, $newVMID);
        logModuleCall('proxmox_custom', __FUNCTION__, 'VM started. Provisioning complete!', ['vmid' => $newVMID], null);

        return 'success';

    } catch (Exception $e) {
        logModuleCall('proxmox_custom', __FUNCTION__, ['serviceid' => $serviceId, 'apiTokenSecret' => '***', 'password' => '***',], $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

function proxmox_custom_SuspendAccount(array $params)
{
    $serviceId      = $params['serviceid'];
    $serverHostname = $params['serverhostname'];
    $apiTokenID     = $params['serverusername'];
    $apiTokenSecret = $params['serverpassword'];
    $nodeConfigured = $params['configoption2'];
    $roleid = 'PVEVMUser';

    $vmid = proxmox_custom_getVMID($serviceId);
    $node = proxmox_custom_resolveVMNode($serverHostname, $apiTokenID, $apiTokenSecret, $vmid, $nodeConfigured);

    $userId        = $params['userid'];
    $proxmoxUserID = 'client' . $userId . '@pve';

    try {
        // This function now waits for the task to complete
        proxmox_custom_stopVM($serverHostname, $apiTokenID, $apiTokenSecret, $node, $vmid);

        $path = "/vms/{$vmid}";
        proxmox_custom_removePermissions($serverHostname, $apiTokenID, $apiTokenSecret, $proxmoxUserID, $path, $roleid);

        return 'success';
    } catch (Exception $e) {
        logModuleCall('proxmox_custom', __FUNCTION__, ['serviceid' => $serviceId], $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

function proxmox_custom_UnsuspendAccount(array $params)
{
    $serviceId      = $params['serviceid'];
    $serverHostname = $params['serverhostname'];
    $apiTokenID     = $params['serverusername'];
    $apiTokenSecret = $params['serverpassword'];
    $nodeConfigured = $params['configoption2'];

    $vmid = proxmox_custom_getVMID($serviceId);
    $node = proxmox_custom_resolveVMNode($serverHostname, $apiTokenID, $apiTokenSecret, $vmid, $nodeConfigured);

    $userId        = $params['userid'];
    $proxmoxUserID = 'client' . $userId . '@pve';

    try {
        $path   = "/vms/{$vmid}";
        $roleid = 'PVEVMUser';
        proxmox_custom_assignPermissions($serverHostname, $apiTokenID, $apiTokenSecret, $proxmoxUserID, $path, $roleid);

        // This function now waits for the task to complete
        proxmox_custom_startVM($serverHostname, $apiTokenID, $apiTokenSecret, $node, $vmid);

        return 'success';
    } catch (Exception $e) {
        logModuleCall('proxmox_custom', __FUNCTION__, ['serviceid' => $serviceId], $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

function proxmox_custom_TerminateAccount(array $params)
{
    $serviceId      = $params['serviceid'];
    $serverHostname = $params['serverhostname'];
    $apiTokenID     = $params['serverusername'];
    $apiTokenSecret = $params['serverpassword'];
    $nodeConfigured = $params['configoption2'];
    $roleid         = 'PVEVMUser';

    $vmid = proxmox_custom_getVMID($serviceId);
    $node = proxmox_custom_resolveVMNode($serverHostname, $apiTokenID, $apiTokenSecret, $vmid, $nodeConfigured);

    $userId        = $params['userid'];
    $proxmoxUserID = 'client' . $userId . '@pve';

    try {
        $isRunning = proxmox_custom_isVMRunning($serverHostname, $apiTokenID, $apiTokenSecret, $node, $vmid);
        if ($isRunning) {
            // This function now waits for the task to complete
            proxmox_custom_stopVM($serverHostname, $apiTokenID, $apiTokenSecret, $node, $vmid);
            logModuleCall('proxmox_custom', __FUNCTION__, 'Stopped VM', ['vmid' => $vmid], null);
        }

        $userExists = proxmox_custom_userExists($serverHostname, $apiTokenID, $apiTokenSecret, $proxmoxUserID);
        logModuleCall('proxmox_custom', __FUNCTION__, 'Checked User Existence', ['proxmoxUserID' => $proxmoxUserID, 'userExists' => $userExists], null);
        if ($userExists) {
            $path = "/vms/{$vmid}";
            proxmox_custom_removePermissions($serverHostname, $apiTokenID, $apiTokenSecret, $proxmoxUserID, $path, $roleid);
            logModuleCall('proxmox_custom', __FUNCTION__, 'Removed Permissions', ['proxmoxUserID' => $proxmoxUserID, 'path' => $path, 'roleid' => $roleid], null);
        } else {
            logModuleCall('proxmox_custom', __FUNCTION__, 'User Does Not Exist, Skipping Remove Permissions', ['proxmoxUserID' => $proxmoxUserID], null);
        }

        // This function now waits for the task to complete
        proxmox_custom_destroyVM($serverHostname, $apiTokenID, $apiTokenSecret, $node, $vmid);
        logModuleCall('proxmox_custom', __FUNCTION__, 'Destroyed VM', ['vmid' => $vmid], null);

        if ($userExists) {
            $hasOtherPermissions = proxmox_custom_userHasOtherPermissions($serverHostname, $apiTokenID, $apiTokenSecret, $proxmoxUserID, $vmid);
            logModuleCall('proxmox_custom', __FUNCTION__, 'Checked Other Permissions', ['proxmoxUserID' => $proxmoxUserID, 'hasOtherPermissions' => $hasOtherPermissions], null);
            if (!$hasOtherPermissions) {
                proxmox_custom_deleteUser($serverHostname, $apiTokenID, $apiTokenSecret, $proxmoxUserID);
                logModuleCall('proxmox_custom', __FUNCTION__, 'Deleted User', ['proxmoxUserID' => $proxmoxUserID], null);
            } else {
                logModuleCall('proxmox_custom', __FUNCTION__, 'User Has Other Permissions, Skipping Delete', ['proxmoxUserID' => $proxmoxUserID], null);
            }
        }

        return 'success';
    } catch (Exception $e) {
        logModuleCall('proxmox_custom', __FUNCTION__, ['serviceid' => $serviceId], $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}


// --- ASYNC PROVISIONING FUNCTIONS --- //

/**
 * Ensures the mod_proxmox_tasks table exists. Called once per request if needed.
 */
function proxmox_custom_ensureTaskTable()
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    if (!Capsule::schema()->hasTable('mod_proxmox_tasks')) {
        Capsule::schema()->create('mod_proxmox_tasks', function ($table) {
            $table->increments('id');
            $table->integer('service_id');
            $table->integer('server_id');
            $table->string('task_type', 50)->default('create');
            $table->string('stage', 50)->default('cloning');
            $table->string('upid', 255)->nullable();
            $table->text('params');
            $table->string('status', 20)->default('pending');
            $table->text('error_message')->nullable();
            $table->integer('attempts')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
        logModuleCall('proxmox_custom', __FUNCTION__, 'Created mod_proxmox_tasks table', null, null);
    }
}

/**
 * Starts a Proxmox clone operation and returns the UPID without waiting.
 *
 * @return string The UPID of the clone task.
 * @throws Exception
 */
function proxmox_custom_startCloneVM($hostname, $apiTokenID, $apiTokenSecret, $node, $templateId, $newVMID, $vmName)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$templateId}/clone";
    logModuleCall('proxmox_custom', __FUNCTION__, 'Starting clone (no wait)', ['URL' => $url, 'newVMID' => $newVMID, 'vmName' => $vmName], null);

    $postFieldsArray = ['newid' => $newVMID, 'name' => $vmName, 'full' => 1, 'target' => $node];
    $postFields = http_build_query($postFieldsArray, '', '&', PHP_QUERY_RFC3986);
    $headers = ["Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}", 'Content-Type: application/x-www-form-urlencoded'];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    logModuleCall('proxmox_custom', __FUNCTION__, 'Clone Start Response', ['Response' => $response, 'cURL Error' => $curlError], null);

    if ($response === false) {
        throw new Exception('Start Clone VM failed: ' . $curlError);
    }

    $data = json_decode($response, true);
    if (isset($data['errors']) && !empty($data['errors'])) {
        throw new Exception('Start Clone VM failed: ' . json_encode($data['errors']));
    }

    if (isset($data['data'])) {
        return $data['data']; // Return the UPID
    }

    throw new Exception('Could not retrieve task ID for clone operation.');
}

/**
 * Continues async provisioning for all pending tasks.
 * Called from the WHMCS cron hook.
 */
function proxmox_custom_continueProvisioning()
{
    proxmox_custom_ensureTaskTable();

    $pendingTasks = Capsule::table('mod_proxmox_tasks')
        ->where('status', 'pending')
        ->orderBy('created_at', 'asc')
        ->get();

    foreach ($pendingTasks as $task) {
        try {
            $p = json_decode($task->params, true);
            $hostname       = $p['serverhostname'];
            $apiTokenID     = $p['serverusername'];
            $apiTokenSecret = $p['serverpassword'];
            $node           = $p['node'];
            $vmid           = $p['vmid'];
            $serviceId      = $p['serviceid'];

            logModuleCall('proxmox_custom', __FUNCTION__, "Processing task {$task->id}", ['stage' => $task->stage, 'vmid' => $vmid], null);

            Capsule::table('mod_proxmox_tasks')->where('id', $task->id)->update([
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            switch ($task->stage) {

                // ── STAGE: cloning ─────────────────────────────────────────
                case 'cloning':
                    $taskStatus = proxmox_custom_getTaskStatus($hostname, $apiTokenID, $apiTokenSecret, $node, $task->upid);

                    if (isset($taskStatus['status']) && $taskStatus['status'] === 'running') {
                        logModuleCall('proxmox_custom', __FUNCTION__, "Clone still running for task {$task->id}", null, null);
                        break; // Still running, will check again next cron
                    }

                    if (!isset($taskStatus['exitstatus']) || $taskStatus['exitstatus'] !== 'OK') {
                        throw new Exception('Clone task failed: ' . ($taskStatus['exitstatus'] ?? 'Unknown'));
                    }

                    logModuleCall('proxmox_custom', __FUNCTION__, "Clone completed for task {$task->id}. Advancing to stop_vm.", null, null);
                    Capsule::table('mod_proxmox_tasks')->where('id', $task->id)->update([
                        'stage'      => 'stop_vm',
                        'upid'       => null,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    break;

                // ── STAGE: stop_vm ─────────────────────────────────────────
                case 'stop_vm':
                    // Check if VM is already stopped (full clones create stopped VMs)
                    $vmStatus = proxmox_custom_getVMStatus($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid);
                    if (isset($vmStatus['status']) && $vmStatus['status'] === 'stopped') {
                        logModuleCall('proxmox_custom', __FUNCTION__, "VM already stopped for task {$task->id}. Advancing to configure.", null, null);
                    } else {
                        proxmox_custom_stopVM($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid);
                        logModuleCall('proxmox_custom', __FUNCTION__, "VM stopped for task {$task->id}. Advancing to configure.", null, null);
                    }

                    Capsule::table('mod_proxmox_tasks')->where('id', $task->id)->update([
                        'stage'      => 'configure',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    break;

                // ── STAGE: configure (resize + config + net speed) ────────
                case 'configure':
                    // Resize disk
                    $baseDiskSize = 10;
                    $increaseSize = $p['diskSizeGB'] - $baseDiskSize;
                    if ($increaseSize > 0) {
                        try {
                            proxmox_custom_resizeDisk($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid, 'scsi0', $increaseSize);
                            logModuleCall('proxmox_custom', __FUNCTION__, "Disk resized for task {$task->id}", ['increaseSize' => $increaseSize], null);
                        } catch (Exception $e) {
                            logModuleCall('proxmox_custom', __FUNCTION__, "Disk resize failed (non-fatal) for task {$task->id}", $e->getMessage(), null);
                        }
                    }

                    // Configure VM
                    proxmox_custom_configureVM(
                        $hostname, $apiTokenID, $apiTokenSecret, $node, $vmid,
                        $p['cpuCores'], $p['ramMB'], $p['userid'], $p['password'],
                        $p['macAddress'], $p['bridge'], $p['mtu']
                    );
                    logModuleCall('proxmox_custom', __FUNCTION__, "VM configured for task {$task->id}", null, null);

                    // Set network speed
                    proxmox_custom_setNetworkSpeed($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid, $p['networkSpeed']);
                    logModuleCall('proxmox_custom', __FUNCTION__, "Network speed set for task {$task->id}", null, null);

                    Capsule::table('mod_proxmox_tasks')->where('id', $task->id)->update([
                        'stage'      => 'start_vm',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    break;

                // ── STAGE: start_vm ────────────────────────────────────────
                case 'start_vm':
                    proxmox_custom_startVM($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid);
                    logModuleCall('proxmox_custom', __FUNCTION__, "VM started for task {$task->id}. Provisioning complete!", null, null);

                    // Mark as completed
                    Capsule::table('mod_proxmox_tasks')->where('id', $task->id)->update([
                        'stage'      => 'completed',
                        'status'     => 'completed',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    break;

                default:
                    logModuleCall('proxmox_custom', __FUNCTION__, "Unknown stage '{$task->stage}' for task {$task->id}", null, null);
                    break;
            }

        } catch (Exception $e) {
            // Fail immediately — no retries. Check WHMCS Module Log for details.
            logModuleCall('proxmox_custom', __FUNCTION__, "Task {$task->id} FAILED at stage '{$task->stage}'", $e->getMessage(), $e->getTraceAsString());

            Capsule::table('mod_proxmox_tasks')->where('id', $task->id)->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);

            // If the clone already finished, clean up the partially-provisioned VM
            if ($task->stage !== 'cloning' && !empty($vmid)) {
                try {
                    logModuleCall('proxmox_custom', __FUNCTION__, "Cleaning up failed VM {$vmid}", null, null);
                    // Try stopping first (may already be stopped)
                    try { proxmox_custom_stopVM($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid); } catch (Exception $ignore) {}
                    sleep(3);
                    proxmox_custom_destroyVM($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid);
                    logModuleCall('proxmox_custom', __FUNCTION__, "Successfully cleaned up failed VM {$vmid}", null, null);
                } catch (Exception $cleanupErr) {
                    logModuleCall('proxmox_custom', __FUNCTION__, "Failed to clean up VM {$vmid}", $cleanupErr->getMessage(), null);
                }
            }
        }
    }
}

// --- HELPER FUNCTIONS --- //

/**
 * Waits for a Proxmox task to complete by polling its status.
 *
 * @param string $hostname
 * @param string $apiTokenID
 * @param string $apiTokenSecret
 * @param string $node
 * @param string $upid The task ID (UPID) to monitor.
 * @param int $timeout Timeout in seconds.
 * @return bool
 * @throws Exception
 */
function proxmox_custom_waitForTaskCompletion($hostname, $apiTokenID, $apiTokenSecret, $node, $upid, $timeout = 300)
{
    $startTime = time();
    logModuleCall('proxmox_custom', __FUNCTION__, "Waiting for task {$upid} to complete...", null, null);

    while (time() - $startTime < $timeout) {
        try {
            $taskStatus = proxmox_custom_getTaskStatus($hostname, $apiTokenID, $apiTokenSecret, $node, $upid);
            
            if (isset($taskStatus['status']) && $taskStatus['status'] === 'running') {
                sleep(5); // Wait 5 seconds before polling again
                continue;
            }
            
            if (isset($taskStatus['exitstatus']) && $taskStatus['exitstatus'] === 'OK') {
                logModuleCall('proxmox_custom', __FUNCTION__, "Task {$upid} completed successfully.", $taskStatus, null);
                return true; // Task completed successfully
            } else {
                $errorMessage = "Task {$upid} failed with exit status: " . ($taskStatus['exitstatus'] ?? 'Unknown');
                 logModuleCall('proxmox_custom', __FUNCTION__, $errorMessage, $taskStatus, null);
                throw new Exception($errorMessage);
            }

        } catch (Exception $e) {
            // Rethrow exception from getTaskStatus or the one we created
            throw $e;
        }
    }
    
    throw new Exception("Timed out waiting for task {$upid} to complete.");
}

/**
 * Retrieves the status of a specific Proxmox task.
 *
 * @param string $hostname
 * @param string $apiTokenID
 * @param string $apiTokenSecret
 * @param string $node
 * @param string $upid The task ID (UPID).
 * @return array
 * @throws Exception
 */
function proxmox_custom_getTaskStatus($hostname, $apiTokenID, $apiTokenSecret, $node, $upid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/tasks/" . urlencode($upid) . "/status";

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new Exception("Failed to retrieve task status for {$upid}: " . $curlError);
    }
    
    $data = json_decode($response, true);
    
    if ($httpCode >= 200 && $httpCode < 300 && isset($data['data'])) {
        return $data['data'];
    } else {
        $errorMessage = isset($data['errors']) ? json_encode($data['errors']) : $response;
        throw new Exception("Failed to retrieve task status for {$upid}. API response: " . $errorMessage);
    }
}

// Function to check if a user exists
function proxmox_custom_userExists($hostname, $apiTokenID, $apiTokenSecret, $userid)
{
    $url = "https://{$hostname}/api2/json/access/users";

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('Check user existence failed: ' . $curlError);
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        $data = json_decode($response, true);
        if (isset($data['data'])) {
            foreach ($data['data'] as $user) {
                if ($user['userid'] === $userid) {
                    return true;
                }
            }
        }
        return false;
    } else {
        throw new Exception('Check user existence failed: Invalid response');
    }
}

// Function to remove permissions from a user for a VM
function proxmox_custom_removePermissions($hostname, $apiTokenID, $apiTokenSecret, $userid, $path, $roleid)
{
    $url = "https://{$hostname}/api2/json/access/acl";

    $postFieldsArray = [
        'path'      => $path,
        'users'     => $userid,
        'roles'     => $roleid,
        'propagate' => 0,
        'delete'    => 1,
    ];

    $postFields = http_build_query($postFieldsArray, '', '&', PHP_QUERY_RFC3986);

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
        'Content-Type: application/x-www-form-urlencoded',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    logModuleCall('proxmox_custom', __FUNCTION__, ['URL' => $url, 'Post Fields' => $postFieldsArray, 'Headers' => $headers,], ['Response' => $response, 'HTTP Code' => $httpCode, 'cURL Error' => $curlError,]);

    if ($response === false) {
        throw new Exception('Remove permissions failed: ' . $curlError);
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    } else {
        $data = json_decode($response, true);
        $errorMessage = isset($data['errors']) ? json_encode($data['errors']) : $response;
        throw new Exception('Remove permissions failed: ' . $errorMessage);
    }
}

// Function to check if a user has permissions elsewhere
function proxmox_custom_userHasOtherPermissions($hostname, $apiTokenID, $apiTokenSecret, $userid, $excludeVmid)
{
    $url = "https://{$hostname}/api2/json/access/permissions?userid=" . urlencode($userid);

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('Check user permissions failed: ' . $curlError);
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        $data = json_decode($response, true);
        if (isset($data['data'])) {
            foreach ($data['data'] as $path => $permissions) {
                if ($path !== "/vms/{$excludeVmid}") {
                    return true;
                }
            }
        }
        return false;
    } else {
        throw new Exception('Check user permissions failed: Invalid response');
    }
}

// Function to delete a user
function proxmox_custom_deleteUser($hostname, $apiTokenID, $apiTokenSecret, $userid)
{
    $url = "https://{$hostname}/api2/json/access/users/" . urlencode($userid);

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('Delete user failed: ' . $curlError);
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    } else {
        $data = json_decode($response, true);
        $errorMessage = isset($data['errors']) ? json_encode($data['errors']) : json_encode($data);
        throw new Exception('Delete user failed: ' . $errorMessage);
    }
}

// Function to check if a VM is running
function proxmox_custom_isVMRunning($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/status/current";

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    logModuleCall('proxmox_custom', __FUNCTION__, ['URL' => $url, 'Headers' => $headers,], ['Response'   => $response, 'HTTP Code'  => $httpCode, 'cURL Error' => $curlError,]);

    if ($response === false) {
        throw new Exception('Check VM status failed: ' . $curlError);
    }

    $data = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && isset($data['data']['status'])) {
        return $data['data']['status'] === 'running';
    } else {
        $errorMessage = isset($data['errors']) ? json_encode($data['errors']) : $response;
        throw new Exception('Check VM status failed: ' . $errorMessage);
    }
}

// Function to stop a VM
function proxmox_custom_stopVM($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/status/stop";
    logModuleCall('proxmox_custom', __FUNCTION__, 'Stopping VM', ['URL' => $url, 'vmid' => $vmid], null);

    $headers = ["Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}"];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    logModuleCall('proxmox_custom', __FUNCTION__, 'Stop VM Response', ['Response' => $response, 'cURL Error' => $curlError], null);

    if ($response === false) {
        throw new Exception('Stop VM failed: ' . $curlError);
    }

    $data = json_decode($response, true);
    if (isset($data['errors']) && !empty($data['errors'])) {
        throw new Exception('Stop VM failed: ' . json_encode($data['errors']));
    }

    if (isset($data['data'])) {
        $upid = $data['data'];
        proxmox_custom_waitForTaskCompletion($hostname, $apiTokenID, $apiTokenSecret, $node, $upid);
    } else {
        logModuleCall('proxmox_custom', __FUNCTION__, 'Could not retrieve task ID for stop operation. Waiting for status change instead.', ['vmid' => $vmid], null);
        proxmox_custom_waitForVMStatus($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid, 'stopped');
    }

    logModuleCall('proxmox_custom', __FUNCTION__, 'Stop VM Success', ['vmid' => $vmid], null);
    return true;
}


// Function to destroy a VM
function proxmox_custom_destroyVM($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}";

    $headers = ["Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}"];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('Destroy VM failed: ' . $curlError);
    }

    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        if (isset($data['data'])) {
            $upid = $data['data'];
            proxmox_custom_waitForTaskCompletion($hostname, $apiTokenID, $apiTokenSecret, $node, $upid);
        }
        return true;
    } else {
        $errorMessage = isset($data['errors']) ? json_encode($data['errors']) : json_encode($data);
        throw new Exception('Destroy VM failed: ' . $errorMessage);
    }
}

/**
 * Checks whether a VMID already exists in the Proxmox cluster.
 *
 * @param string $hostname
 * @param string $apiTokenID
 * @param string $apiTokenSecret
 * @param int|string $vmid The VMID to check.
 * @return bool True if the VMID already exists.
 * @throws Exception on API failure.
 */
function proxmox_custom_vmidExists($hostname, $apiTokenID, $apiTokenSecret, $vmid)
{
    $url = "https://{$hostname}/api2/json/cluster/resources?type=vm";
    $headers = ["Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}"];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    logModuleCall('proxmox_custom', __FUNCTION__, ['URL' => $url, 'vmid' => $vmid], ['Response' => $response, 'cURL Error' => $curlError], null);

    if ($response === false) {
        throw new Exception('Check VMID existence failed: ' . $curlError);
    }

    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300 && isset($data['data'])) {
        foreach ($data['data'] as $resource) {
            if (isset($resource['vmid']) && (int)$resource['vmid'] === (int)$vmid) {
                return true;
            }
        }
        return false;
    } else {
        $errorMessage = isset($data['errors']) ? json_encode($data['errors']) : $response;
        throw new Exception('Check VMID existence failed: ' . $errorMessage);
    }
}

function proxmox_custom_cloneVM($hostname, $apiTokenID, $apiTokenSecret, $node, $templateId, $newVMID, $vmName)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$templateId}/clone";
    logModuleCall('proxmox_custom', __FUNCTION__, 'Cloning VM', ['URL' => $url, 'newVMID' => $newVMID, 'vmName' => $vmName], null);

    $postFieldsArray = ['newid' => $newVMID, 'name' => $vmName, 'full' => 1, 'target' => $node,];
    $postFields = http_build_query($postFieldsArray, '', '&', PHP_QUERY_RFC3986);
    $headers = ["Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}", 'Content-Type: application/x-www-form-urlencoded',];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    logModuleCall('proxmox_custom', __FUNCTION__, 'Clone VM Response', ['Response' => $response, 'cURL Error' => $curlError], null);

    if ($response === false) {
        throw new Exception('Clone VM failed: ' . $curlError);
    }

    $data = json_decode($response, true);
    if (isset($data['errors']) && !empty($data['errors'])) {
        throw new Exception('Clone VM failed: ' . json_encode($data['errors']));
    }

    if (isset($data['data'])) {
        $upid = $data['data'];
        proxmox_custom_waitForTaskCompletion($hostname, $apiTokenID, $apiTokenSecret, $node, $upid);
    } else {
        throw new Exception('Could not retrieve task ID for clone operation.');
    }

    logModuleCall('proxmox_custom', __FUNCTION__, 'Clone VM Success', ['newVMID' => $newVMID], null);
    return true;
}

// Function to configure VM
function proxmox_custom_configureVM($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid, $cpuCores, $ram, $userId, $password, $macAddress, $bridge = 'vmbr1', $mtu = null)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/config";
    
    $cloudInitUser     = 'client' . $userId;
    $cloudInitPassword = $password;
    
    // Updated NetConfig logic to support custom bridge and optional MTU
    $netConfig = "virtio={$macAddress},bridge={$bridge},firewall=0";
    if ($mtu) {
        $netConfig .= ",mtu={$mtu}";
    }

    $postFieldsArray = [
        'cores'      => $cpuCores,
        'memory'     => $ram,
        'ciuser'     => $cloudInitUser,
        'cipassword' => $cloudInitPassword,
        'agent'      => 1,
        'net0'       => $netConfig,
    ];

    $postFields = http_build_query($postFieldsArray, '', '&', PHP_QUERY_RFC3986);
    $headers = ["Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}", 'Content-Type: application/x-www-form-urlencoded',];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    logModuleCall('proxmox_custom', __FUNCTION__, 'Configure VM Response', ['Response' => $response, 'cURL Error' => $curlError], null);

    if ($response === false) {
        throw new Exception('Configure VM failed: ' . $curlError);
    }

    $data = json_decode($response, true);
    if (isset($data['errors']) && !empty($data['errors'])) {
        throw new Exception('Configure VM failed: ' . json_encode($data['errors']));
    }

    if (isset($data['data'])) {
        $upid = $data['data'];
        proxmox_custom_waitForTaskCompletion($hostname, $apiTokenID, $apiTokenSecret, $node, $upid);
    }

    logModuleCall('proxmox_custom', __FUNCTION__, 'Configure VM Success', ['vmid' => $vmid, 'status' => 'Configurations applied successfully'], null);
    return true;
}

// Modify the saveVMDetails function to accept $publicIP
function proxmox_custom_saveVMDetails($serviceId, $vmid, $publicIP = null)
{
    $fields = Capsule::table('tblcustomfields')->where('type', 'product')->whereIn('fieldname', ['VMID', 'IP'])->pluck('id', 'fieldname');

    if (isset($fields['VMID'])) {
        Capsule::table('tblcustomfieldsvalues')->updateOrInsert(['fieldid' => $fields['VMID'], 'relid' => $serviceId], ['value' => $vmid]);
    } else {
        $fieldId = Capsule::table('tblcustomfields')->insertGetId(['type' => 'product', 'relid' => 0, 'fieldname' => 'VMID', 'fieldtype' => 'text', 'description' => 'Proxmox VMID', 'required' => '0', 'showorder' => '0', 'showinvoice' => '0',]);
        Capsule::table('tblcustomfieldsvalues')->updateOrInsert(['fieldid' => $fieldId, 'relid' => $serviceId], ['value' => $vmid]);
    }

    if ($publicIP && isset($fields['IP'])) {
        Capsule::table('tblcustomfieldsvalues')->updateOrInsert(['fieldid' => $fields['IP'], 'relid' => $serviceId], ['value' => $publicIP]);
    } elseif ($publicIP) {
        $fieldId = Capsule::table('tblcustomfields')->insertGetId(['type' => 'product', 'relid' => 0, 'fieldname' => 'IP', 'fieldtype' => 'text', 'description' => 'Assigned Public IP', 'required' => '0', 'showorder' => '0', 'showinvoice' => '0',]);
        Capsule::table('tblcustomfieldsvalues')->updateOrInsert(['fieldid' => $fieldId, 'relid' => $serviceId], ['value' => $publicIP]);
    }
}

/**
 * Saves the internal PVE password for a service (admin-only custom field).
 */
function proxmox_custom_savePVEPassword($serviceId, $pvePassword)
{
    $field = Capsule::table('tblcustomfields')
        ->where('type', 'product')
        ->where('fieldname', 'PVEPassword')
        ->first();

    if ($field) {
        Capsule::table('tblcustomfieldsvalues')->updateOrInsert(
            ['fieldid' => $field->id, 'relid' => $serviceId],
            ['value' => $pvePassword]
        );
    } else {
        $fieldId = Capsule::table('tblcustomfields')->insertGetId([
            'type'        => 'product',
            'relid'       => 0,
            'fieldname'   => 'PVEPassword',
            'fieldtype'   => 'text',
            'description' => 'Internal Proxmox user password (do not share)',
            'adminonly'   => 'on',
            'required'    => '0',
            'showorder'   => '0',
            'showinvoice' => '0',
        ]);
        Capsule::table('tblcustomfieldsvalues')->updateOrInsert(
            ['fieldid' => $fieldId, 'relid' => $serviceId],
            ['value' => $pvePassword]
        );
    }
}

/**
 * Retrieves the stored internal PVE password for a service.
 */
function proxmox_custom_getPVEPassword($serviceId)
{
    $field = Capsule::table('tblcustomfields')
        ->where('type', 'product')
        ->where('fieldname', 'PVEPassword')
        ->first();

    if (!$field) {
        return null;
    }

    $value = Capsule::table('tblcustomfieldsvalues')
        ->where('fieldid', $field->id)
        ->where('relid', $serviceId)
        ->value('value');

    return $value ?: null;
}

/**
 * Changes an existing Proxmox user's password by deleting and recreating the user.
 * The /access/password endpoint is not available with API tokens, so we use this workaround.
 * VM permissions are automatically re-assigned after recreation.
 */
function proxmox_custom_changeUserPassword($hostname, $apiTokenID, $apiTokenSecret, $userid, $newPassword)
{
    // 1. Get the user's current VM permissions so we can restore them
    $permissionsToRestore = [];
    try {
        $url = "https://{$hostname}/api2/json/access/acl";
        $headers = ["Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}"];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);
        if (isset($data['data'])) {
            foreach ($data['data'] as $acl) {
                if (isset($acl['ugid']) && $acl['ugid'] === $userid && $acl['type'] === 'user') {
                    $permissionsToRestore[] = ['path' => $acl['path'], 'roleid' => $acl['roleid']];
                }
            }
        }
    } catch (Exception $e) {
        logModuleCall('proxmox_custom', __FUNCTION__, 'Warning: could not read existing permissions', $e->getMessage(), null);
    }

    // 2. Delete the user
    proxmox_custom_deleteUser($hostname, $apiTokenID, $apiTokenSecret, $userid);

    // 3. Recreate with new password
    proxmox_custom_createUser($hostname, $apiTokenID, $apiTokenSecret, $userid, $newPassword);

    // 4. Re-assign permissions
    foreach ($permissionsToRestore as $perm) {
        try {
            proxmox_custom_assignPermissions($hostname, $apiTokenID, $apiTokenSecret, $userid, $perm['path'], $perm['roleid']);
        } catch (Exception $e) {
            logModuleCall('proxmox_custom', __FUNCTION__, 'Warning: could not restore permission', ['path' => $perm['path'], 'error' => $e->getMessage()], null);
        }
    }

    logModuleCall('proxmox_custom', __FUNCTION__, 'Password changed via delete+recreate', ['userid' => $userid, 'permissions_restored' => count($permissionsToRestore)], null);
    return true;
}

function proxmox_custom_createUser($hostname, $apiTokenID, $apiTokenSecret, $userid, $password)
{
    $url = "https://{$hostname}/api2/json/access/users";

    $postFieldsArray = ['userid' => $userid, 'password' => $password, 'enable' => 1,];
    $postFields = http_build_query($postFieldsArray, '', '&', PHP_QUERY_RFC3986);
    $headers = ["Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}", 'Content-Type: application/x-www-form-urlencoded',];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    logModuleCall('proxmox_custom', 'Create User', ['URL' => $url, 'Post Fields' => '[omitted]',], ['Response' => $response, 'HTTP Code' => $httpCode, 'cURL Error' => $curlError,]);

    if ($response === false) {
        throw new Exception('Create user failed: ' . $curlError);
    }

    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    } else {
        $errorMessage = isset($data['errors']) ? json_encode($data['errors']) : json_encode($data);
        throw new Exception('Create user failed: ' . $errorMessage);
    }
}

function proxmox_custom_assignPermissions($hostname, $apiTokenID, $apiTokenSecret, $userid, $path, $roleid)
{
    $url = "https://{$hostname}/api2/json/access/acl";

    $postFieldsArray = ['path' => $path, 'users' => $userid, 'roles' => $roleid, 'propagate' => 0,];
    $postFields = http_build_query($postFieldsArray, '', '&', PHP_QUERY_RFC3986);
    $headers = ["Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}", 'Content-Type: application/x-www-form-urlencoded',];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    logModuleCall('proxmox_custom', 'Assign Permissions', ['URL' => $url, 'Post Fields' => $postFieldsArray,], ['Response' => $response, 'HTTP Code' => $httpCode, 'cURL Error' => $curlError,]);

    if ($response === false) {
        throw new Exception('Assign permissions failed: ' . $curlError);
    }

    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    } else {
        $errorMessage = isset($data['errors']) ? json_encode($data['errors']) : json_encode($data);
        throw new Exception('Assign permissions failed: ' . $errorMessage);
    }
}

function proxmox_custom_getAvailableMAC($serverId, $hostname, $apiTokenID, $apiTokenSecret, $node)
{
    logModuleCall('proxmox_custom', __FUNCTION__, 'Fetching Assigned IP Addresses from server configuration', ['serverId' => $serverId], null);

    $serverDetails = Capsule::table('tblservers')->where('id', $serverId)->first();
    if (!$serverDetails) {
        throw new Exception('Server details not found.');
    }

    $assignedIPsRaw = $serverDetails->assignedips;
    if (empty($assignedIPsRaw)) {
        throw new Exception('No MAC addresses configured in the "Assigned IP Addresses" field.');
    }

    $macPool = [];
    $lines = explode("\n", $assignedIPsRaw);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Parse MAC=IP or MAC=IP;Bridge or MAC=IP;Bridge,MTU
        if (strpos($line, '=') !== false) {
            list($mac, $configData) = explode('=', $line, 2);
            $mac = trim($mac);
            $configData = trim($configData);
            
            // Set defaults
            $ip = $configData;
            $bridge = 'vmbr1'; // Default backup bridge
            $mtu = null;

            // Check if bridge info is present (delimited by ;)
            if (strpos($configData, ';') !== false) {
                list($ip, $bridgeData) = explode(';', $configData, 2);
                $ip = trim($ip);
                $bridgeData = trim($bridgeData);

                // Check if MTU info is present (delimited by , inside bridgeData)
                if (strpos($bridgeData, ',') !== false) {
                    list($bridge, $mtuVal) = explode(',', $bridgeData, 2);
                    $bridge = trim($bridge);
                    $mtu = intval(trim($mtuVal));
                } else {
                    $bridge = $bridgeData;
                }
            }
            
            // Store structured data instead of just IP
            $macPool[$mac] = [
                'ip' => $ip,
                'bridge' => $bridge,
                'mtu' => $mtu
            ];
        } else {
            // Fallback for lines without '=' (though unlikely based on your format)
            $macPool[$line] = [
                'ip' => '',
                'bridge' => 'vmbr1',
                'mtu' => null
            ];
        }
    }

    if (empty($macPool)) {
        throw new Exception('MAC Address Pool is empty or invalid.');
    }
    logModuleCall('proxmox_custom', __FUNCTION__, 'Parsed MAC-IP Pool', ['macPool' => $macPool], null);

    $usedMacs = proxmox_custom_getUsedMACs($hostname, $apiTokenID, $apiTokenSecret, $node);
    logModuleCall('proxmox_custom', __FUNCTION__, 'Retrieved Used MAC Addresses', ['usedMacs' => $usedMacs], null);

    foreach ($macPool as $mac => $details) {
        if (!in_array(strtolower($mac), array_map('strtolower', $usedMacs))) {
            logModuleCall('proxmox_custom', __FUNCTION__, 'Found Available MAC Address', [
                'macAddress' => $mac, 
                'publicIP' => $details['ip'],
                'bridge' => $details['bridge'],
                'mtu' => $details['mtu']
            ], null);
            
            // Return array with all details
            return [$mac, $details['ip'], $details['bridge'], $details['mtu']];
        }
    }

    throw new Exception('No available MAC addresses in the pool.');
}

function proxmox_custom_getUsedMACs($hostname, $apiTokenID, $apiTokenSecret, $node)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu";
    $headers = ["Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}"];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    logModuleCall('proxmox_custom', __FUNCTION__, 'Get VM List Response', ['URL' => $url, 'Headers' => $headers,], ['Response' => $response, 'cURL Error' => $curlError,]);

    if ($response === false) {
        throw new Exception('Failed to retrieve VM list: ' . $curlError);
    }
    $data = json_decode($response, true);
    if (!isset($data['data']) || !is_array($data['data'])) {
        throw new Exception('Invalid response when retrieving VM list.');
    }

    $usedMacs = [];
    foreach ($data['data'] as $vm) {
        $vmid = $vm['vmid'];
        try {
            $vmConfig = proxmox_custom_getVMConfig($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid);
        } catch (Exception $e) {
            logModuleCall('proxmox_custom', __FUNCTION__, "Failed to get config for VMID {$vmid}", null, $e->getMessage());
            continue;
        }

        foreach ($vmConfig as $key => $value) {
            if (preg_match('/^net\d+/', $key)) {
                if (preg_match('/hwaddr=([0-9A-Fa-f:]+)/i', $value, $matches)) {
                    $usedMacs[] = strtolower($matches[1]);
                } elseif (preg_match('/macaddr=([0-9A-Fa-f:]+)/i', $value, $matches)) {
                    $usedMacs[] = strtolower($matches[1]);
                } elseif (preg_match('/^(?:virtio|e1000|rtl8139|vmxnet3)=([0-9A-Fa-f:]+)/i', $value, $matches)) {
                    $usedMacs[] = strtolower($matches[1]);
                } elseif (preg_match('/^([0-9A-Fa-f:]{17})/', $value, $matches)) {
                    $usedMacs[] = strtolower($matches[1]);
                }
            }
        }
    }

    $usedMacs = array_unique($usedMacs);
    logModuleCall('proxmox_custom', __FUNCTION__, 'Retrieved Used MAC Addresses', null, ['usedMacs' => $usedMacs]);
    return $usedMacs;
}

function proxmox_custom_startVM($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/status/start";
    logModuleCall('proxmox_custom', __FUNCTION__, 'Starting VM', ['URL' => $url, 'vmid' => $vmid], null);

    $headers = ["Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}"];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    logModuleCall('proxmox_custom', __FUNCTION__, 'Start VM Response', ['Response' => $response, 'cURL Error' => $curlError], null);

    if ($response === false) {
        throw new Exception('Start VM failed: ' . $curlError);
    }

    $data = json_decode($response, true);
    if (isset($data['errors']) && !empty($data['errors'])) {
        throw new Exception('Start VM failed: ' . json_encode($data['errors']));
    }

    if (isset($data['data'])) {
        $upid = $data['data'];
        proxmox_custom_waitForTaskCompletion($hostname, $apiTokenID, $apiTokenSecret, $node, $upid);
    } else {
        logModuleCall('proxmox_custom', __FUNCTION__, 'Could not retrieve task ID for start operation. Waiting for status change instead.', ['vmid' => $vmid], null);
        proxmox_custom_waitForVMStatus($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid, 'running');
    }

    logModuleCall('proxmox_custom', __FUNCTION__, 'Start VM Success', ['vmid' => $vmid], null);
    return true;
}

function proxmox_custom_saveDedicatedIP($serviceId, $publicIP)
{
    logModuleCall('proxmox_custom', __FUNCTION__, 'Saving Dedicated IP', ['serviceId' => $serviceId, 'publicIP' => $publicIP], null);
    Capsule::table('tblhosting')->where('id', $serviceId)->update(['dedicatedip' => $publicIP]);
    logModuleCall('proxmox_custom', __FUNCTION__, 'Dedicated IP Saved Successfully', ['serviceId' => $serviceId, 'publicIP' => $publicIP], null);
    return true;
}

function proxmox_custom_getVMConfig($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/config";
    $headers = ["Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}"];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    logModuleCall('proxmox_custom', __FUNCTION__, 'Get VM Config Response', ['Response' => $response, 'cURL Error' => $curlError], null);

    if ($response === false) {
        throw new Exception('Failed to retrieve VM config: ' . $curlError);
    }
    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300 && isset($data['data'])) {
        return $data['data'];
    } else {
        throw new Exception('Failed to retrieve VM config: Invalid response');
    }
}

function proxmox_custom_resizeDisk($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid, $disk, $increaseSize)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/resize";

    $postFieldsArray = ['disk' => $disk, 'size' => "+{$increaseSize}G",];
    $postFields = http_build_query($postFieldsArray);
    $headers = ["Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}", 'Content-Type: application/x-www-form-urlencoded',];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    logModuleCall('proxmox_custom', __FUNCTION__, 'Resize Disk Response', ['URL' => $url, 'Post Fields' => $postFieldsArray, 'Response' => $response, 'HTTP Code' => $httpCode, 'cURL Error' => $curlError], null);

    if ($response === false) {
        throw new Exception('Resize Disk failed: ' . $curlError);
    }
    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        if (isset($data['data'])) {
            $upid = $data['data'];
            proxmox_custom_waitForTaskCompletion($hostname, $apiTokenID, $apiTokenSecret, $node, $upid);
        }
        return true;
    } else {
        $errorMessage = isset($data['errors']) ? json_encode($data['errors']) : json_encode($data);
        throw new Exception('Resize Disk failed: ' . $errorMessage);
    }
}

// NOTE: The endpoint /cloudinit/generate does not seem to exist in the standard Proxmox API.
// This function assumes it's a custom endpoint. It has been modified to poll a task ID if one is returned.
function proxmox_custom_regenerateCloudInit($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/cloudinit/generate";
    $headers = ["Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}"];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    logModuleCall('proxmox_custom', __FUNCTION__, 'Regenerate Cloud-Init Response', ['Response' => $response, 'cURL Error' => $curlError], null);

    if ($response === false) {
        throw new Exception('Regenerate Cloud-Init failed: ' . $curlError);
    }
    $data = json_decode($response, true);
    if (isset($data['errors']) && !empty($data['errors'])) {
        throw new Exception('Regenerate Cloud-Init failed: ' . json_encode($data['errors']));
    }
    
    // If this custom endpoint returns a task ID, wait for it.
    if (isset($data['data'])) {
        $upid = $data['data'];
        proxmox_custom_waitForTaskCompletion($hostname, $apiTokenID, $apiTokenSecret, $node, $upid);
    }

    return true;
}

function proxmox_custom_waitForVMStatus($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid, $desiredStatus, $timeout = 300)
{
    $startTime = time();
    while (time() - $startTime < $timeout) {
        $status = proxmox_custom_getVMStatus($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid);
        if ($status === $desiredStatus) {
            return true;
        }
        sleep(5);
    }
    throw new Exception("VM did not reach status '{$desiredStatus}' within {$timeout} seconds.");
}

function proxmox_custom_getVMStatus($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/status/current";
    $headers = ["Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}"];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('Failed to retrieve VM status: ' . $curlError);
    }
    $data = json_decode($response, true);
    if (isset($data['data']['status'])) {
        return $data['data']['status'];
    } else {
        throw new Exception('Failed to retrieve VM status: Invalid response');
    }
}

function proxmox_custom_getPendingChanges($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/pending";
    $headers = ["Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}"];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('Failed to retrieve pending changes: ' . $curlError);
    }
    $data = json_decode($response, true);
    if (isset($data['data'])) {
        return $data['data'];
    } else {
        throw new Exception('Failed to retrieve pending changes: Invalid response');
    }
}

function proxmox_custom_getVMID($serviceId)
{
    $field = Capsule::table('tblcustomfields')->where('type', 'product')->where('fieldname', 'VMID')->first();
    if (!$field) {
        throw new Exception('VMID custom field not found.');
    }
    $value = Capsule::table('tblcustomfieldsvalues')->where('fieldid', $field->id)->where('relid', $serviceId)->first();
    if (!$value || empty($value->value)) {
        throw new Exception('VMID not found for service ID ' . $serviceId);
    }
    return $value->value;
}

function proxmox_custom_updateServiceDetails($serviceId, $userId, $publicIP, $hostnameSuffix = '.vps.example.com')
{
    $username = 'client' . $userId;
    $publicIPFormatted = str_replace('.', '-', $publicIP);
    $hostname = "Host_{$publicIPFormatted}{$hostnameSuffix}";

    Capsule::table('tblhosting')->where('id', $serviceId)->update(['username' => $username, 'domain' => $hostname,]);
    logModuleCall('proxmox_custom', __FUNCTION__, 'Updated Service Details', ['serviceId' => $serviceId, 'username' => $username, 'hostname' => $hostname,], null);
}

function proxmox_custom_GetOption(array $params, $id, $default = null)
{
    foreach ($params['configoptions'] as $optionName => $value) {
        $optionId = strpos($optionName, '|') !== false ? explode('|', $optionName, 2)[0] : $optionName;
        if ($optionId === $id) return $value;
    }
    foreach ($params['customfields'] as $fieldName => $value) {
        $fieldId = strpos($fieldName, '|') !== false ? explode('|', $fieldName, 2)[0] : $fieldName;
        if ($fieldId === $id) return $value;
    }
    $options = proxmox_custom_ConfigOptions();
    $i = 0;
    foreach ($options as $key => $value) {
        $i++;
        if ($key === $id) {
            if (isset($params['configoption' . $i]) && $params['configoption' . $i] !== '') {
                return $params['configoption' . $i];
            }
            break;
        }
    }
    return $default;
}

function proxmox_custom_setNetworkSpeed($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid, $networkSpeed)
{
    $vmConfig = proxmox_custom_getVMConfig($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid);
    if (!isset($vmConfig['net0'])) {
        throw new Exception('Network interface net0 not found in VM configuration.');
    }
    $currentNet0 = $vmConfig['net0'];
    $newNet0 = preg_replace('/(,|^)rate=\d+(\.\d+)?/', '', $currentNet0);
    $newNet0 .= ",rate={$networkSpeed}";

    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/config";
    $params = ['net0' => $newNet0];
    $postFields = http_build_query($params);
    $headers = ["Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}", 'Content-Type: application/x-www-form-urlencoded',];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    logModuleCall('proxmox_custom', __FUNCTION__, ['URL' => $url, 'Post Fields' => $params, 'Response' => $response, 'HTTP Code' => $httpCode, 'cURL Error' => $curlError,], null, null);

    if ($response === false) {
        throw new Exception('Failed to set network speed: ' . $curlError);
    }
    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        if (isset($data['data'])) {
            $upid = $data['data'];
            proxmox_custom_waitForTaskCompletion($hostname, $apiTokenID, $apiTokenSecret, $node, $upid);
        }
        return true;
    } else {
        $errorMessage = isset($data['errors']) ? json_encode($data['errors']) : json_encode($data);
        throw new Exception('Failed to set network speed: ' . $errorMessage);
    }
}

// --- ADMIN AREA FUNCTIONS --- //

function proxmox_custom_AdminServicesTabFields(array $params)
{
    try {
        $vmid = proxmox_custom_getVMID($params['serviceid']);
        $publicIP = proxmox_custom_getPublicIP($params['serviceid']);
        return [
            'Proxmox VMID' => $vmid,
            'Public IP' => $publicIP,
        ];
    } catch (Exception $e) {
        return [
            'Proxmox VM Info' => 'Could not retrieve VM details: ' . $e->getMessage(),
        ];
    }
}

function proxmox_custom_AdminCustomButtonArray()
{
    return [
        "Start VM" => "Start",
        "Stop VM"  => "Stop",
        "Reboot VM" => "Reboot",
    ];
}


// --- CLIENT AREA FUNCTIONS --- //

function proxmox_custom_Start(array $params)
{
    try {
        $serverHostname = $params['serverhostname'];
        $apiTokenID     = $params['serverusername'];
        $apiTokenSecret = $params['serverpassword'];
        $nodeConfigured = $params['configoption2'];
        $serviceId      = $params['serviceid'];
        $vmid = proxmox_custom_getVMID($serviceId);
        $node = proxmox_custom_resolveVMNode($serverHostname, $apiTokenID, $apiTokenSecret, $vmid, $nodeConfigured);
        proxmox_custom_startVM($serverHostname, $apiTokenID, $apiTokenSecret, $node, $vmid);
        return 'success';
    } catch (Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
}

function proxmox_custom_Stop(array $params)
{
    try {
        $serverHostname = $params['serverhostname'];
        $apiTokenID     = $params['serverusername'];
        $apiTokenSecret = $params['serverpassword'];
        $nodeConfigured = $params['configoption2'];
        $serviceId      = $params['serviceid'];
        $vmid = proxmox_custom_getVMID($serviceId);
        $node = proxmox_custom_resolveVMNode($serverHostname, $apiTokenID, $apiTokenSecret, $vmid, $nodeConfigured);
        proxmox_custom_stopVM($serverHostname, $apiTokenID, $apiTokenSecret, $node, $vmid);
        return 'success';
    } catch (Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
}

function proxmox_custom_ClientArea(array $params)
{
    $errorMessage = '';
    $vmStatus = 'Unknown';
    $cpuUsage = 0;
    $ramUsage = 0;
    $ramTotal = 0;
    $publicIP = 'Unknown';
    $rrdData = [];
    $vmid = '';
    $node = '';

    try {
        $serverHostname = $params['serverhostname'];
        $apiTokenID     = $params['serverusername'];
        $apiTokenSecret = $params['serverpassword'];
        $nodeConfigured = proxmox_custom_GetOption($params, 'Node');
        $serviceId      = $params['serviceid'];
        $vmid           = proxmox_custom_getVMID($serviceId);
        $node           = proxmox_custom_resolveVMNode($serverHostname, $apiTokenID, $apiTokenSecret, $vmid, $nodeConfigured);

        // Get standard resource usage
        $vmResources = proxmox_custom_getVMResourceUsage($serverHostname, $apiTokenID, $apiTokenSecret, $node, $vmid);
        $vmStatus = $vmResources['status'];
        $cpuUsage = $vmResources['cpu'];
        $ramUsage = $vmResources['mem'];
        $ramTotal = $vmResources['maxmem'];
        $publicIP = proxmox_custom_getPublicIP($serviceId);

        // Fetch RRD data for graphs
        $rrdData = proxmox_custom_getVMRRDData($serverHostname, $apiTokenID, $apiTokenSecret, $node, $vmid, 'hour');

    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }

    $consoleEnabled = proxmox_custom_GetOption($params, 'EnableConsole', '') === 'on';

    return [
        'tabOverviewReplacementTemplate' => 'templates/clientarea.tpl',
        'templateVariables'              => [
            'vmStatus'        => ucfirst($vmStatus),
            'cpuUsage'        => $cpuUsage,
            'ramUsage'        => $ramUsage,
            'ramTotal'        => $ramTotal,
            'publicIP'        => $publicIP,
            'serviceid'       => $params['serviceid'],
            'errorMessage'    => $errorMessage,
            'rrdData'         => json_encode($rrdData),
            'consoleEnabled'  => $consoleEnabled,
            'vmid'            => $vmid,
            'node'            => $node,
            'serverHostname'  => $serverHostname,
        ],
    ];
}

function proxmox_custom_getVMRRDData($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid, $timeframe = 'hour')
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/rrddata?timeframe={$timeframe}";
    $headers = ["Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}"];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('Failed to retrieve VM RRD data: ' . $curlError);
    }

    $data = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && isset($data['data'])) {
        return $data['data'];
    } else {
        $errorMessage = isset($data['errors']) ? json_encode($data['errors']) : 'Invalid response';
        throw new Exception('Failed to retrieve VM RRD data: ' . $errorMessage);
    }
}

function proxmox_custom_getVMResourceUsage($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/status/current";
    $headers = ["Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}"];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('Failed to retrieve VM resource usage: ' . $curlError);
    }
    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300 && isset($data['data'])) {
        $statusData = $data['data'];
        $cpuUsage = round(($statusData['cpu'] ?? 0) * 100, 2);
        $ramUsageBytes = $statusData['mem'] ?? 0;
        $ramTotalBytes = $statusData['maxmem'] ?? 0;
        $ramUsageMB = round($ramUsageBytes / (1024 * 1024), 2);
        $ramTotalMB = round($ramTotalBytes / (1024 * 1024), 2);
        return ['cpu' => $cpuUsage, 'mem' => $ramUsageMB, 'maxmem' => $ramTotalMB, 'status' => $statusData['status'] ?? 'unknown',];
    } else {
        throw new Exception("Failed to retrieve VM resource usage (HTTP {$httpCode}): " . ($response ?: 'No response'));
    }
}

function proxmox_custom_getPublicIP($serviceId)
{
    $hosting = Capsule::table('tblhosting')->where('id', $serviceId)->first(['dedicatedip']);
    if ($hosting && !empty($hosting->dedicatedip)) {
        return $hosting->dedicatedip;
    }

    $field = Capsule::table('tblcustomfields')->where('type', 'product')->where('fieldname', 'IP')->first();
    if ($field) {
        $value = Capsule::table('tblcustomfieldsvalues')->where('fieldid', $field->id)->where('relid', $serviceId)->first();
        if ($value && !empty($value->value)) {
            return $value->value;
        }
    }
    return 'Unknown';
}

function proxmox_custom_ClientAreaCustomButtonArray()
{
    return [
        "Start VM"         => "Start",
        "Stop VM"          => "Stop",
        "Reboot VM"        => "Reboot",
        "Console"          => "Console",
        "Reinstall Server" => "Reinstall",
    ];
}

function proxmox_custom_GoToPanel(array $params)
{
    $serverHostname = $params['serverhostname'];

    // Append the default Proxmox port if it's not already in the hostname
    if (strpos($serverHostname, ':') === false) {
        $serverHostname .= ':8006';
    }

    $panelUrl = "https://{$serverHostname}/";

    return [
        'act' => 'redirect',
        'url' => $panelUrl,
    ];
}

function proxmox_custom_Reboot(array $params)
{
    try {
        $serverHostname = $params['serverhostname'];
        $apiTokenID     = $params['serverusername'];
        $apiTokenSecret = $params['serverpassword'];
        $nodeConfigured = $params['configoption2'];
        $serviceId      = $params['serviceid'];
        $vmid = proxmox_custom_getVMID($serviceId);
        $node = proxmox_custom_resolveVMNode($serverHostname, $apiTokenID, $apiTokenSecret, $vmid, $nodeConfigured);
        proxmox_custom_rebootVM($serverHostname, $apiTokenID, $apiTokenSecret, $node, $vmid);
        return 'success';
    } catch (Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
}

function proxmox_custom_rebootVM($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/status/reboot";
    logModuleCall('proxmox_custom', __FUNCTION__, 'Rebooting VM', ['URL' => $url, 'vmid' => $vmid], null);

    $headers = ["Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}"];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    logModuleCall('proxmox_custom', __FUNCTION__, 'Reboot VM Response', ['Response' => $response, 'cURL Error' => $curlError], null);

    if ($response === false) {
        throw new Exception('Reboot VM failed: ' . $curlError);
    }
    $data = json_decode($response, true);
    if (isset($data['errors']) && !empty($data['errors'])) {
        throw new Exception('Reboot VM failed: ' . json_encode($data['errors']));
    }

    if (isset($data['data'])) {
        $upid = $data['data'];
        proxmox_custom_waitForTaskCompletion($hostname, $apiTokenID, $apiTokenSecret, $node, $upid);
    }
    
    logModuleCall('proxmox_custom', __FUNCTION__, 'Reboot VM Success', ['vmid' => $vmid], null);
    return true;
}

function proxmox_custom_Console(array $params)
{
    try {
        $serverHostname = $params['serverhostname'];
        $apiTokenID     = $params['serverusername'];
        $apiTokenSecret = $params['serverpassword'];
        $nodeConfigured = $params['configoption2'];
        $serviceId      = $params['serviceid'];
        $vmid           = proxmox_custom_getVMID($serviceId);
        $node           = proxmox_custom_resolveVMNode($serverHostname, $apiTokenID, $apiTokenSecret, $vmid, $nodeConfigured);
        $userId         = $params['userid'];

        // Build clean console URL (Proxmox JS handles VNC connection internally)
        $consoleUrl = "https://{$serverHostname}/?console=kvm&novnc=1&vmid={$vmid}"
            . "&vmname=vm{$vmid}&node={$node}&resize=off&cmd=";

        // Get auth ticket server-side (for PVEAuthCookie)
        $proxmoxUserID = 'client' . $userId . '@pve';
        $pvePassword = proxmox_custom_getPVEPassword($serviceId);

        if (empty($pvePassword)) {
            $pvePassword = bin2hex(random_bytes(16));
            proxmox_custom_savePVEPassword($serviceId, $pvePassword);
        }

        try {
            proxmox_custom_changeUserPassword($serverHostname, $apiTokenID, $apiTokenSecret, $proxmoxUserID, $pvePassword);
        } catch (Exception $e) {
            logModuleCall('proxmox_custom', __FUNCTION__, 'Password sync failed (non-fatal)', $e->getMessage(), null);
        }

        $authData = proxmox_custom_getAuthTicket($serverHostname, $proxmoxUserID, $pvePassword);
        $authTicket = $authData['ticket'];

        // Redirect through helper page to set PVEAuthCookie on Proxmox domain
        $hashData = json_encode(['ticket' => $authTicket, 'url' => $consoleUrl]);
        $loginUrl = "https://{$serverHostname}/consolevnc/console-login.html#" . rawurlencode($hashData);
        header("Location: {$loginUrl}");
        die();

    } catch (Exception $e) {
        logModuleCall('proxmox_custom', __FUNCTION__, ['serviceid' => $serviceId], $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Gets a PVE authentication ticket (PVEAuthCookie) using username/password.
 */
function proxmox_custom_getAuthTicket($hostname, $username, $password)
{
    $url = "https://{$hostname}/api2/json/access/ticket";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['username' => $username, 'password' => $password]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('Auth ticket request failed: ' . $curlError);
    }

    $data = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && isset($data['data']['ticket'])) {
        return $data['data'];
    } else {
        throw new Exception('Failed to get PVE auth ticket. HTTP ' . $httpCode);
    }
}

/**
 * Gets a VNC proxy ticket and port for websocket connection.
 */
function proxmox_custom_getVNCTicket($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/vncproxy";

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
        'Content-Type: application/x-www-form-urlencoded',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['websocket' => 1]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('VNC proxy request failed: ' . $curlError);
    }

    $data = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && isset($data['data']['ticket'])) {
        return $data['data'];
    } else {
        $errorDetails = $response ?: 'No response from server.';
        throw new Exception('Failed to get VNC ticket. API Response: ' . $errorDetails);
    }
}

/**
 * Gets a VNC proxy ticket using a PVE auth ticket (not API token).
 * This ensures the VNC ticket is tied to the same user as the websocket auth.
 */
function proxmox_custom_getVNCTicketWithAuth($hostname, $pveTicket, $csrfToken, $node, $vmid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/vncproxy";

    $headers = [
        "Cookie: PVEAuthCookie={$pveTicket}",
        "CSRFPreventionToken: {$csrfToken}",
        'Content-Type: application/x-www-form-urlencoded',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['websocket' => 1]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    logModuleCall('proxmox_custom', __FUNCTION__, ['node' => $node, 'vmid' => $vmid, 'httpCode' => $httpCode], $response, $curlError);

    if ($response === false) {
        throw new Exception('VNC proxy request failed: ' . $curlError);
    }

    $data = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && isset($data['data']['ticket'])) {
        return $data['data'];
    } else {
        $errorDetails = $response ?: 'No response from server.';
        throw new Exception('Failed to get VNC ticket. API Response: ' . $errorDetails);
    }
}

function proxmox_custom_Reinstall(array $params)
{
    try {
        $serviceId = $params['serviceid'];
        $userId    = $params['userid'];
        $currentTime = time();
        $lastReinstallTime = proxmox_custom_getLastReinstallTime($serviceId);

        if ($lastReinstallTime && ($currentTime - $lastReinstallTime) < 1800) {
            $remainingTime = 1800 - ($currentTime - $lastReinstallTime);
            $minutes = ceil($remainingTime / 60);
            throw new Exception("You must wait {$minutes} more minute(s) before you can reinstall the server.");
        }

        $terminateResult = proxmox_custom_TerminateAccount($params);
        if ($terminateResult !== 'success') {
            throw new Exception('Failed to terminate the existing VM: ' . $terminateResult);
        }
        $createResult = proxmox_custom_CreateAccount($params);
        if ($createResult !== 'success') {
            throw new Exception('Failed to create the new VM: ' . $createResult);
        }
        proxmox_custom_setLastReinstallTime($serviceId, $currentTime);
        return 'success';

    } catch (Exception $e) {
        logModuleCall('proxmox_custom', __FUNCTION__, ['serviceid' => $serviceId], $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

function proxmox_custom_getLastReinstallTime($serviceId)
{
    $field = Capsule::table('tblcustomfields')->where('type', 'product')->where('fieldname', 'Last Reinstall Time')->first();
    if (!$field) {
        return null;
    }
    $value = Capsule::table('tblcustomfieldsvalues')->where('fieldid', $field->id)->where('relid', $serviceId)->first();
    if ($value && !empty($value->value)) {
        return (int)$value->value;
    }
    return null;
}

function proxmox_custom_setLastReinstallTime($serviceId, $timestamp)
{
    $field = Capsule::table('tblcustomfields')->where('type', 'product')->where('fieldname', 'Last Reinstall Time')->first();
    if (!$field) {
        $fieldId = Capsule::table('tblcustomfields')->insertGetId(['type' => 'product', 'relid' => 0, 'fieldname' => 'Last Reinstall Time', 'fieldtype' => 'text', 'description' => 'Stores the timestamp of the last reinstallation', 'required' => '0', 'showorder' => '0', 'showinvoice' => '0', 'adminonly' => '1',]);
    } else {
        $fieldId = $field->id;
    }
    Capsule::table('tblcustomfieldsvalues')->updateOrInsert(['fieldid' => $fieldId, 'relid' => $serviceId], ['value' => $timestamp]);
}
/**
 * Resolve the current node of a VM from the cluster view.
 * Falls back to the configured node if it cannot be resolved.
 */
function proxmox_custom_resolveVMNode($hostname, $apiTokenID, $apiTokenSecret, $vmid, $fallbackNode = null)
{
    $url = "https://{$hostname}/api2/json/cluster/resources?type=vm";
    $headers = ["Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}"];

    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('Resolve node failed: ' . $curlError);
        }

        $data = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && isset($data['data'])) {
            foreach ($data['data'] as $resource) {
                if (isset($resource['vmid']) && (int)$resource['vmid'] === (int)$vmid && !empty($resource['node'])) {
                    return $resource['node'];
                }
            }
        } else {
            throw new Exception('Resolve node failed: Invalid response');
        }
    } catch (Exception $e) {
        // Log but keep going with the fallback node so actions still work.
        logModuleCall('proxmox_custom', __FUNCTION__, ['vmid' => $vmid, 'fallback' => $fallbackNode], $e->getMessage(), null);
    }

    return $fallbackNode;
}
