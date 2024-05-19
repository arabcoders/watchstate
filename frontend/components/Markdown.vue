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
  marked.use(baseUrl(api_url.value))
  marked.use({gfm: true})
  content.value = marked.parse(text)
}));

</script>
