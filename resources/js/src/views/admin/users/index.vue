<template>
    <div>
        <!-- Breadcrumb -->
        <ul class="flex space-x-2 rtl:space-x-reverse mb-6">
            <li><span class="text-primary">Admin</span></li>
            <li class="before:content-['/'] ltr:before:mr-2 rtl:before:ml-2 text-white-dark">Users</li>
        </ul>

        <!-- Header row -->
        <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
            <div>
                <h1 class="text-2xl font-bold dark:text-white">User Management</h1>
                <p class="text-sm text-white-dark/70 mt-0.5">Manage all platform users</p>
            </div>
            <button @click="openCreateModal" class="btn btn-primary gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Add User
            </button>
        </div>

        <!-- Filters -->
        <div class="panel mb-5">
            <div class="flex flex-wrap gap-3 items-center">
                <!-- Search -->
                <div class="relative flex-1 min-w-[200px]">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11A6 6 0 115 11a6 6 0 0112 0z"/>
                    </svg>
                    <input
                        v-model="filters.search"
                        @input="onSearchInput"
                        type="text"
                        class="form-input pl-9"
                        placeholder="Search by name or email…"
                    />
                </div>

                <!-- Role filter -->
                <select v-model="filters.role" @change="() => loadUsers(1)" class="form-select w-40">
                    <option value="">All Roles</option>
                    <option value="admin">Admin</option>
                    <option value="user">User</option>
                </select>

                <!-- Per page -->
                <select v-model="filters.per_page" @change="() => loadUsers(1)" class="form-select w-32">
                    <option value="10">10 / page</option>
                    <option value="15">15 / page</option>
                    <option value="25">25 / page</option>
                    <option value="50">50 / page</option>
                </select>

                <!-- Total badge -->
                <span v-if="usersStore.users" class="text-sm text-white-dark/60 ltr:ml-auto rtl:mr-auto">
                    {{ usersStore.users.total }} users total
                </span>
            </div>
        </div>

        <!-- Table -->
        <div class="panel">
            <!-- Loading -->
            <div v-if="usersStore.loading" class="flex justify-center py-16">
                <span class="animate-spin border-4 border-primary border-l-transparent rounded-full w-10 h-10 inline-block"></span>
            </div>

            <!-- Empty -->
            <div v-else-if="!usersStore.users?.data?.length" class="text-center py-16 text-white-dark/50">
                <svg class="w-14 h-14 mx-auto mb-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.768-.231-1.48-.634-2.071M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.768.231-1.48.634-2.071M15 7a3 3 0 11-6 0 3 3 0 016 0zM6.354 15.143A8.014 8.014 0 0115.646 15.143"/>
                </svg>
                <p class="text-lg font-medium">No users found</p>
                <p class="text-sm mt-1">Try adjusting your search or filters.</p>
            </div>

            <!-- Users table -->
            <div v-else class="table-responsive">
                <table class="table-hover">
                    <thead>
                        <tr>
                            <th class="w-10">#</th>
                            <th>User</th>
                            <th>Contact</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th class="text-center w-28">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in usersStore.users.data" :key="user.id">
                            <td class="text-white-dark/60 text-sm">{{ user.id }}</td>

                            <!-- User info -->
                            <td>
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-full overflow-hidden shrink-0 bg-primary/10 flex items-center justify-center">
                                        <img v-if="user.avatar_url" :src="user.avatar_url" :alt="user.name" class="w-full h-full object-cover" />
                                        <span v-else class="text-sm font-bold text-primary">{{ initials(user.name) }}</span>
                                    </div>
                                    <div>
                                        <p class="font-semibold dark:text-white">{{ user.name }}</p>
                                        <p class="text-xs text-white-dark/60">{{ user.email }}</p>
                                    </div>
                                </div>
                            </td>

                            <!-- Contact -->
                            <td>
                                <p v-if="user.phone" class="text-sm">{{ user.phone }}</p>
                                <p v-if="user.job_title" class="text-xs text-white-dark/60">{{ user.job_title }}</p>
                                <span v-if="!user.phone && !user.job_title" class="text-white-dark/30 text-xs">—</span>
                            </td>

                            <!-- Role -->
                            <td>
                                <span :class="user.role === 'admin' ? 'badge badge-outline-success' : 'badge badge-outline-primary'">
                                    {{ user.role === 'admin' ? 'Admin' : 'User' }}
                                </span>
                            </td>

                            <!-- Verified status -->
                            <td>
                                <span :class="user.email_verified_at ? 'badge badge-outline-success' : 'badge badge-outline-warning'">
                                    {{ user.email_verified_at ? 'Verified' : 'Pending' }}
                                </span>
                            </td>

                            <!-- Joined -->
                            <td class="text-sm text-white-dark/70">{{ formatDate(user.created_at) }}</td>

                            <!-- Actions -->
                            <td>
                                <div class="flex items-center justify-center gap-2">
                                    <button
                                        @click="openEditModal(user)"
                                        class="btn btn-sm btn-outline-primary p-1.5"
                                        title="Edit"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                        </svg>
                                    </button>
                                    <button
                                        @click="onDelete(user)"
                                        :disabled="user.id === authStore.user?.id"
                                        class="btn btn-sm btn-outline-danger p-1.5"
                                        :class="{ 'opacity-40 cursor-not-allowed': user.id === authStore.user?.id }"
                                        title="Delete"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M8 7V5a2 2 0 012-2h4a2 2 0 012 2v2"/>
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="usersStore.users && usersStore.users.last_page > 1"
                class="flex items-center justify-between mt-5 flex-wrap gap-3">
                <p class="text-sm text-white-dark/60">
                    Page {{ usersStore.users.current_page }} of {{ usersStore.users.last_page }}
                    ({{ usersStore.users.total }} total)
                </p>
                <div class="flex gap-1">
                    <button
                        @click="loadUsers(usersStore.users.current_page - 1)"
                        :disabled="usersStore.users.current_page <= 1"
                        class="btn btn-sm btn-outline-primary"
                    >Prev</button>

                    <template v-for="page in paginationPages" :key="page">
                        <span v-if="page === '...'" class="btn btn-sm btn-outline-secondary cursor-default">…</span>
                        <button
                            v-else
                            @click="loadUsers(page as number)"
                            :class="['btn btn-sm', page === usersStore.users.current_page ? 'btn-primary' : 'btn-outline-primary']"
                        >{{ page }}</button>
                    </template>

                    <button
                        @click="loadUsers(usersStore.users.current_page + 1)"
                        :disabled="usersStore.users.current_page >= usersStore.users.last_page"
                        class="btn btn-sm btn-outline-primary"
                    >Next</button>
                </div>
            </div>
        </div>

        <!-- ── Create / Edit Modal ── -->
        <div v-if="modal.open" class="fixed inset-0 z-[999] flex items-center justify-center">
            <!-- Backdrop -->
            <div class="absolute inset-0 bg-black/60" @click="closeModal"></div>

            <!-- Panel -->
            <div class="relative bg-white dark:bg-[#1b2e4b] rounded-lg shadow-xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
                <!-- Header -->
                <div class="flex items-center justify-between px-6 py-4 border-b border-white-dark/20">
                    <h2 class="text-lg font-bold dark:text-white">
                        {{ modal.mode === 'create' ? 'Add New User' : 'Edit User' }}
                    </h2>
                    <button @click="closeModal" class="text-gray-400 hover:text-gray-600 dark:hover:text-white">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- Body -->
                <form @submit.prevent="submitForm" class="p-6 space-y-4">
                    <!-- Validation summary -->
                    <div v-if="usersStore.error && Object.keys(usersStore.errors).length"
                        class="alert alert-danger p-3 rounded text-sm">
                        Please fix the errors below.
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Name -->
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium mb-1.5">Full Name <span class="text-danger">*</span></label>
                            <input v-model="form.name" type="text" class="form-input"
                                :class="{ 'border-danger': usersStore.errors.name }"
                                placeholder="Full name" />
                            <p v-if="usersStore.errors.name" class="text-danger text-xs mt-1">{{ usersStore.errors.name[0] }}</p>
                        </div>

                        <!-- Email -->
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium mb-1.5">Email Address <span class="text-danger">*</span></label>
                            <input v-model="form.email" type="email" class="form-input"
                                :class="{ 'border-danger': usersStore.errors.email }"
                                placeholder="email@example.com" />
                            <p v-if="usersStore.errors.email" class="text-danger text-xs mt-1">{{ usersStore.errors.email[0] }}</p>
                        </div>

                        <!-- Password -->
                        <div>
                            <label class="block text-sm font-medium mb-1.5">
                                Password <span v-if="modal.mode === 'create'" class="text-danger">*</span>
                                <span v-else class="text-xs text-white-dark/50 font-normal">(leave blank to keep current)</span>
                            </label>
                            <input v-model="form.password" type="password" class="form-input"
                                :class="{ 'border-danger': usersStore.errors.password }"
                                placeholder="Min 8 characters"
                                autocomplete="new-password" />
                            <p v-if="usersStore.errors.password" class="text-danger text-xs mt-1">{{ usersStore.errors.password[0] }}</p>
                        </div>

                        <!-- Confirm Password -->
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Confirm Password</label>
                            <input v-model="form.password_confirmation" type="password" class="form-input"
                                placeholder="Repeat password"
                                autocomplete="new-password" />
                        </div>

                        <!-- Role -->
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Role <span class="text-danger">*</span></label>
                            <select v-model="form.role" class="form-select"
                                :class="{ 'border-danger': usersStore.errors.role }">
                                <option value="user">User</option>
                                <option value="admin">Admin</option>
                            </select>
                            <p v-if="usersStore.errors.role" class="text-danger text-xs mt-1">{{ usersStore.errors.role[0] }}</p>
                        </div>

                        <!-- Phone -->
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Phone</label>
                            <input v-model="form.phone" type="tel" class="form-input" placeholder="+1 (555) 000-0000" />
                        </div>

                        <!-- Job Title -->
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Job Title</label>
                            <input v-model="form.job_title" type="text" class="form-input" placeholder="e.g. Software Engineer" />
                        </div>

                        <!-- Address -->
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Address</label>
                            <input v-model="form.address" type="text" class="form-input" placeholder="City, Country" />
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="flex justify-end gap-3 pt-2 border-t border-white-dark/20 mt-4">
                        <button type="button" @click="closeModal" class="btn btn-outline-danger">Cancel</button>
                        <button type="submit" :disabled="usersStore.saving" class="btn btn-primary gap-2 min-w-[110px]">
                            <span v-if="usersStore.saving"
                                class="animate-spin border-2 border-white border-l-transparent rounded-full w-4 h-4 inline-block">
                            </span>
                            {{ usersStore.saving ? 'Saving…' : (modal.mode === 'create' ? 'Create User' : 'Save Changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useMeta }      from '@/composables/use-meta';
import { useToast }     from '@/composables/use-toast';
import { useAuthStore } from '@/stores/auth';
import { useUsersStore, type AdminUser, type UserFormData } from '@/stores/users';

useMeta({ title: 'User Management' });

const usersStore = useUsersStore();
const authStore  = useAuthStore();
const { showToast, confirmDialog } = useToast();

// ── Filters & search ─────────────────────────────────────────────────────────
const filters = reactive({ search: '', role: '', per_page: 15 });
let searchTimer: ReturnType<typeof setTimeout>;

function onSearchInput() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadUsers(1), 400);
}

async function loadUsers(page = 1) {
    await usersStore.fetchUsers({
        search:   filters.search   || undefined,
        role:     filters.role     || undefined,
        per_page: filters.per_page,
        page,
    });
    if (usersStore.error) {
        showToast(usersStore.error, 'error');
    }
}

// ── Pagination helper ─────────────────────────────────────────────────────────
const paginationPages = computed((): (number | '...')[] => {
    const current = usersStore.users?.current_page ?? 1;
    const last    = usersStore.users?.last_page    ?? 1;
    if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);
    const pages: (number | '...')[] = [1];
    if (current > 3)         pages.push('...');
    for (let i = Math.max(2, current - 1); i <= Math.min(last - 1, current + 1); i++) pages.push(i);
    if (current < last - 2)  pages.push('...');
    pages.push(last);
    return pages;
});

