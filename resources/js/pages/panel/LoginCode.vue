<script setup lang="ts">
import { Form, Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { login } from '@/routes/panel';
import { resend, verify } from '@/routes/panel/login/code';

const props = defineProps<{
    destination: string;
    codeLength: number;
    expiresAt: string;
    resendAvailableAt: string;
    trustDays: number;
}>();

// Carries no data: the field only gives the server's refusal a typed place to land.
const resendForm = useForm({ code: '' });
const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    timer = setInterval(() => (now.value = Date.now()), 1000);
});

onBeforeUnmount(() => clearInterval(timer));

const secondsUntilResend = computed(() =>
    Math.max(
        0,
        Math.ceil((Date.parse(props.resendAvailableAt) - now.value) / 1000),
    ),
);

function requestNewCode(): void {
    resendForm.post(resend.url(), { preserveScroll: true });
}
</script>

<template>
    <AuthLayout
        title="Revisa tu correo"
        :description="`Enviamos un código de ${codeLength} dígitos a ${destination}.`"
    >
        <Head title="Código de acceso" />

        <Form
            v-bind="verify.form()"
            :reset-on-error="['code']"
            class="space-y-5"
            #default="{ errors, processing }"
        >
            <label class="block space-y-2">
                <span class="text-sm font-medium">Código</span>
                <input
                    v-focus
                    type="text"
                    name="code"
                    required
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    :maxlength="codeLength + 1"
                    :placeholder="'•'.repeat(codeLength)"
                    class="input w-full text-center font-mono text-2xl tracking-[0.5em] input-lg"
                    :class="{
                        'input-error': errors.code ?? resendForm.errors.code,
                    }"
                />
                <span
                    v-if="errors.code ?? resendForm.errors.code"
                    role="alert"
                    class="block text-sm text-error"
                >
                    {{ errors.code ?? resendForm.errors.code }}
                </span>
            </label>

            <label class="flex cursor-pointer items-center gap-3 text-sm">
                <input
                    type="checkbox"
                    name="remember_device"
                    value="1"
                    class="checkbox checkbox-sm checkbox-primary"
                />
                <span class="text-base-content/75">
                    No pedir el código en este navegador durante
                    {{ trustDays }} días
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
                Entrar al panel
            </button>
        </Form>

        <div
            class="flex items-center justify-between gap-4 border-t border-base-300 pt-5 text-sm"
        >
            <Link :href="login.url()" class="link link-hover">
                Usar otra cuenta
            </Link>
            <button
                type="button"
                class="btn btn-ghost btn-sm"
                :disabled="secondsUntilResend > 0 || resendForm.processing"
                @click="requestNewCode"
            >
                <template v-if="secondsUntilResend > 0">
                    Reenviar en {{ secondsUntilResend }} s
                </template>
                <template v-else>Reenviar código</template>
            </button>
        </div>
    </AuthLayout>
</template>
