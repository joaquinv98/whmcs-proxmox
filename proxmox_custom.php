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
				'10'   => '10 Mbps',
				'20'   => '20 Mbps',
				'100'  => '100 Mbps',
				'500'  => '500 Mbps',
				'1000' => '1 Gbps',
			],
			'Default' => '20', // Set default as needed
			'Description' => 'Network speed in Mbps',
		],
    ];
}

function proxmox_custom_CreateAccount(array $params)
{
    // Increase the script execution time limit
    set_time_limit(600); // Adjust as needed (e.g., 10 minutes)

    // Retrieve parameters
    $serviceId       = $params['serviceid'];
    $serverId        = $params['serverid'];
    $serverHostname  = $params['serverhostname'];
    $apiTokenID      = $params['serverusername'];
    $apiTokenSecret  = $params['serverpassword'];
    $node            = $params['configoption2'];
    $password        = $params['password'];
    $userId          = $params['userid'];

    // Retrieve configurable options using the GetOption function
    $cpuCores     = proxmox_custom_GetOption($params, 'CPUCores', 1); // 1 core per unit
    $ramGB        = proxmox_custom_GetOption($params, 'RAM', 1); // expected in GB
    $diskSizeGB   = proxmox_custom_GetOption($params, 'DiskSize', 20); // expected in GB
    $networkSpeed = proxmox_custom_GetOption($params, 'NetworkSpeed', '100'); // expected in MB/s
    $templateId   = proxmox_custom_GetOption($params, 'TemplateID', '101');

    // Convert RAM from GB to MB if necessary
    $ramMB = $ramGB * 1024; // Convert GB to MB

    // Set VM Name
    $vmName = 'vm' . $serviceId;

    try {
        // Log the start of account creation
        logModuleCall(
            'proxmox_custom',
            __FUNCTION__,
            'Starting account creation',
            null,
            null
        );

        // 1. Generate VMID
        $newVMID = proxmox_custom_generateNewVMID($serverHostname, $apiTokenID, $apiTokenSecret);
        logModuleCall(
            'proxmox_custom',
            __FUNCTION__,
            'Generated VMID',
            ['newVMID' => $newVMID],
            null
        );

        // 2. Clone VM
        proxmox_custom_cloneVM($serverHostname, $apiTokenID, $apiTokenSecret, $node, $templateId, $newVMID, $vmName);
        logModuleCall(
            'proxmox_custom',
            __FUNCTION__,
            'Cloned VM',
            [
                'templateId' => $templateId,
                'newVMID'    => $newVMID,
                'vmName'     => $vmName
            ],
            null
        );

        // 3. Save VM details
        proxmox_custom_saveVMDetails($serviceId, $newVMID);
        logModuleCall(
            'proxmox_custom',
            __FUNCTION__,
            'Saved VM Details',
            ['serviceId' => $serviceId, 'newVMID' => $newVMID],
            null
        );

        // 4. Create Proxmox user if it doesn't exist
        $proxmoxUserID = 'u' . $userId . $params['serviceid'] . '@pve'; // Adjust realm if needed
        $userExists    = proxmox_custom_userExists($serverHostname, $apiTokenID, $apiTokenSecret, $proxmoxUserID);
        logModuleCall(
            'proxmox_custom',
            __FUNCTION__,
            'Checked if Proxmox user exists',
            ['proxmoxUserID' => $proxmoxUserID, 'userExists' => $userExists],
            null
        );

        if (!$userExists) {
            proxmox_custom_createUser($serverHostname, $apiTokenID, $apiTokenSecret, $proxmoxUserID, $password);
            logModuleCall(
                'proxmox_custom',
                __FUNCTION__,
                'Created Proxmox user',
                ['proxmoxUserID' => $proxmoxUserID],
                null
            );
        }

        // 5. Assign permissions to the user for the VM
        $path  = "/vms/{$newVMID}";
        $roleid = 'PVEVMUser'; // Ensure this role exists in Proxmox
        proxmox_custom_assignPermissions($serverHostname, $apiTokenID, $apiTokenSecret, $proxmoxUserID, $path, $roleid);
        logModuleCall(
            'proxmox_custom',
            __FUNCTION__,
            'Assigned Permissions',
            [
                'proxmoxUserID' => $proxmoxUserID,
                'path'          => $path,
                'roleid'        => $roleid
            ],
            null
        );

        // 6. Sleep for 3 minutes (180 seconds)
        logModuleCall(
            'proxmox_custom',
            __FUNCTION__,
            'Sleeping for 3 minutes before configuring VM',
            null,
            null
        );
        sleep(90);
        logModuleCall(
            'proxmox_custom',
            __FUNCTION__,
            'Woke up from sleep',
            null,
            null
        );

        // 7. Assign MAC address and Public IP
        list($macAddress, $publicIP) = proxmox_custom_getAvailableMAC($serverId, $serverHostname, $apiTokenID, $apiTokenSecret, $node);
        logModuleCall(
            'proxmox_custom',
            __FUNCTION__,
            'Assigned MAC and IP',
            [
                'macAddress' => $macAddress,
                'publicIP'   => $publicIP
            ],
            null
        );

        // 8. Stop VM before applying configurations
        proxmox_custom_stopVM($serverHostname, $apiTokenID, $apiTokenSecret, $node, $newVMID);
        logModuleCall(
            'proxmox_custom',
            __FUNCTION__,
            'Stopped VM',
            ['newVMID' => $newVMID],
            null
        );

        // Wait until VM is stopped
        proxmox_custom_waitForVMStatus($serverHostname, $apiTokenID, $apiTokenSecret, $node, $newVMID, 'stopped');

        // 9. Resize Disk

        // Base disk size in GB (the size of the disk in your template VM)
        $baseDiskSize = 10; // Adjust this value to match your template's disk size

        // Calculate the increase amount
        $increaseSize = $diskSizeGB - $baseDiskSize;

        // Ensure the increase amount is positive
        if ($increaseSize > 0) {
            // Proceed to resize the disk
            try {
                proxmox_custom_resizeDisk($serverHostname, $apiTokenID, $apiTokenSecret, $node, $newVMID, 'scsi0', $increaseSize);
                logModuleCall(
                    'proxmox_custom',
                    __FUNCTION__,
                    'Resized Disk',
                    [
                        'newVMID'      => $newVMID,
                        'disk'         => 'scsi0',
                        'increaseSize' => $increaseSize,
                    ],
                    null
                );
            } catch (Exception $e) {
                logModuleCall(
                    'proxmox_custom',
                    __FUNCTION__,
                    'Resize Disk Failed',
                    [
                        'newVMID'      => $newVMID,
                        'disk'         => 'scsi0',
                        'increaseSize' => $increaseSize,
                        'Exception'    => $e->getMessage(),
                    ],
                    null
                );
                // Proceed with the rest of the configuration
            }
        } else {
            // No need to resize the disk
            logModuleCall(
                'proxmox_custom',
                __FUNCTION__,
                'No Disk Resize Needed',
                [
                    'newVMID'      => $newVMID,
                    'disk'         => 'scsi0',
                    'desiredSize'  => $diskSizeGB,
                    'baseDiskSize' => $baseDiskSize,
                ],
                null
            );
        }

        // 10. Configure VM resources
        proxmox_custom_configureVM(
            $serverHostname,
            $apiTokenID,
            $apiTokenSecret,
            $node,
            $newVMID,
            $cpuCores,
            $ramMB,
            $userId,
            $password,
            $macAddress
        );

        // 11. Set Network Speed
        proxmox_custom_setNetworkSpeed($serverHostname, $apiTokenID, $apiTokenSecret, $node, $newVMID, $networkSpeed);
		sleep(2);
        // 12. Regenerate Cloud-Init configuration
        proxmox_custom_regenerateCloudInit($serverHostname, $apiTokenID, $apiTokenSecret, $node, $newVMID);
        logModuleCall(
            'proxmox_custom',
            __FUNCTION__,
            'Regenerated Cloud-Init Configuration',
            ['vmid' => $newVMID],
            null
        );
		sleep(3);
        // 13. Assign the Public IP to the "Dedicated IP" field
        if (!empty($publicIP)) {
            proxmox_custom_saveDedicatedIP($serviceId, $publicIP);
            logModuleCall(
                'proxmox_custom',
                __FUNCTION__,
                'Assigned Dedicated IP',
                ['publicIP' => $publicIP],
                null
            );
        }
		sleep(2);
        // 14. Start VM
        proxmox_custom_startVM($serverHostname, $apiTokenID, $apiTokenSecret, $node, $newVMID);
        logModuleCall(
            'proxmox_custom',
            __FUNCTION__,
            'Started VM',
            ['newVMID' => $newVMID],
            null
        );

        // 15. Update the service's username and hostname
        if (!empty($publicIP)) {
            proxmox_custom_updateServiceDetails($serviceId, $userId, $publicIP);
            logModuleCall(
                'proxmox_custom',
                __FUNCTION__,
                'Updated Service Details',
                [
                    'serviceId' => $serviceId,
                    'username'  => $userId,
                    'hostname'  => "Host_" . str_replace('.', '-', $publicIP) . ".vps.ntc.ar",
                ],
                null
            );
        }

        // 16. Mark the service as active
        logModuleCall(
            'proxmox_custom',
            __FUNCTION__,
            'Service marked as active',
            null,
            null
        );

        return 'success';
    } catch (Exception $e) {
        // Log the error with detailed information
        logModuleCall(
            'proxmox_custom',
            __FUNCTION__,
            [
                'serviceid'       => $serviceId,
                'serverid'        => $serverId,
                'serverhostname'  => $serverHostname,
                'apiTokenID'      => $apiTokenID,
                'apiTokenSecret'  => '***', // Mask sensitive information
                'templateId'      => $templateId,
                'node'            => $node,
                'vmName'          => $vmName,
                'cpuCores'        => $cpuCores,
                'ramMB'           => $ramMB,
                'diskSizeGB'      => $diskSizeGB,
                'password'        => '***', // Mask sensitive information
                'userid'          => $userId
            ],
            $e->getMessage(),
            $e->getTraceAsString()
        );

        // Return the error message
        return 'Error: ' . $e->getMessage();
    }
}

