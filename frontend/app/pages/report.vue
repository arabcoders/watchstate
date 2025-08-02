<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-flag"></i></span>
          System Report
        </span>
        <div class="is-pulled-right" v-if="false === show_report_warning">
          <div class="field is-grouped">
            <p class="control">
              <button class="button is-info" @click="scrollToTop" v-tooltip.bottom="'Scroll to top'">
                <span class="icon"><i class="fas fa-arrow-up"/></span>
              </button>
            </p>
            <p class="control">
              <button class="button is-warning" @click="scrollToBottom" v-tooltip.bottom="'Scroll to bottom'">
                <span class="icon"><i class="fas fa-arrow-down"/></span>
              </button>
            </p>
            <p class="control">
              <button class="button is-primary" @click="copyText(data.join('\n'))" v-tooltip.bottom="'Copy report'">
                <span class="icon"><i class="fas fa-copy"/></span>
              </button>
            </p>
          </div>
        </div>
        <div class="subtitle is-hidden-mobile">
          This page shows basic information about the various components of the system.
        </div>
      </div>

      <div class="column is-12">
        <template v-if="show_report_warning">
          <Message message_class="has-background-warning-80 has-text-dark" title="Warning"
                   icon="fas fa-exclamation-triangle">
            While we try to make sure no sensitive information is leaked via the report, it's possible that something
            might be missed. Please review the report before posting it. If you notice any sensitive information, please
            report it to the developers. so we can fix it.
          </Message>
          <div class="mt-4 has-text-centered">
            <NuxtLink class="is-block is-fullwidth is-primary" @click="show_report_warning = false">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-thumbs-up"></i></span>
                <span>I understand. Show me the report.</span>
              </span>
            </NuxtLink>
          </div>
        </template>
        <Message message_class="has-background-info-90 has-text-dark" v-if="!show_report_warning && data.length < 1"
                 title="Loading" icon="fas fa-spinner fa-spin" message="Generating the report. Please wait..."/>
        <template v-if="!show_report_warning && data.length > 0">
          <pre style="min-height: 60vh;max-height:70vh; overflow-y: scroll" class="is-terminal"
               ref="data-content"><code><div ref="topMarker"></div><span v-for="(item, index) in data" :key="index"
                                                                         class="is-block">{{ item }}</span></code><div
              ref="bottomMarker"></div></pre>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup>
import {copyText} from '~/utils/index.js'
import request from '~/utils/request.js'

useHead({title: `System Report`})

const data = ref([])
const show_report_warning = ref(true)

/** @type {Ref<HTMLPreElement|null>} */
const bottomMarker = ref(null)

/** @type {Ref<HTMLPreElement|null>} */
const topMarker = ref(null)

watch(show_report_warning, async v => {
  if (false !== v) {
    return
  }

  const response = await request(`/system/report`)
  const json = await response.json()

  if (useRoute().name !== 'report') {
    return
  }

  data.value = json
})

const scrollToTop = () => {
  if (topMarker.value) {
    topMarker.value.scrollIntoView({behavior: 'smooth'})
  }
}
const scrollToBottom = () => {
  if (bottomMarker.value) {
    bottomMarker.value.scrollIntoView({behavior: 'smooth'})
  }
}
</script>
