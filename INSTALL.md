# Proxmox Simple Provisioning Module

**Installation, Configuration & Usage Guide**

Open-source WHMCS provisioning module for automated VPS deployment (Linux-first, limited Windows support).

---

## At a Glance

- Install the module folder into `modules/servers/`
- Use a Proxmox root API token (Token ID + Secret) for server authentication in WHMCS
- Configure product options for CPU, RAM, disk size, template VMID, and network rate limiting
- Define an IP/MAC pool line-by-line in the server's Assigned IP Addresses field
- Provisioning lifecycle is automated: create, suspend, and terminate actions are handled end-to-end
- Clients can Start / Stop / Restart from WHMCS; optional VNC console can be enabled via a reverse proxy

---

## Module Files

```
proxmox_custom/
├── proxmox_custom.php      # Main module logic
├── clientarea.tpl           # Client area template (dark theme + charts)
└── console-login.html       # Console auth helper (only needed for VNC)
```

---

## 1. Installation

Extract the downloaded archive. Ensure the contents extract into a single folder named `proxmox_custom`.

1. Upload the `proxmox_custom/` folder to your WHMCS directory:
   ```
   /path/to/whmcs/modules/servers/proxmox_custom/
   ```
2. The `clientarea.tpl` template should be located at:
   ```
   modules/servers/proxmox_custom/templates/clientarea.tpl
   ```
   Create the `templates/` subdirectory if needed and move the file there.

---

## 2. Server Credentials

When adding your Proxmox server in WHMCS (**Setup → Products/Services → Servers**), authenticate using an API Token from the Proxmox root user (without privilege separation).

| WHMCS Field | What to enter |
|---|---|
| **Hostname** | Your Proxmox hostname (e.g. `pve001.example.com`) |
| **Username** | Token ID (e.g. `root@pam!whmcs`) |
| **Password** | Token Secret Key |

### Creating the API Token on Proxmox

```bash
pveum user token add root@pam whmcs --privsep=0
```

> [!WARNING]
> Use a dedicated API token and treat the secret like a password. `--privsep=0` gives the token the same permissions as the root user. For production environments, consider creating a dedicated user with minimal permissions and a privilege-separated token.

---

## 3. Product Configuration (Module Settings)

In your WHMCS product settings, configure these options:

| Option | Description |
|---|---|
| **CPUCores** | Number of CPU cores |
| **RAM** | Amount of memory (in GB) |
| **DiskSize** | Disk size in GB. Must be ≥ template size (10 GB minimum recommended) |
| **TemplateID** | VMID of your base template on Proxmox |
| **NetworkSpeed** | Network rate limit in MB/s (applied to the network adapter) |
| **Node** | Proxmox node name (e.g. `pve001`) |
| **HostnameSuffix** | Domain suffix for VM hostnames (e.g. `.vps.example.com`) |
| **EnableConsole** | Show VNC console button in client area (`on` / `off`) |

---

## 4. IP, MAC & Network Configuration

Define a pool of available MAC addresses, public IPs, network bridges, and MTUs directly in the WHMCS server settings.

In the **Assigned IP Addresses** field, enter one configuration per line using the following format:

```
[MAC Address]=[Public IP];[Bridge],[MTU]
```

**Examples:**

```
bc:24:11:23:0e:c2=181.13.218.180;vmbr2,1250
52:54:00:a6:6e:5b=200.89.174.82;vmbr2,1250
```

### How it works

The module assigns IPs to VMs using DHCP with static mapping (configured to deny unknown clients). The IP is not forcefully configured inside the server OS by the module; instead, the DHCP server assigns the correct IP based on the MAC address provisioned in Proxmox.

---

## 5. Automated Module Actions

This module automates the full VPS lifecycle:

| Event | What the module does |
|---|---|
| **On Creation** | Creates a Proxmox user matching the WHMCS user ID; clones the template; configures cloud-init; sets hardware parameters (MAC, disk size, RAM, network rate); and grants user permissions |
| **On Suspension** | Stops the VM and revokes the user's permissions |
| **On Termination** | Permanently deletes both the VM and the Proxmox user |

---

## 6. Client Area & Access

### Quick Actions

Clients can securely **Start**, **Stop**, **Reboot**, and (optionally) **Reinstall** their VPS directly from the WHMCS client area. The client area also shows:

- Live VM status (Running / Stopped)
- CPU and RAM usage
- Performance graphs (CPU, memory, network, disk I/O)

If VNC console access is not required, providing SSH access alongside these buttons is generally sufficient.

### Email Templates

Create custom WHMCS welcome email templates to send clients their server access credentials upon provisioning.

---

## 7. Network Architecture

### Without VNC Console (simpler setup)

If you **don't need VNC console access** for clients, only the WHMCS server needs to reach Proxmox. This can be done via:

- Private network / VPN between WHMCS and Proxmox
- Direct connectivity on port 8006
- Internal reverse proxy

