<template>
  <div class="content" v-html="content"></div>
</template>

<script setup>
import {useStorage} from '@vueuse/core'
import {marked} from 'marked'
import {baseUrl} from 'marked-base-url'
import {disableOpacity, enableOpacity} from "~/utils/index.js";

const props = defineProps({
  file: {
    type: String,
    required: true,
  },
});

const content = ref('')
const api_url = useStorage('api_url', '')

onMounted(async () => {
  const response = await fetch(`${api_url.value}${props.file}?_=${Date.now()}`)
  const text = await response.text()

  marked.use({
    gfm: true,
    hooks: {
      postprocess: (text) => {
        // -- replace github [! with icon
        text = text.replace(/\[!IMPORTANT\]/g, `
        <span class="is-block title is-4">
            <span class="icon-text">
                <span class="icon"><i class="fas fa-exclamation-triangle has-text-danger fa-fade"></i></span>
                <span>Important</span>
            </span>
        </span>`)

        text = text.replace(/\[!NOTE\]/g, `
        <span class="is-block title is-4">
            <span class="icon-text">
                <span class="icon"><i class="fas fa-info-circle has-text-info-50"></i></span>
                <span>Note</span>
            </span>
        </span>`)
        return text
      }
    },
    ...baseUrl(api_url.value),
  });

  content.value = marked.parse(text)
});


</script>
