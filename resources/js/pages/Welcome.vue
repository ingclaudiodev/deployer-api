<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dashboard, login } from '@/routes/panel';

defineProps<{
    frontendUrl: string;
}>();

const page = usePage();
const panelUrl = computed(() =>
    page.props.auth.user ? dashboard.url() : login.url(),
);

const pipeline = [
    { step: '01', title: 'Repositorio', detail: 'tu código, tal como está' },
    {
        step: '02',
        title: 'Análisis con IA',
        detail: 'stack, puertos, servicios',
    },
    { step: '03', title: 'Contenedor', detail: 'Dockerfile y manifiestos' },
    { step: '04', title: 'Cloud Run', detail: 'en línea, escalando a cero' },
];

const capabilities = [
    {
        tag: 'auth',
        title: 'Acceso en dos pasos',
        body: 'Contraseña y un código de un solo uso por correo. Los dispositivos de confianza se saltan el código, nunca la contraseña.',
    },
    {
        tag: 'ia',
        title: 'Arquitecto de nube',
        body: 'Un modelo de Vertex AI lee el repositorio y propone el Dockerfile, los servicios y las variables que necesita en Cloud Run.',
    },
    {
        tag: 'colas',
        title: 'Trabajo en segundo plano',
        body: 'Correos, alertas y avisos salen por colas supervisadas con Horizon, cada job etiquetado para encontrarlo después.',
    },
    {
        tag: 'tiempo real',
        title: 'Eventos al instante',
        body: 'Un servidor WebSocket propio empuja al navegador lo que pasa en tu cuenta, sin recargar la página.',
    },
];
</script>

<template>
    <div class="deployer-backdrop min-h-screen">
        <Head>
            <meta
                name="description"
                content="La API de deployer.cl: autentica cuentas, analiza repositorios con IA y los despliega en Google Cloud Run."
            />
        </Head>

        <header
            class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-5 py-6 lg:px-8"
        >
            <img
                src="/img/deployer-lockup.png"
                alt="deployer.cl"
                width="1200"
                height="293"
                class="h-8 w-auto sm:h-9"
                fetchpriority="high"
            />
            <nav class="flex items-center gap-2" aria-label="Principal">
                <a
                    :href="frontendUrl"
                    class="btn hidden btn-ghost btn-sm sm:inline-flex"
                >
                    deployer.cl
                    <span aria-hidden="true">↗</span>
                </a>
                <Link
                    :href="panelUrl"
                    class="btn btn-outline btn-primary btn-sm"
                >
                    Panel
                </Link>
            </nav>
        </header>

        <main class="mx-auto max-w-6xl px-5 pb-24 lg:px-8">
            <section
                class="grid items-center gap-12 pt-10 pb-20 lg:grid-cols-[1.1fr_0.9fr] lg:pt-20"
            >
                <div class="animate-rise space-y-7">
                    <p
                        class="badge gap-2 border-base-300 bg-base-100/70 font-mono text-xs badge-lg"
                    >
                        <span
                            class="status animate-pulse status-success"
                            aria-hidden="true"
                        ></span>
                        API en línea
                    </p>

                    <h1
                        class="font-display text-4xl leading-[1.08] font-semibold tracking-tight text-balance sm:text-5xl lg:text-6xl"
                    >
                        El motor detrás de
                        <span class="text-brand-gradient">cada despliegue</span>
                    </h1>

                    <p
                        class="max-w-xl text-lg leading-relaxed text-pretty text-base-content/70"
                    >
                        Esta es la API de deployer.cl. Autentica las cuentas,
                        analiza los repositorios con IA y deja cada aplicación
                        corriendo en Google Cloud Run.
                    </p>

                    <div class="flex flex-wrap gap-3">
                        <a :href="frontendUrl" class="btn btn-primary">
                            Ir a deployer.cl
                            <span aria-hidden="true">→</span>
                        </a>
                        <Link :href="panelUrl" class="btn btn-ghost">
                            Panel de operaciones
                        </Link>
                    </div>
                </div>

                <div
                    class="animate-rise overflow-hidden rounded-box border border-base-300 bg-neutral shadow-2xl shadow-black/50"
                    style="animation-delay: 140ms"
                >
                    <div
                        class="flex items-center gap-2 border-b border-base-300 px-4 py-3"
                    >
                        <span class="size-2.5 rounded-full bg-error/70"></span>
                        <span
                            class="size-2.5 rounded-full bg-warning/70"
                        ></span>
                        <span
                            class="size-2.5 rounded-full bg-success/70"
                        ></span>
                        <span class="eyebrow ml-3">acceso en dos pasos</span>
                    </div>
                    <pre
                        class="overflow-x-auto p-5 font-mono text-[0.8125rem] leading-7 text-neutral-content"
                    ><code><span class="text-primary">POST</span> /api/auth/login