function proxmox_custom_SuspendAccount(array $params)
{
    // Retrieve parameters
    $serviceId       = $params['serviceid'];
    $serverHostname  = $params['serverhostname'];
    $apiTokenID      = $params['serverusername'];
    $apiTokenSecret  = $params['serverpassword'];
    $node            = $params['configoption2'];
	$roleid = 'PVEVMUser';

    // Get VMID
    $vmid = proxmox_custom_getVMID($serviceId);

    // Get WHMCS User ID
    $userId         = $params['userid'];
    $proxmoxUserID  = 'u' . $userId . $params['serviceid'] . '@pve'; // Adjust realm if needed

    try {
        // Stop the VM (stop, not shutdown)
        proxmox_custom_stopVM($serverHostname, $apiTokenID, $apiTokenSecret, $node, $vmid);

        // Remove permissions for the user to that VM
        $path = "/vms/{$vmid}";
        proxmox_custom_removePermissions($serverHostname, $apiTokenID, $apiTokenSecret, $proxmoxUserID, $path, $roleid);

        return 'success';
    } catch (Exception $e) {
        logModuleCall(
            'proxmox_custom',
            __FUNCTION__,
            ['serviceid' => $serviceId],
            $e->getMessage(),
            $e->getTraceAsString()
        );
        return 'Error: ' . $e->getMessage();
    }
}

function proxmox_custom_UnsuspendAccount(array $params)
{
    // Retrieve parameters
    $serviceId       = $params['serviceid'];
    $serverHostname  = $params['serverhostname'];
    $apiTokenID      = $params['serverusername'];
    $apiTokenSecret  = $params['serverpassword'];
    $node            = $params['configoption2'];

    // Get VMID
    $vmid = proxmox_custom_getVMID($serviceId);

    // Get WHMCS User ID
    $userId         = $params['userid'];
    $proxmoxUserID  = 'u' . $userId . $params['serviceid'] . '@pve'; // Adjust realm if needed

    try {
        // Assign permissions to the user for the VM
        $path   = "/vms/{$vmid}";
        $roleid = 'PVEVMUser';
        proxmox_custom_assignPermissions($serverHostname, $apiTokenID, $apiTokenSecret, $proxmoxUserID, $path, $roleid);

        // Start the VM
        proxmox_custom_startVM($serverHostname, $apiTokenID, $apiTokenSecret, $node, $vmid);

        return 'success';
    } catch (Exception $e) {
        logModuleCall(
            'proxmox_custom',
            __FUNCTION__,
            ['serviceid' => $serviceId],
            $e->getMessage(),
            $e->getTraceAsString()
        );
        return 'Error: ' . $e->getMessage();
    }
}

