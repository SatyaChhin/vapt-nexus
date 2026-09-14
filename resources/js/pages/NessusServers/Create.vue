<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import NessusServerController from '@/actions/App/Http/Controllers/NessusServerController';
import Heading from '@/components/Heading.vue';
import NessusServerForm from '@/components/vapt/NessusServerForm.vue';
import { create, index } from '@/routes/nessus-servers';

defineProps<{
    defaults: { base_url: string; verify_ssl: boolean };
    allowedNetworks: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Nessus Servers', href: index() },
            { title: 'Add', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="Add Nessus server" />

    <div class="flex flex-1 flex-col p-4">
        <Heading
            title="Add Nessus server"
            description="Register a scanner running in your VMware lab."
        />
        <NessusServerForm
            :action="NessusServerController.store()"
            :defaults="defaults"
            :allowed-networks="allowedNetworks"
        />
    </div>
</template>