// ── Modal state ───────────────────────────────────────────────────────────────
const modal = reactive({ open: false, mode: 'create' as 'create' | 'edit', editId: null as number | null });

const BLANK_FORM: UserFormData = {
    name: '', email: '', password: '', password_confirmation: '',
    role: 'user', phone: '', job_title: '', address: '',
};

const form = reactive<UserFormData>({ ...BLANK_FORM });

function openCreateModal() {
    usersStore.clearErrors();
    Object.assign(form, { ...BLANK_FORM });
    modal.mode   = 'create';
    modal.editId = null;
    modal.open   = true;
}

function openEditModal(user: AdminUser) {
    usersStore.clearErrors();
    Object.assign(form, {
        name:                  user.name,
        email:                 user.email,
        password:              '',
        password_confirmation: '',
        role:                  user.role,
        phone:                 user.phone     ?? '',
        job_title:             user.job_title ?? '',
        address:               user.address   ?? '',
    });
    modal.mode   = 'edit';
    modal.editId = user.id;
    modal.open   = true;
}

function closeModal() {
    modal.open = false;
    usersStore.clearErrors();
}

// ── Submit ────────────────────────────────────────────────────────────────────
async function submitForm() {
    let result: AdminUser | null;

    if (modal.mode === 'create') {
        result = await usersStore.createUser({ ...form });
    } else {
        const payload = { ...form } as Partial<UserFormData>;
        if (!payload.password) {
            delete payload.password;
            delete payload.password_confirmation;
        }
        result = await usersStore.updateUser(modal.editId!, payload);
    }

    if (result) {
        showToast(
            modal.mode === 'create' ? 'User created successfully.' : 'User updated successfully.',
            'success',
        );
        closeModal();
        await loadUsers(usersStore.users?.current_page ?? 1);
    } else if (usersStore.error && !Object.keys(usersStore.errors).length) {
        showToast(usersStore.error, 'error');
    }
}

// ── Delete ────────────────────────────────────────────────────────────────────
async function onDelete(user: AdminUser) {
    if (user.id === authStore.user?.id) return;

    const confirmed = await confirmDialog({
        title:       `Delete "${user.name}"?`,
        text:        'This will permanently delete the user and revoke all their sessions.',
        confirmText: 'Yes, Delete',
        type:        'warning',
    });
    if (!confirmed) return;

    const ok = await usersStore.deleteUser(user.id);
    if (ok) {
        showToast('User deleted successfully.', 'success');
        // If last item on page > 1, go back one page
        const page = usersStore.users?.data.length === 1 && (usersStore.users?.current_page ?? 1) > 1
            ? (usersStore.users?.current_page ?? 1) - 1
            : (usersStore.users?.current_page ?? 1);
        await loadUsers(page);
    } else {
        showToast(usersStore.error ?? 'Failed to delete user.', 'error');
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function initials(name: string): string {
    return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
}

function formatDate(val: string | null | undefined): string {
    if (!val) return '—';
    return new Date(val).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

// ── Mount ─────────────────────────────────────────────────────────────────────
onMounted(() => loadUsers());
</script>