function proxmox_custom_TerminateAccount(array $params)
{
    // Retrieve parameters
    $serviceId       = $params['serviceid'];
    $serverHostname  = $params['serverhostname'];
    $apiTokenID      = $params['serverusername'];
    $apiTokenSecret  = $params['serverpassword'];
    $node            = $params['configoption2'];
    $roleid          = 'PVEVMUser';

    // Get VMID
    $vmid = proxmox_custom_getVMID($serviceId);

    // Get WHMCS User ID
    $userId         = $params['userid'];
    $proxmoxUserID = 'u' . $userId . $params['serviceid'] . '@pve'; // Adjust realm if needed

    try {
        // Check if VM is running
        $isRunning = proxmox_custom_isVMRunning($serverHostname, $apiTokenID, $apiTokenSecret, $node, $vmid);

        if ($isRunning) {
            // Stop the VM
            proxmox_custom_stopVM($serverHostname, $apiTokenID, $apiTokenSecret, $node, $vmid);
            logModuleCall(
                'proxmox_custom',
                __FUNCTION__,
                'Stopped VM',
                ['vmid' => $vmid],
                null
            );
        }

        // Check if the Proxmox user exists
        $userExists = proxmox_custom_userExists($serverHostname, $apiTokenID, $apiTokenSecret, $proxmoxUserID);
        logModuleCall(
            'proxmox_custom',
            __FUNCTION__,
            'Checked User Existence',
            ['proxmoxUserID' => $proxmoxUserID, 'userExists' => $userExists],
            null
        );

        if ($userExists) {
            // Remove permissions before destroying the VM
            $path = "/vms/{$vmid}";
            proxmox_custom_removePermissions($serverHostname, $apiTokenID, $apiTokenSecret, $proxmoxUserID, $path, $roleid);
            logModuleCall(
                'proxmox_custom',
                __FUNCTION__,
                'Removed Permissions',
                [
                    'proxmoxUserID' => $proxmoxUserID,
                    'path'          => $path,
                    'roleid'        => $roleid
                ],
                null
            );
        } else {
            logModuleCall(
                'proxmox_custom',
                __FUNCTION__,
                'User Does Not Exist, Skipping Remove Permissions',
                ['proxmoxUserID' => $proxmoxUserID],
                null
            );
        }

        // Destroy the VM
        proxmox_custom_destroyVM($serverHostname, $apiTokenID, $apiTokenSecret, $node, $vmid);
        logModuleCall(
            'proxmox_custom',
            __FUNCTION__,
            'Destroyed VM',
            ['vmid' => $vmid],
            null
        );

        if ($userExists) {
            // Check if the user has permissions elsewhere
            $hasOtherPermissions = proxmox_custom_userHasOtherPermissions($serverHostname, $apiTokenID, $apiTokenSecret, $proxmoxUserID, $vmid);
            logModuleCall(
                'proxmox_custom',
                __FUNCTION__,
                'Checked Other Permissions',
                ['proxmoxUserID' => $proxmoxUserID, 'hasOtherPermissions' => $hasOtherPermissions],
                null
            );

            if (!$hasOtherPermissions) {
                // Delete the user
                proxmox_custom_deleteUser($serverHostname, $apiTokenID, $apiTokenSecret, $proxmoxUserID);
                logModuleCall(
                    'proxmox_custom',
                    __FUNCTION__,
                    'Deleted User',
                    ['proxmoxUserID' => $proxmoxUserID],
                    null
                );
            } else {
                logModuleCall(
                    'proxmox_custom',
                    __FUNCTION__,
                    'User Has Other Permissions, Skipping Delete',
                    ['proxmoxUserID' => $proxmoxUserID],
                    null
                );
            }
        }

        return 'success';
    } catch (Exception $e) {
        logModuleCall(
            'proxmox_custom',
            __FUNCTION__,
            ['serviceid' => $serviceId],
            $e->getMessage(),
            $e->getTraceAsString()
        );
        return 'Error: ' . $e->getMessage();
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
        CURLOPT_SSL_VERIFYPEER => false, // Set to true in production
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
        'propagate' => 0, // Use the same value as when assigning permissions
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
        CURLOPT_SSL_VERIFYPEER => false, // Set to true in production
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Log the response for debugging
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        [
            'URL'         => $url,
            'Post Fields' => $postFieldsArray,
            'Headers'     => $headers,
        ],
        [
            'Response'    => $response,
            'HTTP Code'   => $httpCode,
            'cURL Error'  => $curlError,
        ]
    );

    if ($response === false) {
        throw new Exception('Remove permissions failed: ' . $curlError);
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        // Success
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
        CURLOPT_SSL_VERIFYPEER => false, // Set to true in production
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
                    return true; // User has permissions elsewhere
                }
            }
        }
        return false; // User does not have permissions elsewhere
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
        CURLOPT_SSL_VERIFYPEER => false, // Set to true in production
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
        CURLOPT_SSL_VERIFYPEER => false, // Set to true in production
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Log the response for debugging
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        [
            'URL' => $url,
            'Headers' => $headers,
        ],
        [
            'Response'   => $response,
            'HTTP Code'  => $httpCode,
            'cURL Error' => $curlError,
        ]
    );

    if ($response === false) {
        throw new Exception('Check VM status failed: ' . $curlError);
    }

    $data = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && isset($data['data']['status'])) {
        if ($data['data']['status'] === 'running') {
            return true;
        } else {
            return false;
        }
    } else {
        $errorMessage = isset($data['errors']) ? json_encode($data['errors']) : $response;
        throw new Exception('Check VM status failed: ' . $errorMessage);
    }
}

