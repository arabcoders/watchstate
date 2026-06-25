<template>
  <form id="custom_link_form" class="space-y-5" @submit.prevent="addNewLink">
    <div class="space-y-2">
      <label for="form_select_type" class="text-sm font-medium text-highlighted">Client</label>
      <USelect
        id="form_select_type"
        v-model="form.type"
        :items="supportedItems"
        value-key="value"
        placeholder="Select client type"
        icon="i-lucide-server"
        class="w-full"
      />
      <p class="text-sm leading-6 text-toned">Select which client this link association for.</p>
    </div>

    <div class="space-y-2">
      <label for="form_map_from" class="text-sm font-medium text-highlighted"
        >Link client GUID</label
      >
      <UInput id="form_map_from" v-model="form.map.from" icon="i-lucide-link" class="w-full" />
      <p class="text-sm leading-6 text-toned">
        Write the {{ form.type.length > 0 ? ucFirst(form.type) : 'client' }} GUID identifier.
      </p>
    </div>

    <div class="space-y-2">
      <label for="form_map_to" class="text-sm font-medium text-highlighted">To This GUID</label>
      <USelect
        id="form_map_to"
        v-model="form.map.to"
        :items="guidItems"
        value-key="value"
        placeholder="Select the associated GUID"
        icon="i-lucide-link-2"
        class="w-full"
      />
      <p class="text-sm leading-6 text-toned">
        Select which WatchState GUID should link with this
        {{ form.type.length > 0 ? ucFirst(form.type) : 'client' }} GUID identifier.
      </p>
    </div>

    <div
      v-if="'plex' === form.type"
      class="rounded-md border border-default bg-elevated/30 px-3 py-3"
    >
      <div class="flex items-center justify-between gap-3">
        <div class="min-w-0">
          <div class="text-sm font-medium text-highlighted">Plex legacy agent GUID</div>
          <p class="mt-1 text-sm text-toned">Plex legacy agents starts with com.plexapp.agents.</p>
        </div>

        <USwitch id="backend_import" v-model="form.options.legacy" color="neutral" />
      </div>
    </div>

    <template v-if="'plex' === form.type && true === form.options.legacy">
      <UCard class="shadow-sm" :ui="nestedCardUi">
        <template #header>
          <button
            type="button"
            class="flex w-full items-center justify-between gap-3 text-left"
            @click="toggleReplace = !toggleReplace"
          >
            <span class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted">
              <UIcon
                :name="toggleReplace ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
                class="size-4 text-toned"
              />
              <span>Toggle Text replacement</span>
            </span>
          </button>
        </template>

        <div class="space-y-4">
          <p class="text-sm text-toned">Text replacement only works for plex legacy agents.</p>

          <template v-if="toggleReplace">
            <div class="space-y-2">
              <label for="form_replace_from" class="text-sm font-medium text-highlighted"
                >Search for</label
              >
              <UInput
                id="form_replace_from"
                v-model="form.replace.from"
                icon="i-lucide-id-card"
                class="w-full"
              />
              <p class="text-sm leading-6 text-toned">
                The text string to replace. Sometimes it's necessary to replace legacy agent GUID
                into something else. Leave it empty to ignore it.
              </p>
            </div>

            <div class="space-y-2">
              <label for="form_replace_to" class="text-sm font-medium text-highlighted"
                >Replace with</label
              >
              <UInput
                id="form_replace_to"
                v-model="form.replace.to"
                icon="i-lucide-id-card"
                class="w-full"
              />
              <p class="text-sm leading-6 text-toned">
                The string replacement. If <code>replace.from</code> is empty this field will be
                ignored.
              </p>
            </div>
          </template>
        </div>
      </UCard>
    </template>

    <div class="flex w-full gap-2 flex-row justify-end">
      <UButton
        type="button"
        color="neutral"
        variant="outline"
        size="sm"
        icon="i-lucide-x"
        class="justify-center"
        @click="emit('cancel')"
      >
        Cancel
      </UButton>

      <UButton
        type="submit"
        color="primary"
        variant="solid"
        size="sm"
        icon="i-lucide-save"
        class="justify-center"
        :loading="isSaving"
        :disabled="false === validForm || isSaving"
      >
        Save
      </UButton>
    </div>
  </form>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import { useDirtyState } from '~/composables/useDirtyState';
import { request, notification, parse_api_response, ucFirst } from '~/utils';
import type { CustomGUID, CustomLink, GenericError, GenericResponse, GuidProvider } from '~/types';

