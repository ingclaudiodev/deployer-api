<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { home } from '@/routes';

defineProps<{
    title: string;
    description: string;
}>();
</script>

<template>
    <div
        class="deployer-backdrop flex min-h-screen flex-col items-center justify-center px-4 py-10"
    >
        <main class="w-full max-w-md animate-rise">
            <Link
                :href="home.url()"
                class="mx-auto mb-8 flex w-fit items-center"
                aria-label="Volver al inicio"
            >
                <img
                    src="/img/deployer-lockup.png"
                    alt="deployer.cl"
                    width="1200"
                    height="293"
                    class="h-9 w-auto"
                />
            </Link>

            <div
                class="card border border-base-300 bg-base-100/90 shadow-2xl shadow-black/40 backdrop-blur"
            >
                <div class="card-body gap-6 p-7 sm:p-9">
                    <header class="space-y-2">
                        <p class="eyebrow">Panel de operaciones</p>
                        <h1
                            class="font-display text-2xl font-semibold tracking-tight"
                        >
                            {{ title }}
                        </h1>
                        <p class="text-sm text-base-content/65">
                            {{ description }}
                        </p>
                    </header>

                    <slot />
                </div>
            </div>

            <p class="mt-6 text-center text-xs text-base-content/45">
                Acceso restringido a super administradores.
            </p>
        </main>
    </div>
</template>
