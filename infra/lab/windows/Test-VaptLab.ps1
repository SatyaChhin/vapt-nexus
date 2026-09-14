<#
.SYNOPSIS
    Checks the Windows side of the VAPT lab: VMware host-only network (VMnet1) and Nessus reachability.

.DESCRIPTION
    Read-only. It never changes adapters, routes or VMware settings. The only thing it can
    launch is VMware's Virtual Network Editor (elevated) when -OpenNetworkEditor is given.
    Exit code: 0 = no failures, 1 = at least one FAIL.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\infra\lab\windows\Test-VaptLab.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\infra\lab\windows\Test-VaptLab.ps1 -OpenNetworkEditor

.EXAMPLE
    # Nessus installed on Kali instead of a separate VM
    powershell -ExecutionPolicy Bypass -File .\infra\lab\windows\Test-VaptLab.ps1 -NessusIp 192.168.56.10
#>
[CmdletBinding()]
param(
    [string]$HostOnlyAdapter = 'VMware Network Adapter VMnet1',
    [string]$NatAdapter = 'VMware Network Adapter VMnet8',
    [string]$HostIp = '192.168.56.1',
    [int]$PrefixLength = 24,
    [string]$KaliIp = '192.168.56.10',
    [string]$NessusIp = '192.168.56.20',
    [int]$NessusPort = 8834,
    [string]$TargetIp = '192.168.56.30',
    [switch]$OpenNetworkEditor
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'
$script:Counts = @{ PASS = 0; WARN = 0; FAIL = 0 }

function Write-Result {
    param(
        [ValidateSet('PASS', 'WARN', 'FAIL', 'INFO')][string]$Level,
        [string]$Message
    )
    $colors = @{ PASS = 'Green'; WARN = 'Yellow'; FAIL = 'Red'; INFO = 'Gray' }
    if ($script:Counts.ContainsKey($Level)) { $script:Counts[$Level]++ }
    Write-Host ('  {0,-4}  ' -f $Level) -ForegroundColor $colors[$Level] -NoNewline
    Write-Host $Message
}

function Write-Section([string]$Title) {
    Write-Host ''
    Write-Host "[$Title]" -ForegroundColor Cyan
}

function Test-TcpPort([string]$Address, [int]$Port, [int]$TimeoutMs = 3000) {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $pending = $client.BeginConnect($Address, $Port, $null, $null)
        if (-not $pending.AsyncWaitHandle.WaitOne($TimeoutMs)) { return $false }
        $client.EndConnect($pending)
        return $true
    } catch {
        return $false
    } finally {
        $client.Close()
    }
}

$subnet = $HostIp -replace '\.\d+$', '.0'
$vmnetcfg = $null
foreach ($root in @($env:ProgramFiles, ${env:ProgramFiles(x86)})) {
    if (-not $root) { continue }
    $path = Join-Path $root 'VMware\VMware Workstation\vmnetcfg.exe'
    if (Test-Path $path) { $vmnetcfg = $path; break }
}

Write-Host 'VAPT lab - Windows host checks' -ForegroundColor Cyan
Write-Host "Host-only: $HostOnlyAdapter ($HostIp/$PrefixLength)   Nessus: https://${NessusIp}:$NessusPort"

# ------------------------------------------------------------------ VMware
Write-Section 'VMware'
if ($vmnetcfg) {
    Write-Result PASS "Virtual Network Editor: $vmnetcfg"
} else {
    Write-Result WARN 'vmnetcfg.exe not found (is VMware Workstation installed?)'
}

$nat = Get-NetAdapter -Name $NatAdapter -ErrorAction SilentlyContinue
if ($nat -and $nat.Status -eq 'Up') {
    Write-Result PASS "$NatAdapter is Up (Kali eth0 / Internet)"
} else {
    Write-Result WARN "$NatAdapter not found or not Up (Kali's NAT uplink depends on it)"
}

# ------------------------------------------------------------------ VMnet1
Write-Section 'Host-only network (VMnet1)'
$hostOnly = Get-NetAdapter -Name $HostOnlyAdapter -ErrorAction SilentlyContinue
if (-not $hostOnly) {
    Write-Result FAIL "$HostOnlyAdapter does not exist - create VMnet1 first:"
    Write-Host '        VMware > Edit > Virtual Network Editor > Change Settings > Add Network... > VMnet1'
    Write-Host '        Host-only | [x] Connect a host virtual adapter | [ ] Use local DHCP service'
    Write-Host "        Subnet IP $subnet   Subnet mask 255.255.255.0   > Apply > OK"
    Write-Host "        Do NOT click 'Restore Defaults' (it can renumber VMnet8 and break Kali's NAT)."
    if ($OpenNetworkEditor -and $vmnetcfg) {
        Write-Result INFO 'Launching the Virtual Network Editor (accept the UAC prompt)...'
        try {
            Start-Process -FilePath $vmnetcfg -Verb RunAs
        } catch {
            Write-Result WARN 'The editor was not launched (UAC declined?). Open it from VMware > Edit > Virtual Network Editor.'
        }
    } elseif ($vmnetcfg) {
        Write-Result INFO 'Re-run with -OpenNetworkEditor to launch the editor elevated.'
    }
} else {
    if ($hostOnly.Status -eq 'Up') {
        Write-Result PASS "$HostOnlyAdapter is Up"
    } else {
        Write-Result FAIL "$HostOnlyAdapter is $($hostOnly.Status) (admin PowerShell: Enable-NetAdapter -Name '$HostOnlyAdapter')"
    }
    $addresses = @(Get-NetIPAddress -InterfaceIndex $hostOnly.ifIndex -AddressFamily IPv4 -ErrorAction SilentlyContinue)
    $match = @($addresses | Where-Object { $_.IPAddress -eq $HostIp -and $_.PrefixLength -eq $PrefixLength })
    if ($match.Count -gt 0) {
        Write-Result PASS "$HostOnlyAdapter = $HostIp/$PrefixLength"
    } else {
        $found = ($addresses | ForEach-Object { "$($_.IPAddress)/$($_.PrefixLength)" }) -join ', '
        if (-not $found) { $found = 'none' }
        Write-Result FAIL "expected $HostIp/$PrefixLength on $HostOnlyAdapter, found: $found (set Subnet IP $subnet in the Virtual Network Editor)"
    }
}

