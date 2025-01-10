# Module Actions

This module performs the following actions:

## On Creation:
- Creates a Proxmox user with the same ID as the WHMCS user.
- Clones a VM from a template in WHMCS.
- Configures cloud-init with server-related parameters.
- Sets up the MAC address, increases disk size, adjusts RAM, and configures the network rate.
- Grants permissions to the newly created user.

## On Suspension:
- Stops the VM.
- Removes the user's permissions.

## On Termination:
- Deletes the user.
- Deletes the VM.

# Customizable Options

The module accepts the following customizable options:

- **CPUCores**
- **RAM** (in GB)
- **DiskSize** (in GB, must be equal to or larger than the template size, with 10 GB as the recommended minimum)
- **TemplateID** (the template VMID on Proxmox)
- **NetworkSpeed** (in MB/s, set as "rate" on the network adapter)

# Setup and Configuration

Upon purchasing this module, you will receive all necessary files for setup. This module is designed primarily for Linux deployments, though it can also support Windows with certain limitations.

## Public IP Configuration

You can configure available public IPs using the following format in the server settings:

```
"Assigned IP Addresses (One per line)": XX:XX:XX:XX =XXX.XXX.XXX.XXX
```

Here, the first set of XXs represents the MAC addresses that will be assigned to the machine, and the second set represents the public IPs visible to clients. This IP is not configured on the server but serves as a pool of available public IPs.

## IP Assignment and Networking

The module assigns IPs to VMs using DHCP with static mapping, configured to deny unknown clients. The available IPs are pulled based on MAC addresses assigned in Proxmox.

For public access, you can set up a Proxmox portal behind an SSL-secured Nginx server. The module supports access through WHMCS, allowing clients to start, stop, and restart the VPS via buttons in the client area. If VNC console access is not needed, SSH plus these control buttons can suffice.

# Server Credentials

When adding the server, you will need to use an API Token from the root user (without privilege separation). Use the token key as the password and the token ID as the username.

# Email Templates

You will need to create email templates based on how you plan to grant service access.

# Open Source

The module is completely open source, and you can modify it as you please to better fit your business needs.

# Contribute

If you want to contribute, please feel free to acquire it via: [WHMCS Marketplace](https://marketplace.whmcs.com/product/7553-proxmox-simple-provisioning)

note: you may need to increase allowed times in both nginx and apache to over 300s depending on your deployment speed.