// Function to stop a VM (stop, not shutdown)
function proxmox_custom_stopVM($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/status/stop";

    // Log the stop VM request
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Stopping VM',
        ['URL' => $url, 'vmid' => $vmid],
        null
    );

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false, // Set to true in production
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Log the response
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Stop VM Response',
        ['Response' => $response, 'cURL Error' => $curlError],
        null
    );

    if ($response === false) {
        throw new Exception('Stop VM failed: ' . $curlError);
    }

    // Optionally, verify the response to ensure the VM was stopped
    $data = json_decode($response, true);
    if (isset($data['errors']) && !empty($data['errors'])) {
        throw new Exception('Stop VM failed: ' . json_encode($data['errors']));
    }

    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Stop VM Success',
        ['vmid' => $vmid],
        null
    );

    return true;
}


// Function to destroy a VM
function proxmox_custom_destroyVM($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}";

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false, // Set to true in production
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

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    } else {
        $data = json_decode($response, true);
        $errorMessage = isset($data['errors']) ? json_encode($data['errors']) : json_encode($data);
        throw new Exception('Destroy VM failed: ' . $errorMessage);
    }
}
function proxmox_custom_generateNewVMID($hostname, $apiTokenID, $apiTokenSecret)
{
    $url = "https://{$hostname}/api2/json/cluster/nextid";

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false, // Set to true in production
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Log the response and any cURL errors
    logModuleCall(
        'proxmox_custom',
        'Generate New VMID',
        [
            'URL' => $url,
        ],
        [
            'Response' => $response,
            'cURL Error' => $curlError,
        ]
    );

    if ($response === false) {
        throw new Exception('Get next VMID failed: ' . $curlError);
    }

    $data = json_decode($response, true);
    if (isset($data['data'])) {
        return $data['data'];
    } else {
        throw new Exception('Get next VMID failed: Invalid response');
    }
}
function proxmox_custom_cloneVM($hostname, $apiTokenID, $apiTokenSecret, $node, $templateId, $newVMID, $vmName)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$templateId}/clone";

    // Log the clone VM request
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Cloning VM',
        ['URL' => $url, 'newVMID' => $newVMID, 'vmName' => $vmName],
        null
    );

    $postFieldsArray = [
        'newid'  => $newVMID,
        'name'   => $vmName,
        'full'   => 1,
        'target' => $node,
    ];

    $postFields = http_build_query($postFieldsArray, '', '&', PHP_QUERY_RFC3986);

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
        'Content-Type: application/x-www-form-urlencoded',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false, // Set to true in production
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Log the response
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Clone VM Response',
        ['Response' => $response, 'cURL Error' => $curlError],
        null
    );

    if ($response === false) {
        throw new Exception('Clone VM failed: ' . $curlError);
    }

    // Optionally, verify the response to ensure cloning was successful
    $data = json_decode($response, true);
    if (isset($data['errors']) && !empty($data['errors'])) {
        throw new Exception('Clone VM failed: ' . json_encode($data['errors']));
    }

    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Clone VM Success',
        ['newVMID' => $newVMID],
        null
    );

    return true;
}

// Function to configure VM
function proxmox_custom_configureVM($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid, $cpuCores, $ram, $userId, $password, $macAddress)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/config";

    // Cloud-Init user and password settings
    $cloudInitUser     = 'u' . $userId . $params['serviceid']; // Set username to 'u' + WHMCS user ID
    $cloudInitPassword = $password;    // Set password to service password

    // Prepare network configuration with the assigned MAC address
    $netConfig = "virtio={$macAddress},bridge=vmbr1,firewall=0";

    $postFieldsArray = [
        'cores'        => $cpuCores,
        'memory'       => $ram,
        'ciuser'       => $cloudInitUser,
        'cipassword'   => $cloudInitPassword,
        'agent'        => 1,
        'net0'         => $netConfig,
        // Removed 'ide2' parameter
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
        CURLOPT_SSL_VERIFYPEER => false, // Set to true in production
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Log the response
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Configure VM Response',
        ['Response' => $response, 'cURL Error' => $curlError],
        null
    );

    if ($response === false) {
        throw new Exception('Configure VM failed: ' . $curlError);
    }

    // Verify the response to ensure configurations were applied
    $data = json_decode($response, true);
    if (isset($data['errors']) && !empty($data['errors'])) {
        throw new Exception('Configure VM failed: ' . json_encode($data['errors']));
    }

    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Configure VM Success',
        ['vmid' => $vmid, 'status' => 'Configurations applied successfully'],
        null
    );

    return true;
}

// Modify the saveVMDetails function to accept $publicIP
function proxmox_custom_saveVMDetails($serviceId, $vmid, $publicIP = null)
{
    // Update service custom fields
    $fields = Capsule::table('tblcustomfields')
        ->where('type', 'product')
        ->whereIn('fieldname', ['VMID', 'IP'])
        ->pluck('id', 'fieldname');

    // Save VMID
    if (isset($fields['VMID'])) {
        Capsule::table('tblcustomfieldsvalues')->updateOrInsert(
            ['fieldid' => $fields['VMID'], 'relid' => $serviceId],
            ['value' => $vmid]
        );
    } else {
        // Create VMID custom field if it doesn't exist
        $fieldId = Capsule::table('tblcustomfields')->insertGetId([
            'type' => 'product',
            'relid' => 0,
            'fieldname' => 'VMID',
            'fieldtype' => 'text',
            'description' => 'Proxmox VMID',
            'required' => '0',
            'showorder' => '0',
            'showinvoice' => '0',
        ]);
        Capsule::table('tblcustomfieldsvalues')->updateOrInsert(
            ['fieldid' => $fieldId, 'relid' => $serviceId],
            ['value' => $vmid]
        );
    }

    // Save Public IP
    if ($publicIP && isset($fields['IP'])) {
        Capsule::table('tblcustomfieldsvalues')->updateOrInsert(
            ['fieldid' => $fields['IP'], 'relid' => $serviceId],
            ['value' => $publicIP]
        );
    } elseif ($publicIP) {
        // Create IP custom field if it doesn't exist
        $fieldId = Capsule::table('tblcustomfields')->insertGetId([
            'type' => 'product',
            'relid' => 0,
            'fieldname' => 'IP',
            'fieldtype' => 'text',
            'description' => 'Assigned Public IP',
            'required' => '0',
            'showorder' => '0',
            'showinvoice' => '0',
        ]);
        Capsule::table('tblcustomfieldsvalues')->updateOrInsert(
            ['fieldid' => $fieldId, 'relid' => $serviceId],
            ['value' => $publicIP]
        );
    }
}

