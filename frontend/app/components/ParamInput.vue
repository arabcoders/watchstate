<template>
  <USelect
    v-if="options.length > 0"
    :model-value="modelValue"
    :items="options"
    :placeholder="placeholder"
    :disabled="disabled"
    class="w-full"
    @update:model-value="(value: string) => emit('update:modelValue', value ?? '')"
  >
    <template #leading>
      <span v-if="placeholder && !modelValue" class="text-toned">{{ placeholder }}</span>
    </template>
  </USelect>
  <UInput
    v-else-if="'integer' === inputType || 'number' === inputType"
    :model-value="modelValue"
    type="number"
    :placeholder="placeholder"
    :disabled="disabled"
    class="w-full"
    @update:model-value="(value: string | number) => emit('update:modelValue', String(value ?? ''))"
  />
  <UInput
    v-else
    :model-value="modelValue"
    type="text"
    :placeholder="placeholder"
    :disabled="disabled"
    class="w-full"
    autocomplete="off"
    @update:model-value="(value: string | number) => emit('update:modelValue', String(value ?? ''))"
  />
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { OpenAPIRouteParameter } from '~/types';

const props = withDefaults(
  defineProps<{
    modelValue: string;
    param: OpenAPIRouteParameter;
    disabled?: boolean;
  }>(),
  {
    disabled: false,
  },
);

const emit = defineEmits<{
  'update:modelValue': [value: string];
}>();

const inputType = computed<string>(() => props.param.schemaType ?? '');

const options = computed<Array<string>>(() => {
  if (props.param.schemaEnum.length > 0) {
    return props.param.schemaEnum;
  }

  if ('boolean' === inputType.value) {
    return ['true', 'false'];
  }

  return [];
});

const placeholder = computed<string>(() => {
  if (props.param.example) {
    return props.param.example;
  }

  const label = props.param.schemaFormat
    ? `${props.param.schemaType ?? 'value'} (${props.param.schemaFormat})`
    : props.param.schemaType || props.param.schemaSummary || 'value';

  return label;
});
</script>
