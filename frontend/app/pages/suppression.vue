<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell" description="Stop recording matching log messages.">
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isBusy || toggleForm"
          @click="loadContent"
          label="Reload"
        />
        <UButton
          color="primary"
          variant="solid"
          size="sm"
          icon="i-lucide-plus"
          :disabled="isBusy || toggleForm"
          @click="addItem"
          label="Add rule"
        />
      </template>
    </PageHeader>

    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading suppression rules"
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="loadError"
      color="error"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Unable to load suppression rules"
      :description="loadError"
    />

    <UEmpty
      v-else-if="0 === items.length"
      icon="i-lucide-list-filter"
      title="No suppression rules"
    />

    <section v-else aria-label="Suppression rules" class="space-y-4">
      <div class="flex flex-wrap items-end justify-between gap-3">
        <UFormField label="Filter rules" name="suppression_filter" class="w-full sm:max-w-sm">
          <UInput
            id="suppression_filter"
            v-model="search"
            icon="i-lucide-search"
            placeholder="Rule, matching type or example"
            class="w-full"
          />
        </UFormField>
        <p class="text-sm text-toned" aria-live="polite">
          {{ filteredItems.length }}{{ search.trim() ? ` of ${items.length}` : '' }}
          {{ 1 === items.length ? 'rule' : 'rules' }}
        </p>
      </div>

      <UEmpty v-if="0 === filteredItems.length" icon="i-lucide-search" title="No matching rules" />

      <div v-else class="ws-list-card">
        <article
          v-for="item in filteredItems"
          :key="String(item.id)"
          :aria-labelledby="`rule-${item.id}`"
          class="grid min-w-0 gap-4 p-4 sm:p-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]"
        >
          <div class="min-w-0 space-y-2">
            <h2
              :id="`rule-${item.id}`"
              class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted"
            >
              <UIcon
                :name="'regex' === item.type ? 'i-lucide-code' : 'i-lucide-heading'"
                class="size-4 text-toned"
              />
              {{ 'regex' === item.type ? 'Regex' : 'Contains' }}
            </h2>
            <p class="wrap-anywhere whitespace-pre-wrap font-mono text-sm text-highlighted">
              {{ item.rule }}
            </p>
          </div>

          <dl class="min-w-0 space-y-2">
            <dt class="text-xs text-muted">Example</dt>
            <dd class="wrap-anywhere whitespace-pre-wrap font-mono text-sm text-toned">
              {{ item.example }}
            </dd>
          </dl>

          <div class="flex flex-wrap items-start gap-2 xl:justify-end">
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-pencil"
              :disabled="isBusy || toggleForm"
              :aria-label="`Edit ${item.type} rule: ${item.rule}`"
              @click="editItem(item)"
              label="Edit"
            />
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-trash-2"
              :disabled="isBusy || toggleForm"
              :aria-label="`Delete ${item.type} rule: ${item.rule}`"
              @click="deleteItem(item)"
              label="Delete"
            />
          </div>
        </article>
      </div>
    </section>

    <UModal
      :open="toggleForm"
      :title="formModalTitle"
      :ui="formModalUi"
      :dismissible="!isBusy"
      :close="!isBusy"
      @update:open="handleFormOpenChange"
    >
      <template #body>
        <form id="suppression_form" class="space-y-5" @submit.prevent="sendData">
          <UFormField label="Matching type" name="form_type" required>
            <USelect
              id="form_type"
              v-model="formData.type"
              :items="typeItems"
              value-key="value"
              icon="i-lucide-cpu"
              class="w-full"
              :disabled="isBusy || null !== formData.id"
            />
          </UFormField>

          <UFormField label="Rule" name="form_rule" required>
            <UInput
              id="form_rule"
              v-model="formData.rule"
              :placeholder="'regex' === formData.type ? '/this match \\d+/is' : 'hide_me'"
              :icon="'regex' === formData.type ? 'i-lucide-code' : 'i-lucide-heading'"
              class="w-full"
              :disabled="isBusy"
              required
            />

            <template #help>
              <template v-if="'regex' === formData.type">
                Use a delimited
                <NuxtLink
                  to="https://regex101.com/"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="text-primary hover:underline"
                >
                  PCRE2 pattern
                </NuxtLink>
                with optional flags.
              </template>
              <template v-else
                >Matches messages containing this exact, case-sensitive text.</template
              >
            </template>
          </UFormField>

          <UFormField
            label="Example"
            name="form_example"
            help="Must match this rule and must not match another saved rule."
            required
          >
            <UInput
              id="form_example"
              v-model="formData.example"
              placeholder="A log message that matches this rule"
              icon="i-lucide-type"
              class="w-full"
              :disabled="isBusy"
              required
            />
          </UFormField>
        </form>
      </template>

      <template #footer>
        <div class="flex w-full justify-end">
          <UButton
            color="primary"
            variant="solid"
            size="sm"
            icon="i-lucide-save"
            type="submit"
            form="suppression_form"
            :loading="'save' === pendingAction"
            :disabled="isBusy || !formData.rule || !formData.example"
          >
            Save
          </UButton>
        </div>
      </template>
    </UModal>
  </main>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useHead } from '#app';
