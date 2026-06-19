<script setup lang="ts">
import { computed } from 'vue';
import { renderMarkdown } from '@/lib/markdown';

/**
 * A live, in-browser approximation of the printed certificate — used beside
 * the cert-text editor so authors see roughly how their title + body will lay
 * out. Pure/props-only; the exact output is always the downloadable PDF (this
 * deliberately skips the PDF background image and the script signature font).
 */
const props = withDefaults(
    defineProps<{
        orgName?: string;
        studentName?: string;
        certTitle?: string;
        certText?: string;
        issueDate?: string;
        expires?: string;
        hours?: string;
        instructor?: string;
        certId?: string;
        showSignature?: boolean;
    }>(),
    {
        orgName: 'Your Organization',
        studentName: 'Sample Student',
        certTitle: '',
        certText: '',
        issueDate: 'June 1, 2026',
        expires: 'June 1, 2028',
        hours: '4.00',
        instructor: 'Instructor Name',
        certId: 'CERT00000000-001',
        showSignature: true,
    },
);

const bodyHtml = computed(() => renderMarkdown(props.certText));
</script>

<template>
    <!-- 11:8.5 landscape sheet -->
    <div
        class="aspect-[11/8.5] w-full rounded-md border border-border bg-white p-[4%] font-serif text-[#1f2937] shadow-sm"
        data-testid="cert-preview"
    >
        <div class="flex h-full flex-col items-center text-center">
            <div class="text-[2.2cqw] font-bold tracking-wide">
                {{ orgName }}
            </div>
            <div class="mx-auto mt-1 w-[12%] border-b-2 border-[#b08d57]"></div>

            <div
                class="mt-[2%] text-[5cqw] tracking-[0.3em] text-[#1f5c3a]"
            >
                CERTIFICATE
            </div>
            <div class="text-[2cqw] italic text-[#4b5563]">of Training</div>

            <div class="mt-[2.5%] text-[1.6cqw] italic text-[#4b5563]">
                This certifies that
            </div>
            <div class="mt-1 text-[4cqw] text-[#14532d]">{{ studentName }}</div>
            <div class="mx-auto mt-1 w-[55%] border-b border-[#9ca3af]"></div>

            <!-- Author-controlled content -->
            <div class="mt-[3%] text-[2.6cqw] font-bold">
                {{ certTitle || 'Certificate title' }}
            </div>
            <div
                class="prose-cert mt-2 max-h-[28%] overflow-hidden text-[1.7cqw] leading-snug text-[#374151]"
            >
                <div v-if="certText" v-html="bodyHtml"></div>
                <span v-else class="italic text-muted-foreground">
                    Certificate body…
                </span>
            </div>

            <!-- Footer pinned to the bottom -->
            <div class="mt-auto flex w-full items-end justify-between pt-[2%] text-[1.4cqw]">
                <table class="text-left">
                    <tbody>
                        <tr>
                            <td class="pr-2 text-[#4b5563]">Certificate</td>
                            <td>{{ certId }}</td>
                        </tr>
                        <tr>
                            <td class="pr-2 text-[#4b5563]">Expires</td>
                            <td>{{ expires }}</td>
                        </tr>
                        <tr>
                            <td class="pr-2 text-[#4b5563]">Hours</td>
                            <td>{{ hours }}</td>
                        </tr>
                    </tbody>
                </table>
                <div class="text-center">
                    <div class="italic" style="font-family: cursive">
                        {{ showSignature ? instructor : ' ' }}
                    </div>
                    <div class="border-t border-[#6b7280] pt-0.5 text-[1.1cqw] tracking-wider text-[#4b5563]">
                        INSTRUCTOR
                    </div>
                    <div class="mt-1">{{ issueDate }}</div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Container-query units (cqw) scale the cert text to the preview width so it
   reads like a shrunk page regardless of the pane size. */
[data-testid='cert-preview'] {
    container-type: inline-size;
}
.prose-cert :deep(p) {
    margin: 0 0 0.4em;
}
.prose-cert :deep(strong) {
    font-weight: 600;
}
.prose-cert :deep(em) {
    font-style: italic;
}
.prose-cert :deep(ul),
.prose-cert :deep(ol) {
    margin: 0 0 0.4em 1.2em;
    list-style: revert;
    text-align: left;
    display: inline-block;
}
</style>
