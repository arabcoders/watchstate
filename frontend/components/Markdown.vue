<template>
  <div class="content" v-html="content"></div>
</template>

<script setup>
import {useStorage} from "@vueuse/core"
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
        text = text.replace(/\[!IMPORTANT\]/g, '<i class="fas fa-exclamation-triangle has-text-danger"></i>')
        text = text.replace(/\[!NOTE\]/g, '<i class="fas fa-exclamation-circle has-text-info"></i>')
        return text
      }
    },
    ...baseUrl(api_url.value),
  });
  content.value = marked.parse(text)
}));

</script>