function proxmox_custom_createUser($hostname, $apiTokenID, $apiTokenSecret, $userid, $password)
{
    $url = "https://{$hostname}/api2/json/access/users";

    $postFieldsArray = [
        'userid' => $userid,
        'password' => $password,
        'enable' => 1,
    ];

    $postFields = http_build_query($postFieldsArray, '', '&', PHP_QUERY_RFC3986);

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
        'Content-Type: application/x-www-form-urlencoded',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false, // Set to true in production
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Log the response and any cURL errors
    logModuleCall(
        'proxmox_custom',
        'Create User',
        [
            'URL' => $url,
            'Post Fields' => '[omitted]', // Do not log sensitive data
        ],
        [
            'Response' => $response,
            'HTTP Code' => $httpCode,
            'cURL Error' => $curlError,
        ]
    );

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

    $postFieldsArray = [
        'path' => $path,
        'users' => $userid,
        'roles' => $roleid,
        'propagate' => 0, // Do not propagate permissions
    ];

    $postFields = http_build_query($postFieldsArray, '', '&', PHP_QUERY_RFC3986);

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
        'Content-Type: application/x-www-form-urlencoded',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $postFields, // Pass encoded query string
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false, // Set to true in production
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Log the response and any cURL errors
    logModuleCall(
        'proxmox_custom',
        'Assign Permissions',
        [
            'URL' => $url,
            'Post Fields' => $postFieldsArray,
        ],
        [
            'Response' => $response,
            'HTTP Code' => $httpCode,
            'cURL Error' => $curlError,
        ]
    );

    if ($response === false) {
        throw new Exception('Assign permissions failed: ' . $curlError);
    }

    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        // Success
        return true;
    } else {
        // Failure
        $errorMessage = isset($data['errors']) ? json_encode($data['errors']) : json_encode($data);
        throw new Exception('Assign permissions failed: ' . $errorMessage);
    }
}

function proxmox_custom_getAvailableMAC($serverId, $hostname, $apiTokenID, $apiTokenSecret, $node)
{
    // Log the start of MAC assignment
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Fetching Assigned IP Addresses from server configuration',
        ['serverId' => $serverId],
        null
    );

    // Get the "Assigned IP Addresses" field from the server configuration
    $serverDetails = Capsule::table('tblservers')->where('id', $serverId)->first();

    if (!$serverDetails) {
        throw new Exception('Server details not found.');
    }

    $assignedIPsRaw = $serverDetails->assignedips; // This field contains the "Assigned IP Addresses"

    if (empty($assignedIPsRaw)) {
        throw new Exception('No MAC addresses configured in the "Assigned IP Addresses" field.');
    }

    // Parse the assigned IPs to create a MAC-IP mapping
    $macPool = [];
    $lines = explode("\n", $assignedIPsRaw);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($mac, $ip) = explode('=', $line);
            $mac = trim($mac);
            $ip  = trim($ip);
            $macPool[$mac] = $ip;
        } else {
            // If no IP is provided, set IP as empty
            $macPool[$line] = '';
        }
    }

    if (empty($macPool)) {
        throw new Exception('MAC Address Pool is empty or invalid.');
    }

    // Log the parsed MAC-IP pool
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Parsed MAC-IP Pool',
        ['macPool' => $macPool],
        null
    );

    // Get list of used MAC addresses on the node
    $usedMacs = proxmox_custom_getUsedMACs($hostname, $apiTokenID, $apiTokenSecret, $node);
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Retrieved Used MAC Addresses',
        ['usedMacs' => $usedMacs],
        null
    );

    // Find an available MAC address
    foreach ($macPool as $mac => $ip) {
        if (!in_array(strtolower($mac), array_map('strtolower', $usedMacs))) {
            // Found an available MAC address
            logModuleCall(
                'proxmox_custom',
                __FUNCTION__,
                'Found Available MAC Address',
                ['macAddress' => $mac, 'publicIP' => $ip],
                null
            );
            return [$mac, $ip];
        }
    }

    throw new Exception('No available MAC addresses in the pool.');
}

function proxmox_custom_getUsedMACs($hostname, $apiTokenID, $apiTokenSecret, $node)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu";

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
    ];

    // Initialize cURL session
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false, // **Set to true in production**
        CURLOPT_SSL_VERIFYHOST => false, // **Set to true in production**
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    // Execute cURL request
    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Log the response and any cURL errors
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Get VM List Response',
        [
            'URL' => $url,
            'Headers' => $headers,
        ],
        [
            'Response'    => $response,
            'cURL Error'  => $curlError,
        ]
    );

    // Handle cURL errors
    if ($response === false) {
        throw new Exception('Failed to retrieve VM list: ' . $curlError);
    }

    // Decode the JSON response
    $data = json_decode($response, true);

    // Validate the response
    if (!isset($data['data']) || !is_array($data['data'])) {
        throw new Exception('Invalid response when retrieving VM list.');
    }

    $usedMacs = [];

    // Iterate through each VM to extract MAC addresses
    foreach ($data['data'] as $vm) {
        $vmid = $vm['vmid'];

        try {
            // Fetch the VM's configuration
            $vmConfig = proxmox_custom_getVMConfig($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid);
        } catch (Exception $e) {
            // Log the error and continue with the next VM
            logModuleCall(
                'proxmox_custom',
                __FUNCTION__,
                "Failed to get config for VMID {$vmid}",
                null,
                $e->getMessage()
            );
            continue;
        }

        // Iterate through the VM's configuration to find network interfaces
        foreach ($vmConfig as $key => $value) {
			if (preg_match('/^net\d+/', $key)) {
				// Attempt to extract the MAC address using different patterns
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

    // Remove duplicate MAC addresses
    $usedMacs = array_unique($usedMacs);

    // Log the retrieved used MAC addresses
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Retrieved Used MAC Addresses',
        null,
        ['usedMacs' => $usedMacs]
    );

    return $usedMacs;
}

function proxmox_custom_startVM($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/status/start";

    // Log the start VM request
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Starting VM',
        ['URL' => $url, 'vmid' => $vmid],
        null
    );

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false, // Set to true in production
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Log the response
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Start VM Response',
        ['Response' => $response, 'cURL Error' => $curlError],
        null
    );

    if ($response === false) {
        throw new Exception('Start VM failed: ' . $curlError);
    }

    // Optionally, verify the response to ensure the VM was started
    $data = json_decode($response, true);
    if (isset($data['errors']) && !empty($data['errors'])) {
        throw new Exception('Start VM failed: ' . json_encode($data['errors']));
    }

    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Start VM Success',
        ['vmid' => $vmid],
        null
    );

    return true;
}

