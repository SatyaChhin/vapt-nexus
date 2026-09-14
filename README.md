# VAPT Lab - Phase 0 Network Kit

Scripts to build and verify the isolated VMware host-only lab that VAPT Nexus uses to reach Nessus.
Use this lab only against systems you are authorized to test.

```text
Windows host (Laravel 127.0.0.1:8000)
│  VMnet8  192.168.168.1   NAT  (Internet for Kali / Nessus)
│  VMnet1  192.168.56.1    Host-only (VAPT lab)
│
└── VMware Host-only VMnet1  192.168.56.0/24   (no gateway, no DHCP)
     ├── Kali    eth0 NAT 192.168.168.129 (default route) + eth1 192.168.56.10
     ├── Nessus  NAT (plugin updates)                     + 192.168.56.20:8834
     └── Target  host-only only (isolated)                  192.168.56.30
```

**Nessus installed on Kali instead of its own VM?** Then Nessus is `https://192.168.56.10:8834`.
Pass the address to the checks: `LAB_NESSUS_IP=192.168.56.10` for the Linux script and
`-NessusIp 192.168.56.10` for the Windows script. In the app, register the server with that URL.

| File                                   | Runs on                | Changes anything?                                                                                        |
| -------------------------------------- | ---------------------- | -------------------------------------------------------------------------------------------------------- |
| `windows/Test-VaptLab.ps1`             | Windows host           | No (read-only; `-OpenNetworkEditor` only launches the VMware editor)                                     |
| `linux/setup-lab-interface.sh inspect` | Kali / Nessus / Target | No                                                                                                       |
| `linux/setup-lab-interface.sh apply`   | Kali / Nessus / Target | Yes: adds one static lab profile. Shows a plan, asks first, backs up to `/var/tmp/vapt-lab-backup-*.txt` |
| `linux/setup-lab-interface.sh verify`  | Kali / Nessus / Target | No                                                                                                       |

The Linux script never modifies the interface that carries the default route (NAT), refuses interfaces
that already hold non-lab addresses, and ignores `docker0` / bridges / veth. Re-running `apply` is safe.

---

## 1. Windows: create VMnet1

```powershell
# From the repository root. Launches the Virtual Network Editor elevated if VMnet1 is missing.
powershell -ExecutionPolicy Bypass -File .\infra\lab\windows\Test-VaptLab.ps1 -OpenNetworkEditor
```

In the editor: **Change Settings > Add Network... > VMnet1**, then

- Host-only
- [x] Connect a host virtual adapter to this network
- [ ] Use local DHCP service
- Subnet IP `192.168.56.0`, mask `255.255.255.0` > **Apply > OK**

Do **not** press _Restore Defaults_ and do not change VMnet8.
Re-run the script (without `-OpenNetworkEditor`); the _Host-only network_ and _Routing_ sections must be PASS.

## 2. Add the VM adapters (VMware GUI)

| VM                   | Adapter 1          | Adapter 2               |
| -------------------- | ------------------ | ----------------------- |
| Kali                 | NAT (keep)         | **Custom: VMnet1**      |
| Nessus (if separate) | NAT                | **Custom: VMnet1**      |
| Target               | **Custom: VMnet1** | none (keep it isolated) |

_VM > Settings > Add... > Network Adapter > Custom: Specific virtual network > VMnet1._
Note each new adapter's MAC (_Advanced..._) so you can match it inside the VM.

## 3. Copy the script into a VM

- **scp over NAT** (Kali/Nessus; needs `sudo systemctl start ssh` in the VM first):
    ```powershell
    scp .\infra\lab\linux\setup-lab-interface.sh <user>@192.168.168.129:~/
    ```
- VMware drag-and-drop / shared folder (needs `open-vm-tools-desktop`).
- Paste it into an editor in the VM.

If bash reports `bad interpreter: ^M`, fix the line endings: `sed -i 's/\r$//' setup-lab-interface.sh`.

## 4. Configure each VM

