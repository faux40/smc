<template>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
        <span class="flex items-center gap-1.5">
            <span class="inline-block size-2.5 shrink-0 rounded-full bg-red-400 dark:bg-red-600" />
            Overdue / not started
        </span>
        <span class="flex items-center gap-1.5">
            <span class="inline-block size-2.5 shrink-0 rounded-full bg-amber-400 dark:bg-amber-600" />
            Expiring soon
        </span>
        <span class="flex items-center gap-1.5">
            <span class="inline-block size-2.5 shrink-0 rounded-full bg-green-400 dark:bg-green-600" />
            Current
        </span>
    </div>
</template>
