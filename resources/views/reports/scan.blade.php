@php
    use App\Enums\Severity;
    use App\Enums\VulnerabilityState;

    $badge = fn (Severity $severity) => sprintf(
        '<span class="badge" style="background:%s;color:%s">%s</span>',
        $colors[$severity->key()][0],
        $colors[$severity->key()][1],
        $severity->label(),
    );
    $duration = $scan->duration_seconds !== null
        ? sprintf('%d h %02d min', intdiv($scan->duration_seconds, 3600), intdiv($scan->duration_seconds % 3600, 60))
        : '—';
    $date = fn ($value) => $value?->format('d M Y, H:i') ?? '—';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $report->report_number }} – {{ $report->title }}</title>
<style>
    @page { margin: 48px 42px 56px 42px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1f2937; line-height: 1.45; }
    h1, h2, h3, h4 { color: #111827; margin: 0; }
    h2 { font-size: 15pt; margin: 0 0 10px; padding-bottom: 5px; border-bottom: 2px solid #111827; }
    h3 { font-size: 11pt; margin: 16px 0 6px; }
    h4 { font-size: 9pt; margin: 10px 0 3px; text-transform: uppercase; letter-spacing: 0.5px; color: #4b5563; }
    p { margin: 0 0 6px; }
    .page-break { page-break-after: always; }
    .muted { color: #6b7280; }
    .mono { font-family: 'DejaVu Sans Mono', monospace; }
    .badge { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 7.5pt; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; }
    table.grid th { background: #f3f4f6; text-align: left; font-size: 8pt; color: #374151; }
    table.grid th, table.grid td { border: 1px solid #e5e7eb; padding: 4px 6px; vertical-align: top; }
    table.meta td { padding: 3px 0; vertical-align: top; }
    table.meta td.label { width: 32%; color: #6b7280; }
    .num { text-align: right; }
    .center { text-align: center; }

    .cover { padding-top: 150px; }
    .cover .kicker { font-size: 10pt; letter-spacing: 3px; text-transform: uppercase; color: #6b7280; }
    .cover h1 { font-size: 26pt; line-height: 1.15; margin: 10px 0 18px; }
    .cover .project { font-size: 14pt; margin-bottom: 70px; }
    .cover .rule { border-top: 3px solid #111827; width: 90px; margin-bottom: 18px; }
    .notice { border: 1px solid #fcd34d; background: #fffbeb; padding: 8px 10px; margin: 10px 0; }

    .stat td { text-align: center; padding: 8px 4px; border: 1px solid #e5e7eb; }
    .stat .value { font-size: 18pt; font-weight: bold; }
    .stat .label { font-size: 7.5pt; text-transform: uppercase; color: #6b7280; }
    .bar td { height: 10px; padding: 0; }

    .finding { margin-top: 18px; }
    .finding-head { page-break-inside: avoid; }
    .finding-head td { padding: 6px 8px; }
    .finding-title { font-size: 11pt; font-weight: bold; }
    pre { font-family: 'DejaVu Sans Mono', monospace; font-size: 7pt; white-space: pre-wrap; word-wrap: break-word;
          background: #f9fafb; border: 1px solid #e5e7eb; padding: 6px; margin: 3px 0 8px; }
</style>
</head>
<body>

{{-- Cover --}}
<div class="cover">
    <div class="rule"></div>
    <div class="kicker">Vulnerability Assessment Report</div>
    <h1>{{ $scan->name }}</h1>
    <div class="project">{{ $project->name }} <span class="muted">({{ $project->code }})</span></div>

    <table class="meta" style="width: 70%">
        <tr><td class="label">Report number</td><td class="mono">{{ $report->report_number }}</td></tr>
        <tr><td class="label">Targets</td><td>{{ $scan->targets ?: '—' }}</td></tr>
        <tr><td class="label">Scan finished</td><td>{{ $date($scan->finished_at) }}</td></tr>
        <tr><td class="label">Report generated</td><td>{{ $date($generatedAt) }}</td></tr>
        <tr><td class="label">Overall risk</td><td>{!! $overall ? $badge($overall) : '<span class="muted">No vulnerabilities above informational</span>' !!}</td></tr>
    </table>

    <p class="muted" style="margin-top: 120px; font-size: 8pt;">
        CONFIDENTIAL. This report describes security weaknesses of the systems listed above.
        Share it only with people responsible for these systems.
    </p>
</div>
<div class="page-break"></div>

{{-- 1. Executive summary --}}
<h2>1. Executive summary</h2>

<p>
    A vulnerability scan of <strong>{{ $scan->targets ?: 'the target systems' }}</strong> was performed with Nessus
    @if ($scan->started_at) on {{ $scan->started_at->format('d M Y') }}@endif.
    {{ $scan->scanned_hosts }} {{ Str::plural('host', $scan->scanned_hosts) }} responded and
    <strong>{{ $total }} {{ Str::plural('finding', $total) }}</strong> were recorded.
    @php($urgent = $counts['critical'] + $counts['high'])
    {{ $urgent === 0 ? 'None of them is rated critical or high.' : "{$urgent} of them ".($urgent === 1 ? 'is' : 'are').' rated critical or high.' }}
</p>

@if ($overall)
    <p>
        The overall risk is rated {!! $badge($overall) !!}, the severity of the most serious finding.
        @if ($counts['critical'] + $counts['high'] > 0)
            Critical and high findings can typically be exploited remotely and should be fixed first.
        @endif
    </p>
@else
    <p>No vulnerabilities above informational level were found.</p>
@endif

@if ($scan->error_message)
    <div class="notice">{{ $scan->error_message }}</div>
@endif

<table class="stat" style="margin: 12px 0 6px">
    <tr>
        @foreach (array_reverse(Severity::cases()) as $severity)
            <td style="width: 20%">
                <div class="value" style="color: {{ $severity === Severity::Low ? '#a16207' : $colors[$severity->key()][0] }}">{{ $counts[$severity->key()] }}</div>
                <div class="label">{{ $severity->label() }}</div>
            </td>
        @endforeach
    </tr>
</table>

@if ($total > 0)
    <table class="bar" style="margin-bottom: 14px">
        <tr>
            @foreach (array_reverse(Severity::cases()) as $severity)
                @if ($counts[$severity->key()] > 0)
                    <td style="width: {{ round($counts[$severity->key()] / $total * 100, 2) }}%; background: {{ $colors[$severity->key()][0] }}"></td>
                @endif
            @endforeach
        </tr>
    </table>
@endif

@if ($detailed->isNotEmpty())
    <h3>Priorities</h3>
    <table class="grid">
        <tr><th style="width: 9%">Ref</th><th style="width: 13%">Severity</th><th>Finding</th><th style="width: 12%" class="num">Instances</th></tr>
        @foreach ($detailed->take(10) as $finding)
            <tr>
                <td class="mono">{{ $finding['ref'] }}</td>
                <td>{!! $badge($finding['severity']) !!}</td>
                <td>{{ $finding['vulnerability']->name }}</td>
                <td class="num">{{ $finding['instances']->count() }}</td>
            </tr>
        @endforeach
    </table>
    @if ($detailed->count() > 10)
        <p class="muted" style="margin-top: 4px">The {{ $detailed->count() - 10 }} further findings are listed in section 4.</p>
    @endif
@endif

{{-- 2. Scope and method --}}
<h2 style="margin-top: 22px">2. Scope and method</h2>
<table class="meta">
    <tr><td class="label">Project</td><td>{{ $project->name }} ({{ $project->code }}), {{ ucfirst($project->environment->value) }} environment</td></tr>
    <tr><td class="label">Targets</td><td>{{ $scan->targets ?: '—' }}</td></tr>
    <tr><td class="label">Hosts scanned</td><td>{{ $scan->scanned_hosts }} of {{ $scan->total_hosts }}</td></tr>
    <tr><td class="label">Scanner</td><td>{{ $scan->nessusServer?->name ?? 'Nessus' }}@if ($scan->nessusServer?->server_version) ({{ $scan->nessusServer->server_version }})@endif, scan #{{ $scan->nessus_scan_id }}</td></tr>
    <tr><td class="label">Scan window</td><td>{{ $date($scan->started_at) }} to {{ $date($scan->finished_at) }} ({{ $duration }})</td></tr>
    <tr><td class="label">Report</td><td>{{ $report->report_number }}, generated {{ $date($generatedAt) }} by {{ $generatedBy }}</td></tr>
</table>
<p class="muted" style="margin-top: 8px">
    The scan was run from the Nessus scanner's network position, without credentials unless configured in the
    Nessus policy, so it shows what an attacker in that position can see. Severities are those assigned by Nessus (CVSS v3 based).
    Findings marked as false positives in VAPT Nexus are excluded. An automated scan does not replace a manual
    penetration test and may miss vulnerabilities or report some that do not apply.
</p>
<div class="page-break"></div>

{{-- 3. Hosts --}}
<h2>3. Hosts</h2>
<table class="grid">
    <tr>
        <th>Host</th><th>Operating system</th>
        @foreach (array_reverse(Severity::cases()) as $severity)
            <th class="center" style="width: 8%">{{ substr($severity->label(), 0, 4) }}</th>
        @endforeach
    </tr>
    @forelse ($hosts as $host)
        <tr>
            <td><span class="mono">{{ $host['ip_address'] }}</span>@if ($host['name'])<br><span class="muted">{{ $host['name'] }}</span>@endif</td>
            <td>{{ $host['operating_system'] ?? '—' }}</td>
            @foreach (array_reverse(Severity::cases()) as $severity)
                <td class="center">{{ $host['counts'][$severity->key()] ?: '·' }}</td>
            @endforeach
        </tr>
    @empty
        <tr><td colspan="7" class="muted">Nessus reported no hosts.</td></tr>
    @endforelse
</table>

{{-- 4. Findings --}}
<h2 style="margin-top: 22px">4. Findings</h2>
@if ($detailed->isEmpty())
    <p>No findings above informational level.</p>
@else
    <table class="grid">
        <tr><th style="width: 9%">Ref</th><th style="width: 13%">Severity</th><th>Finding</th><th style="width: 9%" class="num">CVSS</th><th style="width: 12%" class="num">Instances</th></tr>
        @foreach ($detailed as $finding)
            <tr>
                <td class="mono">{{ $finding['ref'] }}</td>
                <td>{!! $badge($finding['severity']) !!}</td>
                <td>{{ $finding['vulnerability']->name }}</td>
                <td class="num">{{ $finding['vulnerability']->cvss_score ?? '—' }}</td>
                <td class="num">{{ $finding['instances']->count() }}</td>
            </tr>
        @endforeach
    </table>
@endif
<div class="page-break"></div>

{{-- 5. Detailed findings --}}
@if ($detailed->isNotEmpty())
    <h2>5. Detailed findings</h2>

    @foreach ($detailed as $finding)
        @php($vulnerability = $finding['vulnerability'])
        <div class="finding">
            <table class="finding-head" style="border-left: 5px solid {{ $colors[$finding['severity']->key()][0] }}; background: #f9fafb">
                <tr>
                    <td>
                        <span class="mono muted">{{ $finding['ref'] }}</span>
                        <div class="finding-title">{{ $vulnerability->name }}</div>
                    </td>
                    <td style="width: 18%; text-align: right">{!! $badge($finding['severity']) !!}</td>
                </tr>
            </table>

            <table class="meta" style="margin-top: 6px">
                <tr><td class="label">CVSS @if ($vulnerability->cvss_version) v{{ $vulnerability->cvss_version }}@endif</td>
                    <td>{{ $vulnerability->cvss_score ?? '—' }}@if ($vulnerability->cvss_vector) <span class="mono muted" style="font-size: 7.5pt">{{ $vulnerability->cvss_vector }}</span>@endif</td></tr>
                @if ($finding['cve'])
                    <tr><td class="label">CVE</td><td class="mono">{{ implode(', ', $finding['cve']) }}</td></tr>
                @endif
                <tr><td class="label">Nessus plugin</td><td>{{ $vulnerability->plugin_id }}@if ($vulnerability->family), {{ $vulnerability->family }}@endif</td></tr>
            </table>

            @if ($vulnerability->synopsis)
                <h4>Summary</h4>
                <p>{{ $vulnerability->synopsis }}</p>
            @endif

            @if ($vulnerability->description)
                <h4>Description</h4>
                <p>{!! nl2br(e($vulnerability->description)) !!}</p>
            @endif

            @if ($vulnerability->solution)
                <h4>Recommendation</h4>
                <p>{!! nl2br(e($vulnerability->solution)) !!}</p>
            @endif

            <h4>Affected ({{ $finding['instances']->count() }})</h4>
            <table class="grid">
                <tr><th style="width: 30%">Host</th><th style="width: 22%">Port</th><th>Service</th><th style="width: 18%">Status</th></tr>
                @foreach ($finding['instances'] as $instance)
                    <tr>
                        <td class="mono">{{ $instance['ip_address'] }}</td>
                        <td class="mono">{{ $instance['port'] }}/{{ $instance['protocol'] }}</td>
                        <td>{{ $instance['service'] ?? '—' }}</td>
                        <td>{{ $instance['state'] === VulnerabilityState::Open ? 'Open' : ucwords(str_replace('_', ' ', $instance['state']->value)) }}</td>
                    </tr>
                @endforeach
            </table>

            @if ($finding['instances']->contains(fn ($instance) => $instance['output'] !== null))
                <h4>Evidence</h4>
                @foreach ($finding['instances'] as $instance)
                    @if ($instance['output'] !== null)
                        <div class="muted mono" style="font-size: 7.5pt">{{ $instance['ip_address'] }} {{ $instance['port'] }}/{{ $instance['protocol'] }}</div>
                        <pre>{{ $instance['output'] }}</pre>
                    @endif
                @endforeach
            @endif

            @if ($finding['see_also'])
                <h4>References</h4>
                @foreach ($finding['see_also'] as $link)
                    <div class="mono" style="font-size: 7.5pt">{{ $link }}</div>
                @endforeach
            @endif
        </div>
    @endforeach
    <div class="page-break"></div>
@endif

{{-- Appendix --}}
<h2>Appendix A. Informational findings</h2>
<p class="muted">Facts Nessus collected about the hosts (services, versions, configuration). They are not vulnerabilities but help to understand the attack surface.</p>
@if ($informational->isEmpty())
    <p>None.</p>
@else
    <table class="grid">
        <tr><th style="width: 12%">Plugin</th><th>Name</th><th style="width: 26%">Family</th><th style="width: 12%" class="num">Instances</th></tr>
        @foreach ($informational as $finding)
            <tr>
                <td class="mono">{{ $finding['vulnerability']->plugin_id }}</td>
                <td>{{ $finding['vulnerability']->name }}</td>
                <td>{{ $finding['vulnerability']->family ?? '—' }}</td>
                <td class="num">{{ $finding['instances']->count() }}</td>
            </tr>
        @endforeach
    </table>
@endif

</body>
</html>
