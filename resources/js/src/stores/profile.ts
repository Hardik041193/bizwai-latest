import { defineStore } from 'pinia';
import axios from 'axios';
import { useAuthStore } from '@/stores/auth';

export interface ProfileData {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'user';
    phone: string | null;
    job_title: string | null;
    address: string | null;
    bio: string | null;
    avatar_url: string | null;
    email_verified_at: string | null;
    created_at: string | null;
}

export interface UpdateProfilePayload {
    name: string;
    email: string;
    phone?: string;
    job_title?: string;
    address?: string;
    bio?: string;
}

export interface UpdatePasswordPayload {
    current_password: string;
    password: string;
    password_confirmation: string;
}

interface ProfileState {
    profile: ProfileData | null;
    loading: boolean;
    saving: boolean;
    savingPassword: boolean;
    uploadingAvatar: boolean;
    error: string | null;
    errors: Record<string, string[]>;
    successMessage: string | null;
}

export const useProfileStore = defineStore('profile', {
    state: (): ProfileState => ({
        profile:         null,
        loading:         false,
        saving:          false,
        savingPassword:  false,
        uploadingAvatar: false,
        error:           null,
        errors:          {},
        successMessage:  null,
    }),

    actions: {
        async fetchProfile(): Promise<void> {
            this.loading = true;
            this.error   = null;
            try {
                const { data } = await axios.get<ProfileData>('/api/profile');
                this.profile = data;
            } catch (err: any) {
                this.error = err.response?.data?.message ?? 'Failed to load profile.';
            } finally {
                this.loading = false;
            }
        },

        async updateProfile(payload: UpdateProfilePayload): Promise<boolean> {
            this.saving        = true;
            this.error         = null;
            this.errors        = {};
            this.successMessage = null;
            try {
                const { data } = await axios.put('/api/profile', payload);
                this.profile        = data.user;
                this.successMessage = data.message;

                // Keep auth store in sync
                const authStore = useAuthStore();
                if (authStore.user) {
                    authStore.user.name  = data.user.name;
                    authStore.user.email = data.user.email;
                }

                return true;
            } catch (err: any) {
                if (err.response?.status === 422) {
                    this.errors = err.response.data.errors ?? {};
                    this.error  = 'Please fix the validation errors below.';
                } else {
                    this.error = err.response?.data?.message ?? 'Failed to update profile.';
                }
                return false;
            } finally {
                this.saving = false;
            }
        },

        async updatePassword(payload: UpdatePasswordPayload): Promise<boolean> {
            this.savingPassword  = true;
            this.error           = null;
            this.errors          = {};
            this.successMessage  = null;
            try {
                const { data } = await axios.put('/api/profile/password', payload);
                this.successMessage = data.message;
                return true;
            } catch (err: any) {
                if (err.response?.status === 422) {
                    this.errors = err.response.data.errors ?? {};
                    this.error  = 'Please fix the validation errors below.';
                } else {
                    this.error = err.response?.data?.message ?? 'Failed to update password.';
                }
                return false;
            } finally {
                this.savingPassword = false;
            }
        },

        async uploadAvatar(file: File): Promise<boolean> {
            this.uploadingAvatar = true;
            this.error           = null;
            this.successMessage  = null;
            try {
                const form = new FormData();
                form.append('avatar', file);
                const { data } = await axios.post('/api/profile/avatar', form, {
                    headers: { 'Content-Type': 'multipart/form-data' },
                });
                if (this.profile) {
                    this.profile.avatar_url = data.avatar_url;
                }
                this.successMessage = data.message;
                return true;
            } catch (err: any) {
                if (err.response?.status === 422) {
                    this.errors = err.response.data.errors ?? {};
                }
                this.error = err.response?.data?.message ?? 'Failed to upload avatar.';
                return false;
            } finally {
                this.uploadingAvatar = false;
            }
        },

        clearMessages(): void {
            this.error          = null;
            this.errors         = {};
            this.successMessage = null;
        },
    },
});
