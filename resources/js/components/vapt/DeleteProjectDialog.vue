<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Trash2, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy } from '@/routes/projects';

const props = defineProps<{
    project: { id: number; code: string; name: string };
    counts: { scans: number; findings: number; reports: number };
}>();

const open = ref(false);
const form = useForm({ confirm: '' });

const confirmed = computed(
    () => form.confirm.trim().toUpperCase() === props.project.code,
);

watch(open, (isOpen) => {
    if (!isOpen) {
        form.reset();
        form.clearErrors();
    }
});

function submit() {
    if (!confirmed.value) {
        return;
    }

    form.submit(destroy(props.project.id), { preserveScroll: true });
}

const plural = (count: number, word: string) =>
    `${count} ${word}${count === 1 ? '' : 's'}`;
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button variant="outline" class="text-destructive">
                <Trash2 /> Delete
            </Button>
        </DialogTrigger>
        <DialogContent>
            <form class="grid gap-4" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>
                        Delete {{ project.name }} permanently?
                    </DialogTitle>
                    <DialogDescription>
                        This cannot be undone. Scans in Nessus are not touched.
                    </DialogDescription>
                </DialogHeader>

                <div
                    class="flex gap-3 rounded-lg border border-red-600/30 bg-red-500/5 p-3 text-sm text-red-800 dark:text-red-300"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                    <div>
                        <p class="font-medium">This removes from VAPT Nexus:</p>
                        <ul class="mt-1 list-disc pl-4">
                            <li>{{ plural(counts.scans, 'scan') }}</li>
                            <li>{{ plural(counts.findings, 'finding') }}</li>
                            <li>
                                {{ plural(counts.reports, 'report') }} and their
                                PDF files
                            </li>
                            <li>hosts, raw Nessus results and members</li>
                        </ul>
                        <p class="mt-2">
                            To keep the history instead, edit the project and
                            set its status to <strong>Archived</strong>.
                        </p>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="confirm-delete">
                        Type <code class="font-mono">{{ project.code }}</code>
                        to confirm
                    </Label>
                    <Input
                        id="confirm-delete"
                        v-model="form.confirm"
                        autocomplete="off"
                        spellcheck="false"
                        class="font-mono uppercase"
                        :aria-invalid="!!form.errors.confirm || undefined"
                    />
                    <InputError :message="form.errors.confirm" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="!confirmed || form.processing"
                    >
                        Delete permanently
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
