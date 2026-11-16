<template>
  <form :id="formId" class="space-y-5" @submit.prevent="addNewLink">
    <fieldset :disabled="isSaving" class="min-w-0 space-y-5">
      <UFormField
        label="Client"
        name="type"
        description="Applies to every backend of this client type."
        required
      >
        <USelect
          v-model="form.type"
          :items="supportedItems"
          value-key="value"
          placeholder="Choose a client"
          icon="i-lucide-server"
          class="w-full"
        />
      </UFormField>

      <UFormField
        label="Client GUID"
        name="map.from"
        description="The provider identifier used by the client, such as a Jellyfin provider name or Plex agent ID."
        required
      >
        <UInput v-model="form.map.from" icon="i-lucide-link" class="w-full" />
      </UFormField>

      <UFormField label="WatchState GUID" name="map.to" required>
        <USelect
          v-model="form.map.to"
          :items="guidItems"
          value-key="value"
          placeholder="Choose a built-in or custom GUID"
          icon="i-lucide-link-2"
          class="w-full"
        />
      </UFormField>

      <template v-if="'plex' === form.type">
        <USwitch
          v-model="form.options.legacy"
          color="neutral"
          label="Plex legacy agent"
          description="Legacy agent identifiers start with com.plexapp.agents."
        />

        <details
          v-if="true === form.options.legacy"
          :open="toggleReplace"
          class="space-y-4 border-t border-default pt-5"
          @toggle="toggleReplace = ($event.target as HTMLDetailsElement).open"
        >
          <summary class="w-fit cursor-pointer text-sm font-semibold text-highlighted">
            Text replacement
          </summary>
          <p class="text-sm text-toned">Replacement requires both values.</p>
          <UFormField label="Search for" name="replace.from">
            <UInput v-model="form.replace.from" icon="i-lucide-search" class="w-full" />
          </UFormField>
          <UFormField label="Replace with" name="replace.to">
            <UInput v-model="form.replace.to" icon="i-lucide-replace" class="w-full" />
          </UFormField>
        </details>
      </template>
    </fieldset>

    <Teleport defer :to="props.actionsTarget">
      <div class="flex w-full justify-end gap-2">
        <UButton
          type="submit"
          :form="formId"
          color="primary"
          variant="solid"
          size="sm"
          icon="i-lucide-save"
          :loading="isSaving"
          :disabled="false === validForm || isSaving"
          label="Save"
        />
      </div>
    </Teleport>
  </form>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch, useId } from 'vue';
import { UButton, UFormField, UInput, USelect, USwitch } from '#components';
import { useDirtyState } from '~/composables/useDirtyState';
import { request, notification, parse_api_response, ucFirst } from '~/utils';
import type { CustomGUID, CustomLink, GenericResponse, GuidProvider } from '~/types';

const props = defineProps<{ actionsTarget: string }>();
const formId = useId();

const emit = defineEmits<{
  (e: 'saved'): void;
  (e: 'dirty-change' | 'saving-change', value: boolean): void;
}>();

const defaultData = () => ({
  type: '',
  options: { legacy: true },
  map: { from: '', to: '' },
  replace: { from: '', to: '' },
});

const form = ref<
  CustomLink & {
    options: { legacy: boolean };
    replace: {
      from: string;
      to: string;
    };
  }
>(defaultData());
const guids = ref<Array<GuidProvider>>([]);
const supported = ref<Array<string>>([]);
const isSaving = ref(false);
const links = ref<Array<CustomLink>>([]);
const toggleReplace = ref(false);
const dirtySource = computed(() => ({
  ...form.value,
  toggleReplace: toggleReplace.value,
}));
const { isDirty, markClean } = useDirtyState(dirtySource);
markClean();

const supportedItems = computed(() =>
  supported.value.map((client) => ({ label: ucFirst(client), value: client })),
);
const guidItems = computed(() =>
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
      notification('error', 'Unable to load GUIDs', guidData.error.message);
      return;
    }
    if ('error' in supportedData) {
      notification('error', 'Unable to load client types', supportedData.error.message);
      return;
    }
    if ('error' in customData) {
      notification('error', 'Unable to load client links', customData.error.message);
      return;
    }

    guids.value = guidData;
    supported.value = supportedData;
    links.value = Object.values(customData.links);
  } catch (e: unknown) {
    const message = e instanceof Error ? e.message : 'Unexpected error';
    notification('error', 'Unable to load link options', message, 5000);
  }
});

const addNewLink = async (): Promise<void> => {
  if (isSaving.value) {
    return;
  }

  if (!validForm.value) {
    notification('error', 'Link not added', 'Invalid form data.', 5000);
    return;
  }

  const data = form.value;

  if (!supported.value.includes(data.type)) {
    notification('error', 'Link not added', 'Invalid client type.', 5000);
    return;
  }

  if (!data.map.from) {
    notification('error', 'Link not added', 'Client GUID must not be empty.', 5000);
    return;
  }

  if (!guids.value.find((g) => g.guid === data.map.to)) {
    notification('error', 'Link not added', `Invalid WatchState GUID '${data.map.to}'.`, 5000);
    return;
  }

  for (let i = 0; i < links.value.length; i++) {
    const link = links.value[i];
    if (link && link.type === data.type && link.map.from === data.map.from) {
      notification(
        'error',
        'Link not added',
        `A ${ucFirst(data.type)} link for '${data.map.from}' already exists.`,
        5000,
      );
      return;
    }
  }

  const formData: Partial<CustomLink> = {
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
      notification('error', 'Link not added', `${json.error.code}: ${json.error.message}`, 5000);
      return;
    }

    notification('success', 'Link added', `'${data.map.from}' maps to '${data.map.to}'.`, 5000);
    form.value = defaultData();
    toggleReplace.value = false;
    markClean();
    isSaving.value = false;
    emit('dirty-change', false);
    emit('saved');
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Link not added', message, 5000);
  } finally {
    isSaving.value = false;
  }
};

const validForm = computed(
  (): boolean => !(!form.value.map.to || !form.value.map.from || !form.value.type),
);

watch(isDirty, (value: boolean) => emit('dirty-change', value));
watch(isSaving, (value: boolean) => emit('saving-change', value), { flush: 'sync' });
</script>
