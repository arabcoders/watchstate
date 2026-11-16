<template>
  <nav aria-label="Pagination" class="flex max-w-full min-w-0 flex-nowrap items-center gap-2">
    <UButton
      v-if="1 !== page"
      rel="first"
      aria-label="Go to first page"
      title="Go to first page"
      color="neutral"
      variant="ghost"
      size="sm"
      icon="i-lucide-chevrons-left"
      class="hidden shrink-0 sm:inline-flex"
      :disabled="isLoading"
      :loading="isLoading"
      square
      @click="changePage(1)"
    />

    <UButton
      v-if="1 < page"
      rel="prev"
      aria-label="Go to previous page"
      title="Go to previous page"
      color="neutral"
      variant="ghost"
      size="sm"
      icon="i-lucide-chevron-left"
      class="shrink-0"
      :disabled="isLoading"
      :loading="isLoading"
      square
      @click="changePage(page - 1)"
    />

    <USelect
      v-model="currentPage"
      :items="paginationItems"
      value-key="page"
      label-key="text"
      color="neutral"
      variant="outline"
      size="sm"
      class="w-40 shrink-0 sm:w-48"
      aria-label="Choose page"
      :disabled="isLoading"
      :ui="{ base: 'w-full' }"
      @update:model-value="changePage"
    />

    <UButton
      v-if="last_page > page"
      rel="next"
      aria-label="Go to next page"
      title="Go to next page"
      color="neutral"
      variant="ghost"
      size="sm"
      icon="i-lucide-chevron-right"
      class="shrink-0"
      :disabled="isLoading"
      :loading="isLoading"
      square
      @click="changePage(page + 1)"
    />

    <UButton
      v-if="last_page !== page"
      rel="last"
      aria-label="Go to last page"
      title="Go to last page"
      color="neutral"
      variant="ghost"
      size="sm"
      icon="i-lucide-chevrons-right"
      class="hidden shrink-0 sm:inline-flex"
      :disabled="isLoading"
      :loading="isLoading"
      square
      @click="changePage(last_page)"
    />
  </nav>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { UButton, USelect } from '#components';
import { makePagination } from '~/utils';

const emit = defineEmits<{
  (e: 'navigate', page: number): void;
}>();

const props = withDefaults(
  defineProps<{
    page: number;
    last_page: number;
    isLoading?: boolean;
  }>(),
  {
    isLoading: false,
  },
);

const currentPage = ref(props.page);

const paginationItems = computed(() =>
  makePagination(props.page, props.last_page).map((item) => ({
    ...item,
    disabled: 0 === item.page,
  })),
);

watch(
  () => props.page,
  (value) => {
    currentPage.value = value;
  },
);

const changePage = (page: number | string): void => {
  const nextPage = Number(page);
  if (Number.isNaN(nextPage) || 1 > nextPage || props.last_page < nextPage) {
    currentPage.value = props.page;
    return;
  }

  emit('navigate', nextPage);
  currentPage.value = nextPage;
};
</script>
