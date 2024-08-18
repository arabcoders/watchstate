<template>
  <div class="field is-grouped">
    <div class="control">
      <button rel="first" class="button" v-if="page !== 1" @click="changePage(1)" :disabled="isLoading"
              :class="{'is-loading':isLoading}">
        <span class="icon"><i class="fas fa-angle-double-left"></i></span>
      </button>
    </div>
    <div class="control">
      <button rel="prev" class="button" v-if="page > 1 && (page-1) !== 1" @click="changePage(page-1)"
              :disabled="isLoading" :class="{'is-loading':isLoading}">
        <span class="icon"><i class="fas fa-angle-left"></i></span>
      </button>
    </div>
    <div class="control">
      <div class="select">
        <select id="pager_list" v-model="currentPage" @change="changePage(currentPage)" :disabled="isLoading">
          <option v-for="(item, index) in makePagination(page, last_page)" :key="`pager-${index}`"
                  :value="item.page" :disabled="0 === item.page">
            {{ item.text }}
          </option>
        </select>
      </div>
    </div>
    <div class="control">
      <button rel="next" class="button" v-if="page !== last_page && ( page + 1 ) !== last_page"
              @click="changePage( page + 1 )" :disabled="isLoading" :class="{ 'is-loading': isLoading }">
        <span class="icon"><i class="fas fa-angle-right"></i></span>
      </button>
    </div>
    <div class="control">
      <button rel="last" class="button" v-if="page !== last_page" @click="changePage(last_page)"
              :disabled="isLoading" :class="{ 'is-loading': isLoading }">
        <span class="icon"><i class="fas fa-angle-double-right"></i></span>
      </button>
    </div>
  </div>
</template>

<script setup>
import {makePagination} from '~/utils/index'

const emitter = defineEmits(['navigate'])

const props = defineProps({
  page: {
    type: Number,
    required: true
  },
  last_page: {
    type: Number,
    required: true
  },
  isLoading: {
    type: Boolean,
    required: false,
    default: false
  },
})

const changePage = p => {
  if (p < 1 || p > props.last_page) {
    return
  }
  emitter('navigate', p)
  currentPage.value = p
}

const currentPage = ref(props.page)
</script>