import {
  NuxtLink,
  UAlert,
  UButton,
  UEmpty,
  UFormField,
  UIcon,
  UInput,
  UModal,
  USelect,
} from '#components';
import PageHeader from '~/components/PageHeader.vue';
import { useDirtyCloseGuard } from '~/composables/useDirtyCloseGuard';
import { useDialog } from '~/composables/useDialog';
import { useDirtyState } from '~/composables/useDirtyState';
import type { GenericResponse, SuppressionItem, SuppressionResponse } from '~/types';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { notification, parse_api_response, request } from '~/utils';

useHead({ title: 'Log Suppressor' });

const pageShell = requireTopLevelPageShell('suppression');
const dialog = useDialog();
const defaultData = (): SuppressionItem => ({ id: null, rule: '', example: '', type: 'contains' });

const isLoading = ref(false);
const loadError = ref('');
const items = ref<Array<SuppressionItem>>([]);
const search = ref('');
const toggleForm = ref(false);
const formData = ref<SuppressionItem>(defaultData());
const types = ref<Array<SuppressionItem['type']>>(['contains', 'regex']);
const pendingAction = ref<'save' | 'delete' | 'close' | null>(null);
const isBusy = computed(() => isLoading.value || null !== pendingAction.value);
let disposed = false;

const formModalUi = {
  content: 'max-w-2xl',
  body: 'space-y-5 p-5',
  footer: 'border-t border-default p-5',
};

const filteredItems = computed(() => {
  const query = search.value.trim().toLocaleLowerCase();
  if (!query) {
    return items.value;
  }

  return items.value.filter((item) =>
    [item.type, item.rule, item.example].some((value) => value.toLocaleLowerCase().includes(query)),
  );
});

const typeItems = computed(() =>
  types.value.map((type) => ({
    label: 'regex' === type ? 'Regex' : 'Contains',
    value: type,
  })),
);

const formModalTitle = computed(() =>
  null !== formData.value.id ? 'Edit suppression rule' : 'Add suppression rule',
);

const dirtySource = computed(() => ({
  id: formData.value.id,
  type: formData.value.type,
  rule: formData.value.rule,
  example: formData.value.example,
}));
const { isDirty: isFormDirty, markClean: markFormClean } = useDirtyState(dirtySource);

const resetFormData = (): void => {
  formData.value = defaultData();
  markFormClean();
};

const { handleOpenChange: guardFormOpenChange, requestClose: requestCloseForm } =
  useDirtyCloseGuard(toggleForm, {
    dirty: isFormDirty,
    onDiscard: resetFormData,
  });

const handleFormOpenChange = async (open: boolean): Promise<void> => {
  if (disposed || isBusy.value) {
    return;
  }

  pendingAction.value = 'close';
  try {
    await guardFormOpenChange(open);
  } finally {
    pendingAction.value = null;
  }
};