type CustomLinkFormData = {
  type: string;
  options: {
    legacy: boolean;
  };
  map: {
    from: string;
    to: string;
  };
  replace: {
    from: string;
    to: string;
  };
};

type SelectItem = {
  label: string;
  value: string;
};

const emit = defineEmits<{
  (e: 'cancel' | 'saved'): void;
  (e: 'dirty-change', dirty: boolean): void;
}>();

const defaultData = (): CustomLinkFormData => ({
  type: '',
  options: { legacy: true },
  map: { from: '', to: '' },
  replace: { from: '', to: '' },
});

const form = ref<CustomLinkFormData>(defaultData());
const guids = ref<Array<GuidProvider>>([]);
const supported = ref<Array<string>>([]);
const isSaving = ref<boolean>(false);
const links = ref<Array<CustomLink>>([]);
const toggleReplace = ref<boolean>(false);
const dirtySource = computed(() => ({
  ...form.value,
  toggleReplace: toggleReplace.value,
}));
const { isDirty, markClean } = useDirtyState(dirtySource);

const nestedCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const supportedItems = computed<Array<SelectItem>>(() =>
  supported.value.map((client) => ({ label: ucFirst(client), value: client })),
);

const guidItems = computed<Array<SelectItem>>(() =>
  guids.value.map((guid) => ({ label: guid.guid, value: guid.guid })),
);

onMounted(async () => {
  try {
    const responses = await Promise.all([
      request('/system/guids'),
      request('/system/supported'),
      request('/system/guids/custom'),
    ]);

    const guidData = await parse_api_response<Array<GuidProvider>>(responses[0]);
    const supportedData = await parse_api_response<Array<string>>(responses[1]);
    const customData = await parse_api_response<{
      guids: Record<string, CustomGUID>;
      links: Record<string, CustomLink>;
    }>(responses[2]);

    if ('error' in guidData) {
      notification('error', 'Error', guidData.error.message);
      return;
    }
    if ('error' in supportedData) {
      notification('error', 'Error', supportedData.error.message);
      return;
    }
    if ('error' in customData) {
      notification('error', 'Error', customData.error.message);
      return;
    }

    guids.value = guidData;
    supported.value = supportedData;
    links.value = Object.values(customData.links);
  } catch (e: unknown) {
    const message = e instanceof Error ? e.message : 'Unexpected error';
    notification('error', 'Error', `Request error. ${message}`, 5000);
  }

  markClean();
});

const addNewLink = async (): Promise<void> => {
  if (!validForm.value) {
    notification('error', 'Error', 'Invalid form data.', 5000);
    return;
  }

  const data = form.value;

  if (!supported.value.includes(data.type)) {
    notification('error', 'Error', 'Invalid client type.', 5000);
    return;
  }

  if (!data.map.from) {
    notification('error', 'Error', 'map.from must not be empty.', 5000);
    return;
  }

  if (!guids.value.find((g) => g.guid === data.map.to)) {
    notification('error', 'Error', `Invalid map.to value '${data.map.to}'.`, 5000);
    return;
  }

  for (let i = 0; i < links.value.length; i++) {
    const link = links.value[i];
    if (link && link.type === data.type && link.map.from === data.map.from) {
      notification('error', 'Error', `Link with map.from '${data.map.from}' already exists.`, 5000);
      return;
    }
  }

  const formData: Partial<CustomLinkFormData> = {
    type: data.type,
    map: {
      from: data.map.from,
      to: data.map.to,
    },
  };

  if ('plex' === data.type) {
    formData.options = {
      legacy: Boolean(data.options.legacy),
    };

    if (data.replace.from && data.replace.to) {
      formData.replace = {
        from: data.replace.from,
        to: data.replace.to,
      };
    }
  }

  isSaving.value = true;

  try {
    const response = await request(`/system/guids/custom/${formData.type}`, {
      method: 'PUT',
      body: JSON.stringify(formData),
    });

    const json = await parse_api_response<GenericResponse>(response);

    if ('error' in json) {
      const errorJson = json as GenericError;
      notification('error', 'Error', `${errorJson.error.code}: ${errorJson.error.message}`, 5000);
      return;
    }

    notification('success', 'Success', 'Successfully added new client link.', 5000);
    form.value = defaultData();
    toggleReplace.value = false;
    markClean();
    emit('dirty-change', false);
    emit('saved');
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', `Request error. ${message}`, 5000);
  } finally {
    isSaving.value = false;
  }
};

const validForm = computed(
  (): boolean => !(!form.value.map.to || !form.value.map.from || !form.value.type),
);

watch(isDirty, (value: boolean) => emit('dirty-change', value));
</script>
