<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12">
        <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
                 icon="fas fa-spinner fa-spin" message="Loading data. Please wait..."/>

        <Message v-if="error" message_class="has-background-warning-90 has-text-dark" title="Error"
                 icon="fas fa-exclamation" :message="error"/>

        <div class="content" v-html="content" v-else/>
      </div>
    </div>
  </div>
</template>

<script setup>
import {useStorage} from '@vueuse/core'
import {marked} from 'marked'
import {baseUrl} from 'marked-base-url'
import Message from "~/components/Message.vue";

const props = defineProps({
  file: {
    type: String,
    required: true,
  },
});

const content = ref('')
const api_url = useStorage('api_url', '')
const error = ref('')
const isLoading = ref(true)

onMounted(async () => {
  try {
    isLoading.value = true
    const response = await fetch(`${api_url.value}${props.file}?_=${Date.now()}`)
    if (!response.ok) {
      const err = await parse_api_response(response)
      console.log(err)
      error.value = err.error.message
      return
    }

    const text = await response.text()
    marked.use({
      gfm: true,
      hooks: {
        postprocess: (text) => {
          // -- replace GitHub [! with icon
          text = text.replace(/\[!IMPORTANT]/g, `
        <span class="is-block title mb-2 is-5">
            <span class="icon-text">
                <span class="icon"><i class="fas fa-exclamation-triangle has-text-danger"></i></span>
                <span>IMPORTANT</span>
            </span>
        </span>`)

          text = text.replace(/\[!NOTE]/g, `
        <span class="is-block title is-5">
            <span class="icon-text">
                <span class="icon"><i class="fas fa-info has-text-info-50"></i></span>
                <span>NOTE</span>
            </span>
        </span>`)

          text = text.replace(
              /<!--\s*?i:([\w.-]+)\s*?-->/gi,
              (_, list) => `<span class="icon"><i class="fas ${list.split('.').map(n => n.trim()).join(' ')}"></i></span>`
          );

          return text
        }
      },
      ...baseUrl(api_url.value),
    });
    content.value = String(marked.parse(text))
  } catch (e) {
    console.error(e)
    error.value = e.message
  } finally {
    isLoading.value = false
  }
});


</script>