```bash
chmod +x setup-lab-interface.sh

./setup-lab-interface.sh inspect                 # read-only: shows the NAT iface and the host-only candidate
sudo ./setup-lab-interface.sh apply --role kali  # or: --role nessus / --role target
./setup-lab-interface.sh verify --role kali      # use sudo when Nessus runs on this host (ufw check)

# Nessus on Kali:
sudo LAB_NESSUS_IP=192.168.56.10 ./setup-lab-interface.sh verify --role kali
```

If more than one candidate interface is found, pick explicitly:
`sudo ./setup-lab-interface.sh apply --role kali --iface eth1` or `--mac 00:0c:29:xx:xx:xx`.

What `apply` does with NetworkManager (manual equivalent):

```bash
sudo nmcli connection add type ethernet ifname eth1 con-name vapt-hostonly \
  ipv4.method manual ipv4.addresses 192.168.56.10/24 \
  ipv4.never-default yes ipv4.ignore-auto-dns yes ipv6.method disabled
sudo nmcli connection up vapt-hostonly
```

Without NetworkManager (for example Ubuntu Server), it writes `/etc/netplan/60-vapt-hostonly.yaml`
(static address, no routes, no DNS) and runs `netplan apply`.

**Windows target VM** (replace `Ethernet1` with the VMnet1 adapter's name from `Get-NetAdapter`):

```powershell
New-NetIPAddress -InterfaceAlias "Ethernet1" -IPAddress 192.168.56.30 -PrefixLength 24   # no -DefaultGateway
```

## 5. Final validation

1. `verify --role kali` on Kali: 0 failed
2. `sudo ./setup-lab-interface.sh verify --role nessus` on a separate Nessus VM: 0 failed
3. `verify --role target` on Target: 0 failed
4. `Test-VaptLab.ps1` on Windows: 0 failed

Manual extras from Kali (authorized lab hosts only):

```bash
nmap -p 8834 192.168.56.20      # or .10 when Nessus runs on Kali
nmap -sV 192.168.56.30
```

### Checklist

```text
[ ] VMware NAT adapter works            (Test-VaptLab: VMnet8 Up; Kali verify: Internet section)
[ ] Kali eth0 has Internet              (Kali verify: ping 8.8.8.8 / DNS)
[ ] VMware Host-only adapter exists     (Test-VaptLab: VMnet1 = 192.168.56.1/24)
[ ] Kali eth1 = 192.168.56.10/24        (Kali verify: Lab interface)
[ ] eth1 has NO default gateway         (Kali verify: no default route via eth1)
[ ] Nessus reachable on :8834           (Kali / Nessus verify)
[ ] Target = 192.168.56.30              (Target verify)
[ ] Kali -> Target works                (Kali verify: Lab peers)
[ ] Nessus -> Target works              (Nessus verify: Lab peers)
[ ] Windows -> Nessus:8834 works        (Test-VaptLab: Nessus section)
[ ] Laravel -> Nessus works             (App: Nessus Servers > Test Connection)
```

## Troubleshooting

| Symptom                                         | Check                                                                                                                     |
| ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| No host-only candidate in `inspect`             | VM Settings: adapter 2 is _Custom: VMnet1_ and _Connected_; `ip -br link`                                                 |
| Adapter shows `DOWN`                            | `sudo nmcli device connect eth1`; `sudo systemctl restart NetworkManager` (NAT reconnects by itself)                      |
| Windows routes 192.168.56.x via another adapter | VMnet1 missing, or a VPN pushes that subnet; `Find-NetRoute -RemoteIPAddress 192.168.56.20`                               |
| `apply` fails on activation                     | Another VM already uses the address (duplicate-address detection); `journalctl -u NetworkManager -n 50`                   |
| Nessus port closed                              | `sudo systemctl status nessusd`; `ss -tln \| grep 8834`; `sudo ufw allow from 192.168.56.0/24 to any port 8834 proto tcp` |
| `/server/status` not "ready"                    | Nessus is still compiling plugins after install or update; wait and re-run                                                |
| Undo on a VM                                    | `sudo nmcli connection delete vapt-hostonly` (or remove `/etc/netplan/60-vapt-hostonly.yaml` and `sudo netplan apply`)    |
