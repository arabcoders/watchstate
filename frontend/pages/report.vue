<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span class="title is-4">System Report</span>
      <div class="is-pulled-right" v-if="false === show_report_warning">
        <div class="field is-grouped">
          <p class="control">
            <button class="button is-primary" @click="copyContent" v-tooltip="'Copy Report'">
              <span class="icon"><i class="fas fa-copy"></i></span>
            </button>
          </p>
        </div>
      </div>
      <div class="subtitle">This page shows basic information about the system.</div>
    </div>

    <div class="column is-12">

      <Message message_class="is-warning" title="Warning" v-if="show_report_warning">
        <p>While we try to make sure no sensitive information is leaked via the report, it's possible that
          something might be missed. Please review the report before posting it. If you notice
          any sensitive information, please report it to the developers. so we can fix it.</p>
        <div class="mt-4">
          <button class="button is-block is-fullwidth is-primary" @click="show_report_warning = false">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-exclamation"></i></span>
              <span>I Understand, show report.</span>
            </span>
          </button>
        </div>
      </Message>

      <Message message_class="is-info" v-if="!show_report_warning && data.length < 1">
        <span class="icon"><i class="fas fa-spinner fa-pulse"></i></span>
        <span>Generating the report. Please wait...</span>
      </Message>

      <template v-if="!show_report_warning && data.length > 0">
        <pre style="min-height: 60vh;max-height:70vh; overflow-y: scroll" id="report-content"
        ><code><span v-for="(item, index) in data" :key="index" class="is-block">{{ item }}</span></code></pre>
      </template>
    </div>
  </div>
</template>

<script setup>
import {notification} from '~/utils/index.js'

useHead({title: `System Report`})

const data = ref([])
const show_report_warning = ref(true)

watch(show_report_warning, async (value) => {
  if (false !== value) {
    return
  }
  const response = await request(`/system/report`)
  data.value = await response.json()
})

const copyContent = () => {
  if (navigator.clipboard) {
    navigator.clipboard.writeText(data.value.join('\n')).then(() => {
      notification('success', 'Success', 'Report has been copied to clipboard.')
    }).catch((error) => {
      console.error('Failed to copy: ', error)
      notification('error', 'Error', 'Failed to copy the report.')
    });
    return
  }

  const node = document.querySelector('#report-content')
  const selection = window.getSelection()
  const range = document.createRange()
  range.selectNodeContents(node)
  selection.removeAllRanges()
  selection.addRange(range)

  if (('execCommand' in document) && document.execCommand('copy')) {
    selection.removeAllRanges()
    notification('success', 'Success', 'Report has been copied to clipboard.')
    return
  }

  notification('warning', 'Warning', 'Clipboard API only works on secure context. The report is selected, please use Ctrl+C to copy.')
}
</script>
