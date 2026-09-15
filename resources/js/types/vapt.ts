export type SeverityKey = 'critical' | 'high' | 'medium' | 'low' | 'info';

export type SeverityCounts = Record<SeverityKey, number>;

export type NessusServerStatus =
    | 'unknown'
    | 'connected'
    | 'not_ready'
    | 'unauthorized'
    | 'failed';

export type ScanStatus =
    | 'created'
    | 'queued'
    | 'running'
    | 'completed'
    | 'importing'
    | 'imported'
    | 'failed'
    | 'cancelled';

export type ProjectEnvironment =
    | 'lab'
    | 'development'
    | 'staging'
    | 'production';

export type ProjectStatus = 'active' | 'on_hold' | 'archived';

/** API keys are never sent to the browser, only whether they are set. */
export type NessusServer = {
    id: number;
    name: string;
    base_url: string;
    verify_ssl: boolean;
    status: NessusServerStatus;
    server_version: string | null;
    last_error: string | null;
    last_checked_at: string | null;
    last_connected_at: string | null;
    credentials_configured: boolean;
    projects_count?: number;
    created_at: string | null;
    updated_at: string | null;
};

export type NessusServerOption = {
    id: number;
    name: string;
    status: NessusServerStatus;
};

export type Project = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    environment: ProjectEnvironment;
    status: ProjectStatus;
    creator?: { id: number; name: string } | null;
    nessus_servers?: NessusServerOption[];
    scans_count?: number;
    assets_count?: number;
    open_findings_count?: number;
    can: { update: boolean; delete: boolean };
    created_at: string | null;
    updated_at: string | null;
};

export type RecentScan = {
    id: number;
    name: string;
    status: ScanStatus;
    targets: string;
    nessus_server: string | null;
    total_findings: number;
    critical_count: number;
    high_count: number;
    medium_count: number;
    low_count: number;
    error_message: string | null;
    imported_at: string | null;
    finished_at: string | null;
    created_at: string | null;
};

export type Scan = {
    id: number;
    project_id: number;
    name: string;
    targets: string;
    status: ScanStatus;
    nessus_server?: { id: number; name: string } | null;
    nessus_scan_id: number | null;
    started_at: string | null;
    finished_at: string | null;
    duration_seconds: number | null;
    total_hosts: number;
    scanned_hosts: number;
    total_findings: number;
    severity: SeverityCounts;
    /** Also used for notices such as "results are partial". */
    error_message: string | null;
    imported_at: string | null;
    created_at: string | null;
    updated_at: string | null;
};

export type ScanHost = {
    id: number;
    ip_address: string | null;
    hostname: string | null;
    fqdn: string | null;
    operating_system: string | null;
    total_findings: number;
    severity: SeverityCounts;
};

/** One Nessus plugin within a scan, aggregated over hosts and ports. */
export type ScanFinding = {
    plugin_id: number;
    name: string;
    family: string | null;
    cvss_score: number | null;
    severity: SeverityKey;
    instances: number;
    hosts: number;
};

export type FindingInstance = {
    id: number;
    ip_address: string | null;
    hostname: string | null;
    port: number;
    protocol: string;
    service: string | null;
    severity: SeverityKey;
    state: string;
    plugin_output: string | null;
};

export type PluginDetail = {
    plugin_id: number;
    name: string;
    family: string | null;
    severity: SeverityKey;
    synopsis: string | null;
    description: string | null;
    solution: string | null;
    see_also: string[];
    cve: string[];
    cvss_score: number | null;
    cvss_vector: string | null;
    cvss_version: string | null;
    instances: FindingInstance[];
};

/** A scan as listed by Nessus, with its import state in this project. */
export type NessusScanOption = {
    id: number;
    name: string;
    status: string;
    folder: string | null;
    in_trash: boolean;
    last_modified_at: string | null;
    scan_id: number | null;
    scan_status: ScanStatus | null;
    imported_elsewhere: boolean;
};

export type NessusServerScans = {
    id: number;
    name: string;
    error: string | null;
    scans: NessusScanOption[];
};

export type TopHost = {
    asset_id: number;
    hostname: string | null;
    ip_address: string | null;
    total: number;
    critical: number;
    high: number;
};

export type ReportStatus = 'pending' | 'generating' | 'completed' | 'failed';

export type Report = {
    id: number;
    project_id: number;
    scan_id: number | null;
    scan_name?: string | null;
    report_number: string;
    title: string;
    status: ReportStatus;
    /** Null when the report was generated automatically. */
    created_by?: string | null;
    generated_at: string | null;
    created_at: string | null;
};

export type ProjectDashboard = {
    assets: number;
    scans: number;
    open_vulnerabilities: number;
    severity: SeverityCounts;
    recent_scans: RecentScan[];
    top_hosts: TopHost[];
    recent_reports: Report[];
};

export type VulnerabilityState =
    | 'open'
    | 'fixed'
    | 'accepted'
    | 'false_positive'
    | 'risk_accepted';

/** A Laravel length-aware paginator as Inertia serializes it. */
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

/** One finding on the All Findings page: a plugin on one host and port. */
export type FindingRow = {
    id: number;
    project: { id: number; code: string; name: string } | null;
    scan_id: number;
    plugin_id: number | null;
    name: string | null;
    family: string | null;
    cve: string | null;
    cve_count: number;
    severity: SeverityKey;
    cvss_score: number | null;
    ip_address: string | null;
    hostname: string | null;
    port: number;
    protocol: string;
    service: string | null;
    state: VulnerabilityState;
    first_found_at: string | null;
    last_found_at: string | null;
};

export type FindingFilters = {
    q: string;
    host: string;
    project: number | null;
    /** A VulnerabilityState, or "all". */
    state: string;
    severity: SeverityKey[];
};

/** Open findings on one day, summed over the projects the user can see. */
export type TrendPoint = { date: string } & SeverityCounts;

export type ProjectRole = 'manager' | 'analyst' | 'viewer';

export type ManagedUser = {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'member';
    projects_count: number;
    two_factor_enabled: boolean;
    disabled_at: string | null;
    is_self: boolean;
    created_at: string | null;
};

export type UserProjectOption = {
    id: number;
    code: string;
    name: string;
    status: ProjectStatus;
};

export type ConnectionTestResult = {
    success: boolean;
    message: string;
    status: NessusServerStatus;
    nessus_status: string | null;
    version: string | null;
    edition: string | null;
    latency_ms: number | null;
    server?: NessusServer;
};
