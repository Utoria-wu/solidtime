<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { EllipsisHorizontalIcon } from '@heroicons/vue/20/solid';
import { useInterfaceLanguage } from '@/utils/useInterfaceLanguage';
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
} from '@/packages/ui/src';
import { ArrowLeftOnRectangleIcon, ChatBubbleLeftRightIcon } from '@heroicons/vue/24/solid';
import { openFeedback } from '@/utils/feedback';

const page = usePage<{ has_services_extension?: boolean }>();
const { t } = useInterfaceLanguage();

const logout = () => {
    router.post(route('logout'));
};
</script>
<template>
    <div class="relative">
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <button
                    type="button"
                    data-testid="current_user_button"
                    :aria-label="t('Account options')"
                    :title="t('Account options')"
                    class="grid size-8 place-items-center rounded-md text-text-secondary hover:bg-secondary hover:text-text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-ring">
                    <EllipsisHorizontalIcon class="size-5" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent side="top" align="end" class="min-w-36">
                <DropdownMenuLabel>{{ t('Account options') }}</DropdownMenuLabel>

                <DropdownMenuItem v-if="page.props.has_services_extension" as-child>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2.5 w-full"
                        @click="openFeedback">
                        <ChatBubbleLeftRightIcon class="w-5 h-5 text-icon-default" />
                        <span>Feedback</span>
                    </button>
                </DropdownMenuItem>

                <form class="w-full" @submit.prevent="logout">
                    <DropdownMenuItem as-child class="inline-flex items-center gap-2.5 w-full">
                        <button type="submit" data-testid="logout_button">
                            <ArrowLeftOnRectangleIcon class="w-5 h-5 text-icon-default" />
                            <span>Log Out</span>
                        </button>
                    </DropdownMenuItem>
                </form>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
