import type { DriveFileItem } from '@lumi-ui/svelte';

/** Server view keys for `GET /drive/files`. */
export type DriveView = 'folder' | 'recent' | 'heavy' | 'trash' | 'shared_by_me' | 'shared_with_me';

/**
 * `scope` is Lumi's required discriminator, derived by the server:
 * `user_private` when the viewer owns the row, `shared` when it was received.
 */
export interface DriveItem extends DriveFileItem {
    shared_count?: number;
    owner_name?: string;
}

export interface DriveCrumb {
    code: string;
    name: string;
}

export interface DriveFolderOption {
    code: string;
    label: string;
}

export interface DriveShareRow {
    code: string;
    user_code: string;
    full_name: string;
    created_at: string | null;
}

export interface DriveRecipient {
    code: string;
    full_name: string;
}

export interface DriveStorageInfo {
    used: number;
    total: number;
    percentage: number;
}
