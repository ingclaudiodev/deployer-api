<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head>
        <meta
            name="description"
            content="deployer.cl — despliega tus aplicaciones en Chile."
        />
    </Head>
    <div
        class="flex min-h-screen flex-col items-center justify-center bg-[#15213a] p-6 text-white lg:p-8"
    >
        <main
            class="flex w-full max-w-5xl flex-col items-center gap-8 opacity-100 transition-opacity duration-750 starting:opacity-0"
        >
            <img
                src="/img/deployer-logo-web.jpg"
                srcset="
                    /img/deployer-logo-web.jpg 1400w,
                    /img/deployer-logo.jpeg    2752w
                "
                sizes="(min-width: 1024px) 1024px, 100vw"
                alt="deployer.cl"
                class="w-full max-w-4xl rounded-2xl object-contain shadow-2xl ring-1 shadow-black/40 ring-white/10"
                fetchpriority="high"
            />
            <p class="text-center text-sm text-white/60">Próximamente</p>
        </main>
    </div>
</template>
