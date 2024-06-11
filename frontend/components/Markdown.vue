<template>
  <div class="content" v-html="content"></div>
</template>

<script setup>
import {useStorage} from '@vueuse/core'
import {marked} from 'marked'
import {baseUrl} from 'marked-base-url'

const props = defineProps({
  file: {
    type: String,
    required: true,
  },
});

const content = ref('')
const api_url = useStorage('api_url', '')

onMounted(() => fetch(`${api_url.value}${props.file}`).then(response => response.text()).then(text => {
  marked.use({
    gfm: true,
    renderer: {
      text: (text) => {
        // -- replace github [!] with icon
        text = text.replace(/\[!IMPORTANT\]/g, `
        <span class="is-block title is-4">
            <span class="icon-text">
                <span class="icon"><i class="fas fa-exclamation-triangle has-text-danger"></i></span>
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
}));

</script>
