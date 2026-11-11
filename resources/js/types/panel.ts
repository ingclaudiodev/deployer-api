export type PanelStats = {
    users: number;
    newUsersThisWeek: number;
    unverifiedUsers: number;
    activeTokens: number;
    pendingChallenges: number;
    trustedDevices: number;
};

export type RoleCount = {
    slug: string;
    name: string;
    users: number;
};

export type SignupDay = {
    date: string;
    total: number;
};

export type RecentUser = {
    id: number;
    name: string;
    email: string;
    role: string;
    verified: boolean;
    createdAt: string | null;
};

export type SystemInfo = {
    environment: string;
    debug: boolean;
    php: string;
    laravel: string;
    octane: string | null;
    apiUrl: string;
    frontendUrl: string;
    twoFactor: boolean;
    queue: string;
    cache: string;
    session: string;
    mailer: string;
    aiModel: string;
};

export type Probe = {
    ok: boolean;
    latencyMs: number | null;
};

export type HorizonStatus = 'running' | 'paused' | 'inactive' | 'unreachable';

export type HorizonState = {
    status: HorizonStatus;
    processes: number;
    jobsPerMinute: number;
    recentJobs: number;
    failedJobs: number;
    maxWaitSeconds: number;
};

export type SystemHealth = {
    database: Probe;
    redis: Probe;
    horizon: HorizonState;
};