```
┌──────────┐         ┌──────────────┐
│  WHMCS   │────────▶│  Proxmox     │
│  Server  │  :8006  │  (API only)  │
└──────────┘         └──────────────┘
    Clients do NOT need direct access to Proxmox.
```

### With VNC Console (requires reverse proxy)

If you **want to provide VNC console access** to clients, they must be able to reach the Proxmox web interface through their browser. Since Proxmox runs on port 8006 with a self-signed certificate, a **reverse proxy** is required:

```
┌──────────┐         ┌───────────────┐         ┌──────────────┐
│  Client  │────────▶│ Reverse Proxy │────────▶│  Proxmox     │
│ Browser  │  :443   │ (Nginx/Zoraxy)│  :8006  │  Node        │
└──────────┘         └───────────────┘         └──────────────┘
                     vps.example.com
```

> [!IMPORTANT]
> The reverse proxy hostname (e.g. `vps.example.com`) must match the **Server Hostname** configured in WHMCS. This is the hostname used for both API calls and console URLs.

---

## 8. VNC Console Setup

### Requirements

1. **Clients can reach Proxmox** through a reverse proxy (as described above)
2. **`console-login.html`** is served on the **same domain** as the Proxmox reverse proxy
3. **EnableConsole** is set to `on` in the module settings

### Why `console-login.html` is needed

When a client clicks "Console", the module:

1. Authenticates with Proxmox **server-side** (PHP) and obtains an auth ticket
2. Redirects the client to `console-login.html` on the Proxmox domain
3. The page sets the `PVEAuthCookie` via JavaScript (same-domain, so browsers allow it)
4. Redirects to Proxmox's built-in noVNC console

This is necessary because browsers block setting cookies for a different domain. The WHMCS server (e.g. `panel.example.com`) cannot set cookies for the Proxmox domain (e.g. `vps.example.com`). The helper page bridges this gap by running on the Proxmox domain itself.

### Deploy `console-login.html`

The file must be accessible at a URL on the **same domain** as your Proxmox reverse proxy. The default path is:

```
https://<proxmox-hostname>/consolevnc/console-login.html
```

> [!TIP]
> You can change this path in `proxmox_custom.php` by searching for `consolevnc` and updating the URL.

Place the file on your **reverse proxy**, not on the Proxmox server itself (Proxmox's pveproxy does not serve arbitrary static files).

---

### Nginx Example

Add this to your Nginx server block for the Proxmox domain:

```nginx
server {
    listen 443 ssl;
    server_name vps.example.com;

    # Serve the console login helper
    location /consolevnc/ {
        alias /var/www/consolevnc/;
    }

    # Proxy everything else to Proxmox
    location / {
        proxy_pass https://proxmox-backend:8006;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}
```

Then place the file:

```bash
mkdir -p /var/www/consolevnc/
cp console-login.html /var/www/consolevnc/
```

---

### Zoraxy Example

1. Open the Zoraxy admin panel
2. Edit the proxy rule for your Proxmox domain (e.g. `vps.example.com`)
3. Add a **Virtual Directory**: path `/consolevnc/` pointing to a local directory
4. Place `console-login.html` in the configured directory

Alternatively, use Zoraxy's static file webroot:

```bash
mkdir -p /opt/zoraxy/www/consolevnc/
cp console-login.html /opt/zoraxy/www/consolevnc/
```

---

### Apache Example

```apache
<VirtualHost *:443>
    ServerName vps.example.com

    # Serve console login helper (must come before ProxyPass)
    Alias /consolevnc/ /var/www/consolevnc/
    <Directory /var/www/consolevnc/>
        Require all granted
    </Directory>

    # Proxy to Proxmox
    ProxyPass /consolevnc/ !
    ProxyPass / https://proxmox-backend:8006/
    ProxyPassReverse / https://proxmox-backend:8006/
</VirtualHost>
```

---

## 9. Troubleshooting

### Console shows "401 Unauthorized"

The `PVEAuthCookie` is not being set. Verify that:

- `console-login.html` is accessible at `https://<proxmox-hostname>/consolevnc/console-login.html`
- The URL domain matches the **Server Hostname** configured in WHMCS
- The `EnableConsole` setting is set to `on`

### VM provisioning fails

Check the WHMCS **Module Log** (Utilities → Logs → Module Log) for detailed error messages. Common issues:

- API token permissions insufficient
- Template VMID doesn't exist
- Node name mismatch
- IP/MAC pool not configured in server settings

### VM gets unexpectedly deleted

If upgrading from a previous version of this module, ensure there are no leftover async provisioning hooks:

- Delete `hooks.php` from the module directory (if present)
- Delete the `hooks/` folder from the module directory (if present)
- Delete any copy of `proxmox_async_provisioning.php` from WHMCS `includes/hooks/`
- Clean up the `mod_proxmox_tasks` table if it exists: `DELETE FROM mod_proxmox_tasks;`

### Customization

This module is 100% open-source. You are free to modify the code to fit your workflow.

---

> [!NOTE]
> For more information on the module's philosophy and workflow, see the "Proxmox Simple Provisioning" listing on the WHMCS Marketplace.
