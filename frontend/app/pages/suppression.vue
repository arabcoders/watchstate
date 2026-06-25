<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell">
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-plus"
          @click="toggleForm = true"
          label="Add"
        />

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading"
          @click="loadContent"
          label="Reload"
        />
      </template>
    </PageHeader>

    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading"
      description="Loading data. Please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="0 === items.length"
      color="warning"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="No suppression rules"
    >
      <template #description>
        <div class="space-y-2 text-sm text-default">
          <p>
            No suppression rules were found.
            <UButton
              color="primary"
              variant="link"
              size="sm"
              icon="i-lucide-plus"
              class="px-0"
              @click="toggleForm = true"
            >
              Add a new rule
            </UButton>
          </p>
        </div>
      </template>
    </UAlert>

    <div v-else class="grid gap-4 xl:grid-cols-2">
      <UCard v-for="item in items" :key="String(item.id)" class="h-full shadow-sm" :ui="ruleCardUi">
        <template #header>
          <div class="flex items-center justify-between gap-3">
            <div class="inline-flex items-center gap-2 text-base font-semibold text-highlighted">
              <UIcon name="i-lucide-cpu" class="size-4 text-toned" />
              <span class="capitalize">{{ item.type }}</span>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2">
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-pencil"
                @click="editItem(item)"
                label="Edit"
              />

              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-trash-2"
                @click="deleteItem(item)"
                label="Delete"
              />
            </div>
          </div>
        </template>

        <div class="space-y-3">
          <div class="rounded-md border border-default bg-elevated/20 px-3 py-3">
            <div class="mb-1 inline-flex items-center gap-2 text-xs font-medium text-toned">
              <UIcon
                :name="'regex' === item.type ? 'i-lucide-code' : 'i-lucide-heading'"
                class="size-4"
              />
              <span>Rule</span>
            </div>

            <div
              class="cursor-pointer text-sm text-default"
              :class="expandableInlineClass(item.expandRule, true)"
              @click="item.expandRule = !item.expandRule"
            >
              <code>{{ item.rule }}</code>
            </div>
          </div>

          <div class="rounded-md border border-default bg-elevated/20 px-3 py-3">
            <div class="mb-1 inline-flex items-center gap-2 text-xs font-medium text-toned">
              <UIcon name="i-lucide-file-text" class="size-4" />
              <span>Example</span>
            </div>

            <div
              class="cursor-pointer text-sm text-default"
              :class="expandableInlineClass(item.expandExample, true)"
              @click="item.expandExample = !item.expandExample"
            >
              <code>{{ item.example }}</code>
            </div>
          </div>
        </div>
      </UCard>
    </div>

    <UModal
      :open="toggleForm"
      :title="formModalTitle"
      :ui="formModalUi"
      @update:open="handleFormOpenChange"
    >
      <template #body>
        <form id="suppression_form" class="space-y-5" @submit.prevent="sendData">
          <UFormField label="Matching type" name="form_type">
            <USelect
              id="form_type"
              v-model="formData.type"
              :items="typeItems"
              value-key="value"
              placeholder="Select Type"
              icon="i-lucide-cpu"
              class="w-full"
              :disabled="null !== formData.id"
            />
          </UFormField>

          <UFormField label="Rule" name="form_rule">
            <UInput
              id="form_rule"
              v-model="formData.rule"
              :placeholder="'regex' === formData.type ? '/this match \\d+/is' : 'hide_me'"
              :icon="'regex' === formData.type ? 'i-lucide-code' : 'i-lucide-heading'"
              class="w-full"
            />

            <p class="text-sm leading-6 text-toned">
              <template v-if="'regex' === formData.type">
                Regular expression. To test try
                <NuxtLink
                  to="https://regex101.com/"
                  target="_blank"
                  class="text-primary hover:underline"
                >
                  this link
                </NuxtLink>
                . Select <code>PCRE2 (PHP &gt;=7.3)</code> flavor.
              </template>
              <template v-else>Case sensitive string contains match.</template>
            </p>
          </UFormField>

          <UFormField label="Example" name="form_example">
            <UInput
              id="form_example"
              v-model="formData.example"
              placeholder="String example to test the rule against."
              icon="i-lucide-type"
              class="w-full"
            />

            <p class="text-sm leading-6 text-toned">
              The example text must trigger the supplied rule. This is used to test the rule if it
              is working as expected.
            </p>
          </UFormField>
        </form>
      </template>

      <template #footer>
        <div class="flex w-full gap-2 flex-row justify-end">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-x"
            class="justify-center"
            type="button"
            @click="cancelForm"
          >
            Cancel
          </UButton>

          <UButton
            color="primary"
            variant="solid"
            size="sm"
            icon="i-lucide-save"
            class="justify-center"
            type="submit"
            form="suppression_form"
            :disabled="!formData.rule || !formData.example"
          >
            Save
          </UButton>
        </div>
      </template>
    </UModal>

    <UCard class="shadow-sm" :ui="tipsCardUi">
      <template #header>
        <button
          type="button"
          class="flex w-full items-center justify-between gap-3 text-left"
          @click="show_page_tips = !show_page_tips"
        >
          <span class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-info" class="size-4 text-toned" />
            <span>Tips</span>
          </span>

          <span class="inline-flex items-center gap-1 text-xs font-medium text-toned">
            <UIcon
              :name="show_page_tips ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
              class="size-4"
            />
            <span>{{ show_page_tips ? 'Hide' : 'Show' }}</span>
          </span>
        </button>
      </template>

      <template #default v-if="show_page_tips">
        <ul class="list-disc space-y-2 pl-5 text-sm leading-6 text-default">
          <li>
            The log suppressor works on almost everything that <strong>WatchState</strong> outputs.
            However, there are some exceptions.
          </li>
          <li>
            The use case for this feature is that sometimes it is out of your hands to fix a
            problem, and the constant logging of the same error can be annoying. This feature allows
            you to suppress the error from being shown or recorded.
          </li>
          <li>
            It is less compute intensive to use <strong>contains</strong> type than
            <strong>regex</strong> type, as each rule will be tested against every single output
            message. The fewer rules you have, the better. Having many rules will lead to
            performance degradation.
          </li>
        </ul>
      </template>
    </UCard>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useHead, useRoute } from '#app';
