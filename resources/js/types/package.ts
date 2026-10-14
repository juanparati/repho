export type Ecosystem = 'composer' | 'npm';

export const ECOSYSTEM_LABELS: Record<Ecosystem, string> = {
    composer: 'Composer',
    npm: 'npm',
};

export type Package = {
    id: number;
    name: string;
    ecosystem: Ecosystem;
    repository_url: string;
    subdirectory?: string | null;
    type: 'github' | 'gitlab' | 'git';
    download_dists: boolean;
    description: string | null;
    versions_count?: number;
    last_synced_at: string | null;
    sync_error: string | null;
    is_syncing: boolean;
    sync_progress: number;
    created_at: string;
};

export type Version = {
    id: number;
    version: string;
    version_normalized: string;
    reference: string;
    integrity?: string | null;
    released_at: string | null;
    created_at: string;
    dist_url: string | null;
};

export type Repository = {
    id: number;
    name: string;
    slug: string;
    auth_type: 'none' | 'basic' | 'token';
    auth_username?: string | null;
    packages_count?: number;
    created_at: string;
};

export type ProxySetting = {
    composer_enabled: boolean;
    npm_enabled: boolean;
    auth_type: 'none' | 'basic' | 'token';
    auth_username?: string | null;
    metadata_cache_ttl: number;
};

export type ProxyUpstream = {
    id: number;
    enabled: boolean;
    ecosystem: Ecosystem;
    name: string;
    upstream_url: string;
    auth_type: 'none' | 'basic' | 'token';
    auth_username?: string | null;
    sort_order: number;
    created_at: string;
};

export type Credential = {
    id: number;
    name: string;
    type: 'github' | 'gitlab' | 'git';
    base_url: string | null;
    packages_count?: number;
    created_at: string;
};
