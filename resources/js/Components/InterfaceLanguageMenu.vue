<script setup lang="ts">
import { ChevronUpIcon, GlobeAltIcon } from '@heroicons/vue/20/solid';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/packages/ui/src';
import { useInterfaceLanguage } from '@/utils/useInterfaceLanguage';

const { language, t, setLanguage } = useInterfaceLanguage();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                :aria-label="t('Interface language')"
                class="mb-2 flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm text-text-secondary hover:bg-secondary hover:text-text-primary focus-visible:outline-2 focus-visible:outline-ring">
                <GlobeAltIcon class="size-4 shrink-0" />
                <span class="flex-1">{{ language === 'zh-CN' ? '简体中文' : 'English' }}</span>
                <ChevronUpIcon class="size-4 shrink-0" />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            side="top"
            align="start"
            class="w-[var(--reka-dropdown-menu-trigger-width)]">
            <DropdownMenuRadioGroup :model-value="language" @update:model-value="setLanguage">
                <DropdownMenuRadioItem value="en" lang="en">English</DropdownMenuRadioItem>
                <DropdownMenuRadioItem value="zh-CN" lang="zh-CN">简体中文</DropdownMenuRadioItem>
            </DropdownMenuRadioGroup>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