const loadContent = async (): Promise<void> => {
  if (disposed || isBusy.value || toggleForm.value) {
    return;
  }

  isLoading.value = true;
  loadError.value = '';

  try {
    const response = await request('/system/suppressor');
    const json = await parse_api_response<SuppressionResponse>(response);
    if (disposed) {
      return;
    }

    if ('error' in json) {
      loadError.value = `${json.error.code}: ${json.error.message}`;
      notification('error', 'Unable to load suppression rules', loadError.value);
      return;
    }

    items.value = json.items;
    types.value = json.types;
  } catch (error) {
    if (disposed) {
      return;
    }

    const message = error instanceof Error ? error.message : 'Unexpected error';
    loadError.value = message;
    notification('error', 'Unable to load suppression rules', message);
  } finally {
    isLoading.value = false;
  }
};

const deleteItem = async (item: SuppressionItem): Promise<void> => {
  if (disposed || isBusy.value || toggleForm.value || null === item.id) {
    return;
  }

  const target = { ...item };
  pendingAction.value = 'delete';

  try {
    const { status: confirmStatus } = await dialog.confirmDialog({
      title: 'Delete suppression rule?',
      message: `Delete ${target.type} rule '${target.rule}'?`,
      confirmText: 'Delete',
      confirmColor: 'error',
    });
    if (disposed || true !== confirmStatus) {
      return;
    }

    const response = await request(`/system/suppressor/${target.id}`, { method: 'DELETE' });
    if (disposed) {
      return;
    }

    if (response.ok) {
      items.value = items.value.filter((currentItem) => currentItem.id !== target.id);
      notification('success', 'Rule deleted', `'${target.rule}' was removed.`, 5000);
      return;
    }

    const json = await parse_api_response<GenericResponse>(response);
    if (disposed) {
      return;
    }

    if ('error' in json) {
      notification('error', 'Rule not deleted', `${json.error.code}: ${json.error.message}`, 5000);
      return;
    }

    notification('error', 'Rule not deleted', 'Failed to delete the suppression rule.', 5000);
  } catch (error) {
    if (disposed) {
      return;
    }

    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Rule not deleted', message, 5000);
  } finally {
    pendingAction.value = null;
  }
};

const sendData = async (): Promise<void> => {
  if (disposed || isBusy.value || !toggleForm.value) {
    return;
  }

  const submitted = { ...formData.value };
  const requiredFields: Array<keyof SuppressionItem> = ['rule', 'example', 'type'];
  for (const field of requiredFields) {
    if (!submitted[field]) {
      notification('error', 'Rule not saved', `${field} field is required.`, 5000);
      return;
    }
  }

  pendingAction.value = 'save';

  try {
    const response = await request(
      `/system/suppressor${null !== submitted.id ? `/${submitted.id}` : ''}`,
      {
        method: null !== submitted.id ? 'PUT' : 'POST',
        body: JSON.stringify({
          rule: submitted.rule,
          example: submitted.example,
          type: submitted.type,
        }),
      },
    );
    if (disposed) {
      return;
    }

    if (304 === response.status) {
      await requestCloseForm();
      return;
    }

    const json = await parse_api_response<SuppressionItem>(response);
    if (disposed) {
      return;
    }

    if ('error' in json) {
      notification('error', 'Rule not saved', `${json.error.code}: ${json.error.message}`, 5000);
      return;
    }

    if (null === submitted.id) {
      items.value.push(json);
    } else {
      items.value = items.value.map((item) => (submitted.id === item.id ? json : item));
    }

    const action = null !== submitted.id ? 'updated' : 'added';
    notification('success', 'Rule saved', `'${submitted.rule}' was ${action}.`, 5000);
    resetFormData();
    toggleForm.value = false;
  } catch (error) {
    if (disposed) {
      return;
    }

    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Rule not saved', message, 5000);
  } finally {
    pendingAction.value = null;
  }
};

const addItem = (): void => {
  if (disposed || isBusy.value || toggleForm.value) {
    return;
  }

  resetFormData();
  toggleForm.value = true;
};

const editItem = (item: SuppressionItem): void => {
  if (disposed || isBusy.value || toggleForm.value) {
    return;
  }

  formData.value = { ...item };
  toggleForm.value = true;
  markFormClean();
};

watch(toggleForm, (value: boolean) => {
  if (!value) {
    resetFormData();
  }
});

onMounted(() => void loadContent());
onBeforeUnmount(() => {
  disposed = true;
});

markFormClean();
</script>
