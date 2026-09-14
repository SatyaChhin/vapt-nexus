<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import NessusServerController from '@/actions/App/Http/Controllers/NessusServerController';
import Heading from '@/components/Heading.vue';
import NessusServerForm from '@/components/vapt/NessusServerForm.vue';
import { edit, index } from '@/routes/nessus-servers';
import type { NessusServer } from '@/types';

const props = defineProps<{
    server: NessusServer;
    allowedNetworks: string[];
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Nessus Servers', href: index() },
        { title: props.server.name, href: edit(props.server.id) },
    ],
});
</script>

<template>
    <Head :title="`Edit ${server.name}`" />

    <div class="flex flex-1 flex-col p-4">
        <Heading
            :title="`Edit ${server.name}`"
            description="Changing the URL or keys resets the connection status until the next test."
        />
        <NessusServerForm
            :action="NessusServerController.update(server.id)"
            :server="server"
            :allowed-networks="allowedNetworks"
        />
    </div>
</template>