function proxmox_custom_saveDedicatedIP($serviceId, $publicIP)
{
    // Log the dedicated IP assignment
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Saving Dedicated IP',
        ['serviceId' => $serviceId, 'publicIP' => $publicIP],
        null
    );

    Capsule::table('tblhosting')
        ->where('id', $serviceId)
        ->update(['dedicatedip' => $publicIP]);

    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Dedicated IP Saved Successfully',
        ['serviceId' => $serviceId, 'publicIP' => $publicIP],
        null
    );

    return true;
}

function proxmox_custom_getVMConfig($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/config";

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false, // Set to true in production
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Log the response
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Get VM Config Response',
        ['Response' => $response, 'cURL Error' => $curlError],
        null
    );

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

    $postFieldsArray = [
        'disk' => $disk, // e.g., 'scsi0'
        'size' => "+{$increaseSize}G", // Prefix with '+' to indicate increase
    ];

    $postFields = http_build_query($postFieldsArray);

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

    // Log the response
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Resize Disk Response',
        [
            'URL' => $url,
            'Post Fields' => $postFieldsArray,
            'Response' => $response,
            'HTTP Code' => $httpCode,
            'cURL Error' => $curlError
        ],
        null
    );

    if ($response === false) {
        throw new Exception('Resize Disk failed: ' . $curlError);
    }

    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        // Success
        return true;
    } else {
        // Failure
        $errorMessage = isset($data['errors']) ? json_encode($data['errors']) : json_encode($data);
        throw new Exception('Resize Disk failed: ' . $errorMessage);
    }
}

function proxmox_custom_regenerateCloudInit($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/cloudinit/generate";

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
    ];

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

    // Log the response
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Regenerate Cloud-Init Response',
        ['Response' => $response, 'cURL Error' => $curlError],
        null
    );

    if ($response === false) {
        throw new Exception('Regenerate Cloud-Init failed: ' . $curlError);
    }

    $data = json_decode($response, true);
    if (isset($data['errors']) && !empty($data['errors'])) {
        throw new Exception('Regenerate Cloud-Init failed: ' . json_encode($data['errors']));
    }
	sleep(5);
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

        sleep(5); // Wait for 5 seconds before checking again
    }

    throw new Exception("VM did not reach status '{$desiredStatus}' within {$timeout} seconds.");
}

function proxmox_custom_getVMStatus($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid)
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
    // Retrieve the VMID from custom fields
    $field = Capsule::table('tblcustomfields')
        ->where('type', 'product')
        ->where('fieldname', 'VMID')
        ->first();

    if (!$field) {
        throw new Exception('VMID custom field not found.');
    }

    $value = Capsule::table('tblcustomfieldsvalues')
        ->where('fieldid', $field->id)
        ->where('relid', $serviceId)
        ->first();

    if (!$value || empty($value->value)) {
        throw new Exception('VMID not found for service ID ' . $serviceId);
    }

    return $value->value;
}

function proxmox_custom_updateServiceDetails($serviceId, $userId, $publicIP)
{
    // Set the username to the user ID
    $username = 'u' . $userId . $params['serviceid'];

    // Replace dots in the public IP with dashes
    $publicIPFormatted = str_replace('.', '-', $publicIP);

    // Build the hostname
    $hostname = "Host_{$publicIPFormatted}.vps.ntc.ar";

    // Update the service details in the tblhosting table
    Capsule::table('tblhosting')
        ->where('id', $serviceId)
        ->update([
            'username' => $username,
            'domain'   => $hostname,
        ]);

    // Log the update
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Updated Service Details',
        [
            'serviceId' => $serviceId,
            'username'  => $username,
            'hostname'  => $hostname,
        ],
        null
    );
}

function proxmox_custom_GetOption(array $params, $id, $default = null)
{
    // Try to get the value from config options
    foreach ($params['configoptions'] as $optionName => $value) {
        // Check if the optionName contains a pipe '|'
        if (strpos($optionName, '|') !== false) {
            list($optionId, $friendlyName) = explode('|', $optionName, 2);
        } else {
            $optionId = $optionName;
        }

        if ($optionId === $id) {
            return $value;
        }
    }

    // Try to get the value from custom fields
    foreach ($params['customfields'] as $fieldName => $value) {
        if (strpos($fieldName, '|') !== false) {
            list($fieldId, $friendlyName) = explode('|', $fieldName, 2);
        } else {
            $fieldId = $fieldName;
        }

        if ($fieldId === $id) {
            return $value;
        }
    }

    // Try to get the value from module settings (if applicable)
    $options = proxmox_custom_ConfigOptions();
    $found = false;
    $i = 0;
    foreach ($options as $key => $value) {
        $i++;
        if ($key === $id) {
            $found = true;
            break;
        }
    }

    if ($found && isset($params['configoption' . $i]) && $params['configoption' . $i] !== '') {
        return $params['configoption' . $i];
    }

    return $default;
}