import { useStorage } from '@vueuse/core';
import PageHeader from '~/components/PageHeader.vue';
import { useDirtyCloseGuard } from '~/composables/useDirtyCloseGuard';
import { useDialog } from '~/composables/useDialog';
import { useDirtyState } from '~/composables/useDirtyState';
import type { GenericResponse, SuppressionItem } from '~/types';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { notification, parse_api_response, request } from '~/utils';

type SuppressorResponse = {
  items: Array<SuppressionItem>;
  types: Array<'contains' | 'regex'>;
};

type SelectItem = {
  label: string;
  value: string;
};

type SuppressionCardItem = SuppressionItem & {
  expandRule?: boolean;
  expandExample?: boolean;
};

useHead({ title: 'Log Suppressor' });

const pageShell = requireTopLevelPageShell('suppression');

const route = useRoute();
const dialog = useDialog();

const defaultData = (): SuppressionItem => ({ id: null, rule: '', example: '', type: 'contains' });

const makeCardItem = (item: SuppressionItem): SuppressionCardItem => ({
  ...item,
  expandRule: false,
  expandExample: false,
});

const isLoading = ref<boolean>(false);
const items = ref<Array<SuppressionCardItem>>([]);
const toggleForm = ref<boolean>(false);
const formData = ref<SuppressionItem>(defaultData());
const show_page_tips = useStorage<boolean>('show_page_tips', true);
const types = ref<Array<'contains' | 'regex'>>(['contains', 'regex']);

const ruleCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
  footer: 'px-4 pb-4 pt-0',
};

const formModalUi = {
  content: 'max-w-2xl',
  body: 'space-y-5 p-5',
  footer: 'border-t border-default p-5',
};

const tipsCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const typeItems = computed<Array<SelectItem>>(() =>
  types.value.map((type) => ({
    label: type,
    value: type,
  })),
);