<span class="text-base-content/45">  { "email": "…", "password": "…" }</span>
<span class="text-warning">202</span> { "challengeId": "0193…", "method": "email" }

<span class="text-primary">POST</span> /api/auth/login/verify
<span class="text-base-content/45">  { "challenge_id": "0193…", "code": "······" }</span>
<span class="text-success">200</span> { "accessToken": "…", "user": { … } }</code></pre>
                </div>
            </section>

            <section
                aria-labelledby="pipeline-title"
                class="animate-rise rounded-box border border-base-300 bg-base-100/60 p-6 backdrop-blur sm:p-9"
                style="animation-delay: 220ms"
            >
                <p class="eyebrow">Cómo llega tu código a producción</p>
                <h2
                    id="pipeline-title"
                    class="mt-2 font-display text-2xl font-semibold tracking-tight"
                >
                    Del repositorio a una URL
                </h2>

                <ol class="relative mt-9 grid gap-8 sm:grid-cols-4 sm:gap-4">
                    <svg
                        class="pointer-events-none absolute top-4 left-0 hidden h-1 w-full sm:block"
                        preserveAspectRatio="none"
                        viewBox="0 0 100 1"
                        aria-hidden="true"
                    >
                        <line
                            x1="2"
                            y1="0.5"
                            x2="98"
                            y2="0.5"
                            stroke="var(--color-primary)"
                            stroke-opacity="0.55"
                            stroke-width="1"
                            stroke-dasharray="1.2 1.8"
                            vector-effect="non-scaling-stroke"
                            class="animate-travel [animation-direction:reverse]"
                        />
                    </svg>
                    <li
                        v-for="stage in pipeline"
                        :key="stage.step"
                        class="relative space-y-3"
                    >
                        <span
                            class="flex size-8 items-center justify-center rounded-full border border-primary/60 bg-base-200 font-mono text-xs text-primary"
                        >
                            {{ stage.step }}
                        </span>
                        <div>
                            <p class="font-display font-semibold">
                                {{ stage.title }}
                            </p>
                            <p class="text-sm text-base-content/60">
                                {{ stage.detail }}
                            </p>
                        </div>
                    </li>
                </ol>
            </section>

            <section aria-labelledby="capabilities-title" class="pt-20">
                <p class="eyebrow">Lo que resuelve esta API</p>
                <h2
                    id="capabilities-title"
                    class="mt-2 font-display text-2xl font-semibold tracking-tight"
                >
                    Cuatro piezas, un servicio
                </h2>

                <div class="mt-8 grid gap-5 sm:grid-cols-2">
                    <article
                        v-for="capability in capabilities"
                        :key="capability.tag"
                        class="card border border-base-300 bg-base-100/70 transition-colors duration-300 hover:border-primary/50"
                    >
                        <div class="card-body gap-3">
                            <p class="eyebrow text-primary">
                                {{ capability.tag }}
                            </p>
                            <h3 class="font-display text-lg font-semibold">
                                {{ capability.title }}
                            </h3>
                            <p
                                class="text-sm leading-relaxed text-base-content/65"
                            >
                                {{ capability.body }}
                            </p>
                        </div>
                    </article>
                </div>
            </section>
        </main>

        <footer class="border-t border-base-300">
            <div
                class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-5 py-6 text-sm text-base-content/50 lg:px-8"
            >
                <span>© {{ new Date().getFullYear() }} deployer.cl</span>
                <span class="font-mono text-xs">API de deployer.cl</span>
            </div>
        </footer>
    </div>
</template>
