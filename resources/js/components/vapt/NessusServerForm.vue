<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { KeyRound, ShieldAlert } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/nessus-servers';
import type { NessusServer } from '@/types';
import type { RouteDefinition } from '@/wayfinder';

const props = defineProps<{
    action: RouteDefinition<'post' | 'put' | 'patch'>;
    server?: NessusServer;
    defaults?: { base_url: string; verify_ssl: boolean };
    allowedNetworks: string[];
}>();

const editing = !!props.server;

const form = useForm({
    name: props.server?.name ?? 'Local Nessus',
    base_url: props.server?.base_url ?? props.defaults?.base_url ?? '',
    access_key: '',
    secret_key: '',
    verify_ssl: props.server?.verify_ssl ?? props.defaults?.verify_ssl ?? false,
});

function submit() {
    form.submit(props.action, {
        preserveScroll: true,
        // Never keep keys in page state after a round trip.
        onFinish: () => form.reset('access_key', 'secret_key'),
    });
}
</script>

<template>
    <form class="max-w-2xl space-y-6" @submit.prevent="submit">
        <div class="grid gap-2">
            <Label for="name">Name</Label>
            <Input
                id="name"
                v-model="form.name"
                required
                maxlength="100"
                placeholder="Local Nessus"
            />
            <InputError :message="form.errors.name" />
        </div>

        <div class="grid gap-2">
            <Label for="base_url">URL</Label>
            <Input
                id="base_url"
                v-model="form.base_url"
                type="url"
                required
                placeholder="https://192.168.56.10:8834"
                autocomplete="off"
                spellcheck="false"
            />
            <p class="text-muted-foreground text-xs">
                Use the VM's host-only address. <code>localhost</code> would
                point at this Windows machine, not the VM.
                <template v-if="allowedNetworks.length">
                    Allowed networks:
                    <code>{{ allowedNetworks.join(', ') }}</code>
                </template>
            </p>
            <InputError :message="form.errors.base_url" />
        </div>

        <div class="grid gap-4 rounded-lg border p-4">
            <div class="flex items-start gap-3">
                <KeyRound
                    class="text-muted-foreground mt-0.5 size-4 shrink-0"
                />
                <div class="text-sm">
                    <p class="font-medium">API keys</p>
                    <p class="text-muted-foreground">
                        In Nessus:
                        <em>Settings › My Account › API Keys › Generate</em>.
                        Keys are encrypted at rest and never shown again.
                        <template v-if="editing">
                            Leave both blank to keep the current keys.
                        </template>
                    </p>
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="access_key">Access key</Label>
                <PasswordInput
                    id="access_key"
                    v-model="form.access_key"
                    :required="!editing"
                    autocomplete="off"
                    :placeholder="editing ? '•••••••• (unchanged)' : ''"
                />
                <InputError :message="form.errors.access_key" />
            </div>

            <div class="grid gap-2">
                <Label for="secret_key">Secret key</Label>
                <PasswordInput
                    id="secret_key"
                    v-model="form.secret_key"
                    :required="!editing"
                    autocomplete="off"
                    :placeholder="editing ? '•••••••• (unchanged)' : ''"
                />
                <InputError :message="form.errors.secret_key" />
            </div>
        </div>

        <div class="grid gap-2">
            <div class="flex items-center gap-3">
                <Checkbox
                    id="verify_ssl"
                    :model-value="form.verify_ssl"
                    @update:model-value="
                        (value) => (form.verify_ssl = value === true)
                    "
                />
                <Label for="verify_ssl">Verify TLS certificate</Label>
            </div>
            <InputError :message="form.errors.verify_ssl" />
            <Alert v-if="!form.verify_ssl" class="mt-1">
                <ShieldAlert class="size-4" />
                <AlertTitle>Certificate verification disabled</AlertTitle>
                <AlertDescription>
                    Acceptable for a local lab with Nessus's self-signed
                    certificate. Enable it for any production scanner.
                </AlertDescription>
            </Alert>
        </div>

        <div class="flex items-center gap-3">
            <Button type="submit" :disabled="form.processing">
                {{ editing ? 'Save changes' : 'Add server' }}
            </Button>
            <Button variant="ghost" as-child>
                <Link :href="index()">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