function proxmox_custom_setNetworkSpeed($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid, $networkSpeed)
{
    // Get the current net0 configuration
    $vmConfig = proxmox_custom_getVMConfig($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid);
    if (!isset($vmConfig['net0'])) {
        throw new Exception('Network interface net0 not found in VM configuration.');
    }

    $currentNet0 = $vmConfig['net0'];

    // Remove any existing 'rate' parameter
    $newNet0 = preg_replace('/(,|^)rate=\d+(\.\d+)?/', '', $currentNet0);

    // Append or update the rate parameter
    $newNet0 .= ",rate={$networkSpeed}";

    // Proceed to update the VM configuration
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/config";
    $params = [
        'net0' => $newNet0,
    ];

    $postFields = http_build_query($params);

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
        'Content-Type: application/x-www-form-urlencoded',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT', // Use PUT to update configuration
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false, // Set to true in production
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Log and handle the response
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        [
            'URL'         => $url,
            'Post Fields' => $params,
            'Response'    => $response,
            'HTTP Code'   => $httpCode,
            'cURL Error'  => $curlError,
        ],
        null,
        null
    );

    if ($response === false) {
        throw new Exception('Failed to set network speed: ' . $curlError);
    }

    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    } else {
        $errorMessage = isset($data['errors']) ? json_encode($data['errors']) : $response;
        throw new Exception('Failed to set network speed: ' . $errorMessage);
    }
}
function proxmox_custom_Start(array $params)
{
    try {
        // Retrieve necessary parameters
        $serverHostname = $params['serverhostname'];
        $apiTokenID     = $params['serverusername'];
        $apiTokenSecret = $params['serverpassword'];
        $node           = $params['configoption2'];
        $serviceId      = $params['serviceid'];

        // Get VMID
        $vmid = proxmox_custom_getVMID($serviceId);

        // Start the VM
        proxmox_custom_startVM($serverHostname, $apiTokenID, $apiTokenSecret, $node, $vmid);

        return 'success';
    } catch (Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
}

function proxmox_custom_Stop(array $params)
{
    try {
        // Retrieve necessary parameters
        $serverHostname = $params['serverhostname'];
        $apiTokenID     = $params['serverusername'];
        $apiTokenSecret = $params['serverpassword'];
        $node           = $params['configoption2'];
        $serviceId      = $params['serviceid'];

        // Get VMID
        $vmid = proxmox_custom_getVMID($serviceId);

        // Stop the VM
        proxmox_custom_stopVM($serverHostname, $apiTokenID, $apiTokenSecret, $node, $vmid);

        return 'success';
    } catch (Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
}

function proxmox_custom_ClientArea(array $params)
{
    // Initialize variables
    $errorMessage = '';

    try {
        // Retrieve necessary parameters
        $serverHostname = $params['serverhostname'];
        $apiTokenID     = $params['serverusername'];
        $apiTokenSecret = $params['serverpassword'];
        $node           = $params['configoption2'];
        $serviceId      = $params['serviceid'];

        // Get VMID
        $vmid = proxmox_custom_getVMID($serviceId);

        // Get VM Status and Resource Usage
        $vmStatus = proxmox_custom_getVMStatus(
            $serverHostname,
            $apiTokenID,
            $apiTokenSecret,
            $node,
            $vmid
        );

        $vmResources = proxmox_custom_getVMResourceUsage(
            $serverHostname,
            $apiTokenID,
            $apiTokenSecret,
            $node,
            $vmid
        );
        $cpuUsage = $vmResources['cpu'];    // CPU usage in percentage
        $ramUsage = $vmResources['mem'];    // RAM usage in MB
        $ramTotal = $vmResources['maxmem']; // Total RAM in MB

        // Get Public IP
        $publicIP = proxmox_custom_getPublicIP($serviceId);

    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }

    // Assign variables to the template
    return [
        'tabOverviewReplacementTemplate' => 'templates/clientarea.tpl',
        'templateVariables'              => [
            'vmStatus'     => ucfirst($vmStatus),
            'cpuUsage'     => $cpuUsage,
            'ramUsage'     => $ramUsage,
            'ramTotal'     => $ramTotal,
            'publicIP'     => $publicIP,
            'serviceid'    => $params['serviceid'],
            'errorMessage' => $errorMessage,
        ],
    ];
}

function proxmox_custom_getVMResourceUsage($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/status/current";

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false, // Set to true in production
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

        // CPU usage is provided as a fraction of total CPU (e.g., 0.05)
        // Multiply by 100 to get percentage
        $cpuUsage = round($statusData['cpu'] * 100, 2);

        // Memory usage and maximum memory are in bytes
        $ramUsageBytes = $statusData['mem'];
        $ramTotalBytes = $statusData['maxmem'];

        // Convert bytes to MB
        $ramUsageMB = round($ramUsageBytes / (1024 * 1024), 2);
        $ramTotalMB = round($ramTotalBytes / (1024 * 1024), 2);

        return [
            'cpu'     => $cpuUsage,
            'mem'     => $ramUsageMB,
            'maxmem'  => $ramTotalMB,
            'status'  => $statusData['status'],
        ];
    } else {
        throw new Exception('Failed to retrieve VM resource usage: Invalid response');
    }
}

function proxmox_custom_getPublicIP($serviceId)
{
    // First, try to get the public IP from the 'dedicatedip' field
    $hosting = Capsule::table('tblhosting')
        ->where('id', $serviceId)
        ->first(['dedicatedip']);

    if ($hosting && !empty($hosting->dedicatedip)) {
        return $hosting->dedicatedip;
    }

    // If not found, try to get it from a custom field named 'IP'
    $field = Capsule::table('tblcustomfields')
        ->where('type', 'product')
        ->where('fieldname', 'IP')
        ->first();

    if ($field) {
        $value = Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid', $field->id)
            ->where('relid', $serviceId)
            ->first();

        if ($value && !empty($value->value)) {
            return $value->value;
        }
    }

    return 'Unknown';
}

function proxmox_custom_ClientAreaCustomButtonArray()
{
    return [
        "Start VM"  => "Start",
        "Stop VM"   => "Stop",
        "Reboot VM" => "Reboot",
		"Reinstall Server" => "Reinstall",
    ];
}

function proxmox_custom_Reboot(array $params)
{
    try {
        // Retrieve necessary parameters
        $serverHostname = $params['serverhostname'];
        $apiTokenID     = $params['serverusername'];
        $apiTokenSecret = $params['serverpassword'];
        $node           = $params['configoption2'];
        $serviceId      = $params['serviceid'];

        // Get VMID
        $vmid = proxmox_custom_getVMID($serviceId);

        // Reboot the VM
        proxmox_custom_rebootVM($serverHostname, $apiTokenID, $apiTokenSecret, $node, $vmid);

        return 'success';
    } catch (Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
}

// Function to reboot a VM
function proxmox_custom_rebootVM($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/status/reboot";

    // Log the reboot VM request
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Rebooting VM',
        ['URL' => $url, 'vmid' => $vmid],
        null
    );

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false, // Set to true in production
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Log the response
    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Reboot VM Response',
        ['Response' => $response, 'cURL Error' => $curlError],
        null
    );

    if ($response === false) {
        throw new Exception('Reboot VM failed: ' . $curlError);
    }

    // Optionally, verify the response to ensure the VM was rebooted
    $data = json_decode($response, true);
    if (isset($data['errors']) && !empty($data['errors'])) {
        throw new Exception('Reboot VM failed: ' . json_encode($data['errors']));
    }

    logModuleCall(
        'proxmox_custom',
        __FUNCTION__,
        'Reboot VM Success',
        ['vmid' => $vmid],
        null
    );

    return true;
}

