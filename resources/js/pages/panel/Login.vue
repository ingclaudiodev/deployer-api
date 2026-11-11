<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { store } from '@/routes/panel/login';

const page = usePage();
</script>

<template>
    <AuthLayout
        title="Entrar"
        description="Usa la misma cuenta con la que entras a deployer.cl."
    >
        <Head title="Entrar al panel" />

        <Form
            v-bind="store.form()"
            :reset-on-error="['password']"
            class="space-y-5"
            #default="{ errors, processing }"
        >
            <div
                v-if="errors.email ?? page.props.errors?.email"
                role="alert"
                class="alert alert-soft text-sm alert-error"
            >
                {{ errors.email ?? page.props.errors?.email }}
            </div>

            <label class="block space-y-2">
                <span class="text-sm font-medium">Correo</span>
                <input
                    v-focus
                    type="email"
                    name="email"
                    required
                    autocomplete="username"
                    placeholder="tu@deployer.cl"
                    class="input w-full"
                    :class="{ 'input-error': errors.email }"
                />
            </label>

            <label class="block space-y-2">
                <span class="text-sm font-medium">Contraseña</span>
                <input
                    type="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    class="input w-full"
                    :class="{ 'input-error': errors.password }"
                />
                <span v-if="errors.password" class="text-sm text-error">
                    {{ errors.password }}
                </span>
            </label>

            <button
                type="submit"
                class="btn w-full btn-primary"
                :disabled="processing"
            >
                <span
                    v-if="processing"
                    class="loading loading-sm loading-spinner"
                ></span>
                Continuar
            </button>
        </Form>
    </AuthLayout>
</template>
