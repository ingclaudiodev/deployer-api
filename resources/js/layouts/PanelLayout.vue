<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dashboard, logout } from '@/routes/panel';

defineProps<{
    horizonUrl: string;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const initials = computed(() =>
    (user.value?.name ?? '?')
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => word.charAt(0).toUpperCase())
        .join(''),
);
</script>

<template>
    <div class="deployer-backdrop min-h-screen">
        <header
            class="sticky top-0 z-30 border-b border-base-300 bg-base-200/80 backdrop-blur"
        >
            <nav
                class="navbar mx-auto max-w-7xl gap-2 px-4 lg:px-8"
                aria-label="Navegación del panel"
            >
                <div class="navbar-start gap-3">
                    <Link
                        :href="dashboard.url()"
                        class="flex items-center gap-3"
                    >
                        <img
                            src="/img/deployer-mark.png"
                            alt=""
                            width="256"
                            height="282"
                            class="h-8 w-auto"
                        />
                        <span
                            class="font-display text-base font-semibold tracking-tight"
                        >
                            Panel
                        </span>
                    </Link>
                </div>

                <div class="navbar-center">
                    <ul class="menu menu-horizontal gap-1 px-0 text-sm">
                        <li>
                            <Link :href="dashboard.url()" class="menu-active">
                                Resumen
                            </Link>
                        </li>
                        <li>
                            <a
                                :href="horizonUrl"
                                target="_blank"
                                rel="noopener"
                            >
                                Horizon
                                <span aria-hidden="true">↗</span>
                            </a>
                        </li>
                    </ul>
                </div>

                <div class="navbar-end">
                    <div v-if="user" class="dropdown dropdown-end">
                        <button
                            type="button"
                            class="btn gap-3 btn-ghost px-2"
                            aria-label="Cuenta"
                        >
                            <span class="avatar avatar-placeholder">
                                <span
                                    class="flex size-8 items-center justify-center rounded-full bg-primary font-mono text-xs font-medium text-primary-content"
                                >
                                    {{ initials }}
                                </span>
                            </span>
                            <span class="hidden text-sm sm:inline">
                                {{ user.name }}
                            </span>
                        </button>
                        <div
                            tabindex="0"
                            class="dropdown-content z-40 mt-3 w-64 rounded-box border border-base-300 bg-base-100 p-2 shadow-xl shadow-black/40"
                        >
                            <div class="px-3 py-2">
                                <p class="truncate text-sm font-medium">
                                    {{ user.email }}
                                </p>
                                <p class="eyebrow mt-1">{{ user.role }}</p>
                            </div>
                            <div class="divider my-0"></div>
                            <Link
                                :href="logout.url()"
                                method="post"
                                as="button"
                                class="btn w-full justify-start btn-ghost btn-sm"
                            >
                                Cerrar sesión
                            </Link>
                        </div>
                    </div>
                </div>
            </nav>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-8 lg:px-8 lg:py-10">
            <slot />
        </main>
    </div>
</template>