const formModalTitle = computed(() =>
  formData.value.id ? 'Edit suppression rule' : 'Add new suppression rule',
);

const dirtySource = computed(() => ({
  id: formData.value.id,
  type: formData.value.type,
  rule: formData.value.rule,
  example: formData.value.example,
}));
const { isDirty: isFormDirty, markClean: markFormClean } = useDirtyState(dirtySource);

const expandableInlineClass = (expanded?: boolean, allowBreakAll = false): string => {
  if (true === expanded) {
    return allowBreakAll ? 'break-all' : 'break-words';
  }

  return allowBreakAll ? 'ws-expandable-inline-breakall' : 'ws-expandable-inline';
};

const resetFormData = (): void => {
  formData.value = defaultData();
  markFormClean();
};

const { handleOpenChange: handleFormOpenChange, requestClose: requestCloseForm } =
  useDirtyCloseGuard(toggleForm, {
    dirty: isFormDirty,
    onDiscard: async () => {
      resetFormData();
    },
  });

const loadContent = async (): Promise<void> => {
  isLoading.value = true;
  items.value = [];

  try {
    const response = await request('/system/suppressor');
    const json = await parse_api_response<SuppressorResponse>(response);

    if ('suppression' !== route.name) {
      return;
    }

    if ('error' in json) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`);
      return;
    }

    items.value = json.items.map((item) => makeCardItem(item));
    types.value = json.types;
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', message);
  } finally {
    isLoading.value = false;
  }
};

onMounted(() => void loadContent());

const deleteItem = async (item: SuppressionItem): Promise<void> => {
  const { status: confirmStatus } = await dialog.confirmDialog({
    title: 'Confirm Deletion',
    message: `Delete rule id '${item.id}'?`,
    confirmColor: 'error',
  });

  if (true !== confirmStatus) {
    return;
  }

  try {
    const response = await request(`/system/suppressor/${item.id}`, { method: 'DELETE' });

    if (response.ok) {
      items.value = items.value.filter((currentItem) => currentItem.id !== item.id);
      notification(
        'success',
        'Success',
        `Suppression rule id '${item.id}' successfully deleted.`,
        5000,
      );
      return;
    }

    const json = await parse_api_response<GenericResponse>(response);
    if ('error' in json) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`, 5000);
      return;
    }

    notification('error', 'Error', 'Failed to delete the suppression rule.', 5000);
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', `Request error. ${message}`, 5000);
  }
};

const sendData = async (): Promise<void> => {
  const requiredFields: Array<keyof SuppressionItem> = ['rule', 'example', 'type'];
  for (const field of requiredFields) {
    if (!formData.value[field]) {
      notification('error', 'Error', `${field} field is required.`, 5000);
      return;
    }
  }

  try {
    const response = await request(
      `/system/suppressor${formData.value.id ? `/${formData.value.id}` : ''}`,
      {
        method: formData.value.id ? 'PUT' : 'POST',
        body: JSON.stringify({
          rule: formData.value.rule,
          example: formData.value.example,
          type: formData.value.type,
        }),
      },
    );

    if (304 === response.status) {
      cancelForm();
      return;
    }

    const json = await parse_api_response<SuppressionItem>(response);
    if ('error' in json) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`, 5000);
      return;
    }

    if (!formData.value.id) {
      items.value.push(makeCardItem(json));
    } else {
      const index = items.value.findIndex((item) => item.id === formData.value.id);
      if (-1 !== index) {
        items.value[index] = makeCardItem(json);
      }
    }

    const action = formData.value.id ? 'updated' : 'added';
    notification('success', 'Success', `Suppression rule successfully ${action}.`, 5000);
    resetFormData();
    toggleForm.value = false;
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', `Request error. ${message}`, 5000);
  }
};

const editItem = (item: SuppressionItem): void => {
  formData.value = {
    id: item.id,
    rule: item.rule,
    example: item.example,
    type: item.type,
  };
  toggleForm.value = true;
  markFormClean();
};

const cancelForm = async (): Promise<void> => {
  await requestCloseForm();
};

watch(toggleForm, (value: boolean) => {
  if (!value) {
    resetFormData();
  }
});

markFormClean();
</script>
