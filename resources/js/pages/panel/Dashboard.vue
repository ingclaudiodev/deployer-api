<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3';
import { computed } from 'vue';
import RolesChart from '@/components/panel/RolesChart.vue';
import SignupsChart from '@/components/panel/SignupsChart.vue';
import PanelLayout from '@/layouts/PanelLayout.vue';
import type {
    HorizonStatus,
    PanelStats,
    Probe,
    RecentUser,
    RoleCount,
    SignupDay,
    SystemHealth,
    SystemInfo,
} from '@/types';

const props = defineProps<{
    stats: PanelStats;
    roles: RoleCount[];
    signups: SignupDay[];
    recentUsers: RecentUser[];
    system: SystemInfo;
    horizonUrl: string;
    health?: SystemHealth;
}>();

// Only the live card is refreshed: the rest of the page costs database queries.
usePoll(15000, { only: ['health'] });

const number = new Intl.NumberFormat('es-CL');
const dateTime = new Intl.DateTimeFormat('es-CL', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

const figures = computed(() => [
    {
        label: 'Usuarios',
        value: props.stats.users,
        hint: `${number.format(props.stats.unverifiedUsers)} sin verificar`,
    },
    {
        label: 'Altas en 7 días',
        value: props.stats.newUsersThisWeek,
        hint: 'cuentas nuevas',
    },
    {
        label: 'Sesiones de API',
        value: props.stats.activeTokens,
        hint: 'tokens vigentes',
    },
    {
        label: 'Códigos pendientes',
        value: props.stats.pendingChallenges,
        hint: 'accesos a medio camino',
    },
    {
        label: 'Dispositivos de confianza',
        value: props.stats.trustedDevices,
        hint: 'omiten el código',
    },
]);

const horizonLabels: Record<
    HorizonStatus,
    { text: string; badge: string; dot: string }
> = {
    running: {
        text: 'Activo',
        badge: 'badge-success',
        dot: 'status-success',
    },
    paused: {
        text: 'En pausa',
        badge: 'badge-warning',
        dot: 'status-warning',
    },
    inactive: {
        text: 'Detenido',
        badge: 'badge-error',
        dot: 'status-error',
    },
    unreachable: {
        text: 'Sin conexión',
        badge: 'badge-error',
        dot: 'status-error',
    },
};

const horizon = computed(() =>
    props.health ? horizonLabels[props.health.horizon.status] : null,
);

const services = computed<{ name: string; probe: Probe }[]>(() =>
    props.health
        ? [
              { name: 'Base de datos', probe: props.health.database },
              { name: 'Redis', probe: props.health.redis },
          ]
        : [],
);

const configuration = computed(() => [
    { label: 'PHP', value: props.system.php },
    { label: 'Laravel', value: props.system.laravel },
    { label: 'Servidor', value: props.system.octane ?? 'php-fpm' },
    { label: 'Colas', value: props.system.queue },
    { label: 'Caché', value: props.system.cache },
    { label: 'Sesiones', value: props.system.session },
    { label: 'Correo', value: props.system.mailer },
    { label: 'Modelo de IA', value: props.system.aiModel },
    {
        label: 'Código por correo',
        value: props.system.twoFactor ? 'activo' : 'desactivado',
    },
]);

function wait(seconds: number): string {
    return seconds < 60 ? `${seconds} s` : `${Math.round(seconds / 60)} min`;
}
</script>

<template>
    <PanelLayout :horizon-url="horizonUrl">
        <Head title="Panel" />

        <div class="space-y-6">
            <header
                class="flex animate-rise flex-wrap items-end justify-between gap-4"
            >
                <div class="space-y-2">
                    <p class="eyebrow">{{ system.apiUrl }}</p>
                    <h1
                        class="font-display text-3xl font-semibold tracking-tight"
                    >
                        Resumen
                    </h1>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="badge badge-outline font-mono badge-primary">
                        {{ system.environment }}
                    </span>
                    <span
                        v-if="system.debug"
                        class="badge font-mono badge-warning"
                    >
                        debug activo
                    </span>
                </div>
            </header>

            <section
                aria-label="Cifras de cuentas"
                class="stats w-full animate-rise stats-vertical border border-base-300 bg-base-100 sm:stats-horizontal"
                style="animation-delay: 60ms"
            >
                <div v-for="figure in figures" :key="figure.label" class="stat">
                    <div class="eyebrow stat-title">{{ figure.label }}</div>
                    <div
                        class="stat-value font-display text-3xl font-semibold tabular-nums"
                    >
                        {{ number.format(figure.value) }}
                    </div>
                    <div class="stat-desc">{{ figure.hint }}</div>
                </div>
            </section>

            <div
                class="grid animate-rise gap-6 lg:grid-cols-3"
                style="animation-delay: 120ms"
            >
                <section
                    class="card border border-base-300 bg-base-100 lg:col-span-2"
                >
                    <div class="card-body gap-5">
                        <div
                            class="flex flex-wrap items-center justify-between gap-3"
                        >
                            <div>
                                <p class="eyebrow">Colas</p>
                                <h2 class="font-display text-lg font-semibold">
                                    Horizon
                                </h2>
                            </div>
                            <div class="flex items-center gap-3">
                                <span
                                    v-if="horizon"
                                    class="badge gap-2 badge-soft"
                                    :class="horizon.badge"
                                >
                                    <span
                                        class="status"
                                        :class="horizon.dot"
                                        aria-hidden="true"
                                    ></span>
                                    {{ horizon.text }}
                                </span>
                                <span v-else class="h-6 w-24 skeleton"></span>
                                <a
                                    :href="horizonUrl"
                                    target="_blank"
                                    rel="noopener"
                                    class="btn btn-primary btn-sm"
                                >
                                    Abrir Horizon
                                    <span aria-hidden="true">↗</span>
                                </a>
                            </div>
                        </div>

                        <dl
                            v-if="health"
                            class="grid grid-cols-2 gap-x-6 gap-y-5 sm:grid-cols-5"
                        >
                            <div>
                                <dt class="eyebrow">Procesos</dt>
                                <dd class="font-display text-2xl tabular-nums">
                                    {{
                                        number.format(health.horizon.processes)
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="eyebrow">Jobs por minuto</dt>
                                <dd class="font-display text-2xl tabular-nums">
                                    {{
                                        number.format(
                                            health.horizon.jobsPerMinute,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="eyebrow">Recientes</dt>
                                <dd class="font-display text-2xl tabular-nums">
                                    {{
                                        number.format(health.horizon.recentJobs)
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="eyebrow">Fallidos</dt>
                                <dd
                                    class="font-display text-2xl tabular-nums"
                                    :class="{
                                        'text-error':
                                            health.horizon.failedJobs > 0,
                                    }"
                                >
                                    {{
                                        number.format(health.horizon.failedJobs)
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="eyebrow">Espera máxima</dt>
                                <dd class="font-display text-2xl tabular-nums">
                                    {{ wait(health.horizon.maxWaitSeconds) }}
                                </dd>
                            </div>
                        </dl>
                        <div
                            v-else
                            class="grid grid-cols-2 gap-x-6 gap-y-5 sm:grid-cols-5"
                            aria-busy="true"
                        >
                            <div v-for="n in 5" :key="n" class="space-y-2">
                                <div class="h-3 w-20 skeleton"></div>
                                <div class="h-7 w-14 skeleton"></div>
                            </div>
                        </div>

                        <p
                            v-if="health?.horizon.status === 'inactive'"
                            role="alert"
                            class="alert alert-soft text-sm alert-warning"
                        >
                            Sin workers no sale ningún correo: tampoco el código
                            para entrar a este panel.
                        </p>
                    </div>
                </section>

                <section class="card border border-base-300 bg-base-100">
                    <div class="card-body gap-5">
                        <div>
                            <p class="eyebrow">En vivo · cada 15 s</p>
                            <h2 class="font-display text-lg font-semibold">
                                Servicios
                            </h2>
                        </div>

                        <ul v-if="health" class="space-y-4">
                            <li
                                v-for="service in services"
                                :key="service.name"
                                class="flex items-center justify-between gap-4"
                            >
                                <span class="flex items-center gap-3">
                                    <span
                                        class="status"
                                        :class="
                                            service.probe.ok
                                                ? 'status-success'
                                                : 'status-error'
                                        "
                                        aria-hidden="true"
                                    ></span>
                                    {{ service.name }}
                                </span>
                                <span
                                    class="font-mono text-sm"
                                    :class="
                                        service.probe.ok
                                            ? 'text-base-content/60'
                                            : 'text-error'
                                    "
                                >
                                    {{
                                        service.probe.ok
                                            ? `${service.probe.latencyMs} ms`
                                            : 'sin respuesta'
                                    }}
                                </span>
                            </li>
                        </ul>
                        <ul v-else class="space-y-4" aria-busy="true">
                            <li
                                v-for="n in 2"
                                :key="n"
                                class="flex items-center justify-between"
                            >
                                <span class="h-4 w-28 skeleton"></span>
                                <span class="h-4 w-14 skeleton"></span>
                            </li>
                        </ul>

                        <a
                            :href="system.frontendUrl"
                            target="_blank"
                            rel="noopener"
                            class="mt-auto link font-mono text-sm link-hover"
                        >
                            {{ system.frontendUrl }}
                            <span aria-hidden="true">↗</span>
                        </a>
                    </div>
                </section>
            </div>

            <div
                class="grid animate-rise gap-6 lg:grid-cols-3"
                style="animation-delay: 180ms"
            >
                <section
                    class="card border border-base-300 bg-base-100 lg:col-span-2"
                >
                    <div class="card-body gap-4">
                        <div>
                            <p class="eyebrow">
                                Últimos {{ signups.length }} días
                            </p>
                            <h2 class="font-display text-lg font-semibold">
                                Altas de usuarios
                            </h2>
                        </div>
                        <SignupsChart :signups="signups" />
                    </div>
                </section>

                <section class="card border border-base-300 bg-base-100">
                    <div class="card-body gap-4">
                        <div>
                            <p class="eyebrow">Distribución</p>
                            <h2 class="font-display text-lg font-semibold">
                                Usuarios por rol
                            </h2>
                        </div>
                        <RolesChart :roles="roles" />
                    </div>
                </section>
            </div>

            <div
                class="grid animate-rise gap-6 lg:grid-cols-3"
                style="animation-delay: 240ms"
            >
                <section
                    class="card border border-base-300 bg-base-100 lg:col-span-2"
                >
                    <div class="card-body gap-4">
                        <div>
                            <p class="eyebrow">Cuentas</p>
                            <h2 class="font-display text-lg font-semibold">
                                Últimos registros
                            </h2>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="table">
                                <thead>
                                    <tr class="eyebrow">
                                        <th scope="col">Usuario</th>
                                        <th scope="col">Rol</th>
                                        <th scope="col">Correo</th>
                                        <th scope="col">Alta</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="user in recentUsers"
                                        :key="user.id"
                                    >
                                        <td>
                                            <div class="font-medium">
                                                {{ user.name }}
                                            </div>
                                            <div
                                                class="font-mono text-xs text-base-content/55"
                                            >
                                                {{ user.email }}
                                            </div>
                                        </td>
                                        <td>{{ user.role }}</td>
                                        <td>
                                            <span
                                                class="badge badge-soft badge-sm"
                                                :class="
                                                    user.verified
                                                        ? 'badge-success'
                                                        : 'badge-warning'
                                                "
                                            >
                                                {{
                                                    user.verified
                                                        ? 'verificado'
                                                        : 'pendiente'
                                                }}
                                            </span>
                                        </td>
                                        <td
                                            class="font-mono text-xs whitespace-nowrap text-base-content/60"
                                        >
                                            {{
                                                user.createdAt
                                                    ? dateTime.format(
                                                          new Date(
                                                              user.createdAt,
                                                          ),
                                                      )
                                                    : '—'
                                            }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section class="card border border-base-300 bg-base-100">
                    <div class="card-body gap-4">
                        <div>
                            <p class="eyebrow">Esta instancia</p>
                            <h2 class="font-display text-lg font-semibold">
                                Configuración
                            </h2>
                        </div>
                        <dl class="divide-y divide-base-300 text-sm">
                            <div
                                v-for="item in configuration"
                                :key="item.label"
                                class="flex items-center justify-between gap-4 py-2.5"
                            >
                                <dt class="text-base-content/60">
                                    {{ item.label }}
                                </dt>
                                <dd class="truncate font-mono">
                                    {{ item.value }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </section>
            </div>
        </div>
    </PanelLayout>
</template>