$dhcpConf = Join-Path $env:ProgramData 'VMware\vmnetdhcp.conf'
if (Test-Path $dhcpConf) {
    $scope = Select-String -Path $dhcpConf -Pattern ('^\s*subnet\s+' + [regex]::Escape($subnet) + '\s') -Quiet
    if ($scope) {
        Write-Result WARN "VMware DHCP has a scope for $subnet/$PrefixLength; the lab uses static IPs - untick 'Use local DHCP service' on VMnet1 (or keep its range clear of .10/.20/.30)"
    } else {
        Write-Result PASS "No VMware DHCP scope for $subnet/$PrefixLength (static addressing)"
    }
}

# ------------------------------------------------------------------ Routing
Write-Section 'Routing'
try {
    $route = Find-NetRoute -RemoteIPAddress $NessusIp -ErrorAction Stop | Select-Object -First 1
    if ($route.InterfaceAlias -eq $HostOnlyAdapter) {
        Write-Result PASS "$NessusIp is reached via $HostOnlyAdapter"
    } else {
        Write-Result FAIL "$NessusIp is routed via '$($route.InterfaceAlias)' instead of $HostOnlyAdapter (VMnet1 missing, or a VPN/other adapter claims $subnet/$PrefixLength)"
    }
} catch {
    Write-Result FAIL "No route to $NessusIp"
}

# ------------------------------------------------------------------ Nessus
Write-Section 'Nessus'
if (Test-TcpPort $NessusIp $NessusPort) {
    Write-Result PASS "TCP ${NessusIp}:$NessusPort is open"
    if (Get-Command curl.exe -ErrorAction SilentlyContinue) {
        # Documented, unauthenticated Nessus endpoint; -k because the lab certificate is self-signed.
        $out = (& curl.exe -k -s --max-time 8 -w 'HTTPSTATUS:%{http_code}' "https://${NessusIp}:$NessusPort/server/status") -join "`n"
        $code = ''
        $body = $out
        if ($out -match 'HTTPSTATUS:(\d{3})$') {
            $code = $Matches[1]
            $body = $out.Substring(0, $out.Length - 'HTTPSTATUS:000'.Length)
        }
        if ($code -eq '200') {
            Write-Result PASS 'GET /server/status -> HTTP 200'
            if ($body -match '"status"\s*:\s*"ready"') {
                Write-Result PASS 'Nessus reports status "ready"'
            } else {
                $snippet = $body.Substring(0, [Math]::Min(160, $body.Length))
                Write-Result WARN "Nessus is up but not ready yet (plugins may still be loading): $snippet"
            }
        } else {
            if (-not $code) { $code = 'no response' }
            Write-Result FAIL "GET /server/status -> HTTP $code"
        }
    } else {
        Write-Result WARN 'curl.exe not found; skipped the HTTPS check'
    }
    Write-Result INFO "Browser: https://${NessusIp}:$NessusPort (a self-signed certificate warning is expected in this lab)"
} else {
    Write-Result FAIL "TCP ${NessusIp}:$NessusPort is not reachable - is the VM on, set to $NessusIp on VMnet1, nessusd running, and $NessusPort allowed by its firewall?"
}

# ------------------------------------------------------------------ Lab VMs
Write-Section 'Lab VMs (ping, informational)'
foreach ($vm in @(@('Kali', $KaliIp), @('Target', $TargetIp))) {
    if (Test-Connection -ComputerName $vm[1] -Count 2 -Quiet -ErrorAction SilentlyContinue) {
        Write-Result PASS "ping $($vm[0]) ($($vm[1]))"
    } else {
        Write-Result WARN "no ping reply from $($vm[0]) ($($vm[1])) - VM off, lab IP not set yet, or ICMP blocked"
    }
}

# ------------------------------------------------------------------ Tooling
Write-Section 'Phase 1 tooling (info only)'
foreach ($tool in @('php', 'composer', 'node', 'npm', 'mysql', 'mariadb')) {
    $cmd = Get-Command $tool -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($cmd) { Write-Result INFO "$tool -> $($cmd.Source)" } else { Write-Result INFO "$tool - not on PATH" }
}
if (Get-Command php -ErrorAction SilentlyContinue) {
    $phpVersion = & php -r 'echo PHP_VERSION;'
    Write-Result INFO "PHP $phpVersion (project target: 8.4+)"
}

Write-Host ''
Write-Host ('Result: {0} passed, {1} warnings, {2} failed' -f $script:Counts.PASS, $script:Counts.WARN, $script:Counts.FAIL)
if ($script:Counts.FAIL -gt 0) { exit 1 }
exit 0