function proxmox_custom_getVNCConsole($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/vncproxy";

    $postFieldsArray = [
        'websocket' => 1,
    ];

    $postFields = http_build_query($postFieldsArray);

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
        'Content-Type: application/x-www-form-urlencoded',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('Failed to create VNC ticket: ' . $curlError);
    }

    $data = json_decode($response, true);

    if (!isset($data['data'])) {
        throw new Exception('Failed to create VNC ticket: Invalid response');
    }

    $ticket = urlencode($data['data']['ticket']);
    $port   = $data['data']['port'];

    // Construct the WebSocket URL path
    $websocketUrlPath = "/api2/json/nodes/{$node}/qemu/{$vmid}/vncwebsocket?port={$port}&vncticket={$ticket}";

    return [
        'url' => $websocketUrlPath,
    ];
}

function proxmox_custom_Reinstall(array $params)
{
    try {
        // Retrieve necessary parameters
        $serviceId = $params['serviceid'];
        $userId    = $params['userid'];

        // Get current time
        $currentTime = time();

        // Retrieve the last reinstall time from custom field
        $lastReinstallTime = proxmox_custom_getLastReinstallTime($serviceId);

        // Check if cooldown period has passed (30 minutes = 1800 seconds)
        if ($lastReinstallTime && ($currentTime - $lastReinstallTime) < 1800) {
            $remainingTime = 1800 - ($currentTime - $lastReinstallTime);
            $minutes = ceil($remainingTime / 60);
            throw new Exception("You must wait {$minutes} more minute(s) before you can reinstall the server.");
        }

        // Perform termination
        $terminateResult = proxmox_custom_TerminateAccount($params);
        if ($terminateResult !== 'success') {
            throw new Exception('Failed to terminate the existing VM: ' . $terminateResult);
        }

        // Perform creation
        $createResult = proxmox_custom_CreateAccount($params);
        if ($createResult !== 'success') {
            throw new Exception('Failed to create the new VM: ' . $createResult);
        }

        // Update the last reinstall time
        proxmox_custom_setLastReinstallTime($serviceId, $currentTime);

        return 'success';

    } catch (Exception $e) {
        logModuleCall(
            'proxmox_custom',
            __FUNCTION__,
            ['serviceid' => $serviceId],
            $e->getMessage(),
            $e->getTraceAsString()
        );
        return 'Error: ' . $e->getMessage();
    }
}

function proxmox_custom_getLastReinstallTime($serviceId)
{
    // Retrieve the Last Reinstall Time from custom fields
    $field = Capsule::table('tblcustomfields')
        ->where('type', 'product')
        ->where('fieldname', 'Last Reinstall Time')
        ->first();

    if (!$field) {
        return null;
    }

    $value = Capsule::table('tblcustomfieldsvalues')
        ->where('fieldid', $field->id)
        ->where('relid', $serviceId)
        ->first();

    if ($value && !empty($value->value)) {
        return (int)$value->value;
    }

    return null;
}

function proxmox_custom_setLastReinstallTime($serviceId, $timestamp)
{
    // Update the Last Reinstall Time in custom fields
    $field = Capsule::table('tblcustomfields')
        ->where('type', 'product')
        ->where('fieldname', 'Last Reinstall Time')
        ->first();

    if (!$field) {
        // Create the custom field if it doesn't exist
        $fieldId = Capsule::table('tblcustomfields')->insertGetId([
            'type'        => 'product',
            'relid'       => 0,
            'fieldname'   => 'Last Reinstall Time',
            'fieldtype'   => 'text',
            'description' => 'Stores the timestamp of the last reinstallation',
            'required'    => '0',
            'showorder'   => '0',
            'showinvoice' => '0',
            'adminonly'   => '1',
        ]);
    } else {
        $fieldId = $field->id;
    }

    // Update or insert the value
    Capsule::table('tblcustomfieldsvalues')->updateOrInsert(
        ['fieldid' => $fieldId, 'relid' => $serviceId],
        ['value' => $timestamp]
    );
}

function proxmox_custom_getVMRRDData($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid, $timeframe = 'day')
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/rrddata";

    $queryParams = http_build_query([
        'timeframe' => $timeframe,
        'cf'        => 'AVERAGE',
    ]);

    $url .= '?' . $queryParams;

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false, // Set to true in production
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

function proxmox_custom_getConsoleURL($hostname, $apiTokenID, $apiTokenSecret, $node, $vmid)
{
    $url = "https://{$hostname}/api2/json/nodes/{$node}/qemu/{$vmid}/vncproxy";

    $postFieldsArray = [
        'websocket' => 0, // Use standard VNC console
    ];

    $postFields = http_build_query($postFieldsArray);

    $headers = [
        "Authorization: PVEAPIToken={$apiTokenID}={$apiTokenSecret}",
        'Content-Type: application/x-www-form-urlencoded',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false, // Set to true in production
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('Failed to create console ticket: ' . $curlError);
    }

    $data = json_decode($response, true);

    if (!isset($data['data'])) {
        throw new Exception('Failed to create console ticket: Invalid response');
    }

    $ticket = urlencode($data['data']['ticket']);
    $port   = $data['data']['port'];

    // Construct the console URL
    $consoleUrl = "https://{$hostname}/?console=kvm&novnc=1&vmid={$vmid}&node={$node}";
    $consoleUrl .= "&resize=off&cmd=";
    $consoleUrl .= "&port={$port}&vncticket={$ticket}";

    return $consoleUrl;
}
