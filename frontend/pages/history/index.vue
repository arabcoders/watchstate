<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span class="title is-4">History</span>
      <div class="is-pulled-right">
        <div class="field is-grouped">
          <p class="control">
            <button class="button is-warning" @click.prevent="searchForm = !searchForm">
              <span class="icon">
                <i class="fas fa-search"></i>
              </span>
            </button>
          </p>
          <p class="control">
            <button class="button is-primary" @click.prevent="loadContent(page, true)">
              <span class="icon">
                <i class="fas fa-sync"></i>
              </span>
            </button>
          </p>
        </div>
      </div>
    </div>

    <div class="column is-12" v-if="total && last_page > 1">
      <div class="field is-grouped">
        <div class="control">
          <button rel="first" class="button" v-if="page !== 1" @click="loadContent(1)">
            <span><<</span>
          </button>
        </div>
        <div class="control">
          <button rel="prev" class="button" v-if="page > 1 && (page-1) !== 1" @click="loadContent(page-1)">
            <span><</span>
          </button>
        </div>
        <div class="control">
          <div class="select">
            <select v-model="page" @change="loadContent(page)">
              <option v-for="(item, index) in makePagination()" :key="index" :value="item.page">
                {{ item.text }}
              </option>
            </select>
          </div>
        </div>
        <div class="control">
          <button rel="next" class="button" v-if="page !== last_page && (page+1) !== last_page"
                  @click="loadContent(page+1)">
            <span>></span>
          </button>
        </div>
        <div class="control">
          <button rel="last" class="button" v-if="page !== last_page" @click="loadContent(last_page)">
            <span>>></span>
          </button>
        </div>
      </div>
    </div>

    <div class="column is-12" v-if="searchForm">
      <form @submit.prevent="loadContent(1)">
        <div class="field has-addons">
          <div class="control has-icons-left">
            <div class="select">
              <select v-model="searchField" class="is-capitalized">
                <option value="">Select Field</option>
                <option v-for="field in searchable" :key="field" :value="field">
                  {{ field }}
                </option>
              </select>
            </div>
            <div class="icon is-small is-left">
              <i class="fas fa-folder-tree"></i>
            </div>
          </div>
          <div class="control is-expanded has-icons-left">
            <input class="input" type="search" placeholder="Search..." v-model="query" :disabled="'' === searchField">
            <div class="icon is-small is-left">
              <i class="fas fa-search"></i>
            </div>
            <p class="help" v-if="[ 'metadata', 'extra' ].includes(searchField)">
              <span class="icon has-text-danger"><i class="fas fa-exclamation"></i></span>
              <span>Searching using <code>metadata</code> or <code>extra</code> fields is not currently supported via
                WebUI.</span>
            </p>
          </div>
          <div class="control">
            <button class="button is-primary" type="submit" :disabled="!query || '' === searchField">
              <span class="icon">
                <i class="fas fa-search"></i>
              </span>
            </button>
          </div>
          <div class="control">
            <button class="button is-danger" type="button" v-tooltip="'Reset search'" @click="clearSearch">
              <span class="icon">
                <i class="fas fa-cancel"></i>
              </span>
            </button>
          </div>
        </div>
      </form>
    </div>

    <div class="column is-12">
      <div class="columns is-multiline" v-if="items?.length>0">
        <div class="column is-6-tablet" v-for="item in items" :key="item.id">
          <div class="card">
            <header class="card-header">
              <p class="card-header-title is-text-overflow is-justify-center pr-1">
                <NuxtLink :to="'/history/'+item.id">
                  {{ item.full_title ?? item.title }}
                </NuxtLink>
              </p>
              <span class="card-header-icon">
                <span class="icon" v-if="'episode' === item.type"><i class="fas fa-tv"></i></span>
                <span class="icon" v-else><i class="fas fa-film"></i></span>
              </span>
            </header>
            <div class="card-content">
              <div class="columns is-multiline is-mobile has-text-centered">
                <div class="column is-6-mobile">
                  {{ moment(item.updated).fromNow() }}
                </div>
                <div class="column is-6-mobile">
                  <NuxtLink :href="'/backend/'+item.via">
                    {{ item.via }}
                  </NuxtLink>
                </div>
                <div class="column is-6-mobile" v-if="item.event">
                  <span v-tooltip="'The event which triggered the update.'" class="has-tooltip">
                    {{ item.event }}
                  </span>
                </div>
                <div class="column is-6-mobile">
                  <span class="has-text-success" v-if="item.watched">Played</span>
                  <span class="has-text-danger" v-else>Unplayed</span>
                </div>
                <div class="column is-6-mobile" v-if="item.progress && !item.watched">
                  <span v-tooltip="'Play Progress'">
                    {{ item.progress }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="column is-12" v-else>
        <Message message_class="is-info" v-if="true === isLoading">
          <span class="icon-text">
            <span class="icon"><i class="fas fa-spinner fa-pulse"></i></span>
            <span>Loading data please wait...</span>
          </span>
        </Message>
        <Message v-else message_class="is-warning">
          <button v-if="query" class="delete" @click="clearSearch"></button>

          <div class="icon-text">
            <span class="icon"><i class="fas fa-info"></i></span>
            <span>No items found.</span>
            <span v-if="query">For <code><strong>{{ searchField }}</strong> : <strong>{{ query }}</strong></code></span>
          </div>
          <code class="is-block mt-4" v-if="error">{{ error }}</code>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup>
import request from '~/utils/request.js'
import moment from 'moment'
import Message from '~/components/Message.vue'

const route = useRoute()

useHead({title: 'History'})

const items = ref([]);
const searchable = ref(['id', 'via', 'year', 'type', 'title', 'season', 'episode', 'parent', 'guid']);
const error = ref('')

const page = ref(route.query.page ?? 1);
const perpage = ref(route.query.perpage ?? 50);
const total = ref(0);
const last_page = computed(() => Math.ceil(total.value / perpage.value));

const query = ref(route.query.q ?? '');
const searchField = ref(route.query.key ?? '');
const isLoading = ref(false);
const searchForm = ref(false);

const loadContent = async (pageNumber, fromPopState = false) => {
  pageNumber = parseInt(pageNumber);

  if (isNaN(pageNumber) || pageNumber < 1) {
    pageNumber = 1;
  }

  let title = `Links: Page #${pageNumber}`;

  let search = new URLSearchParams();
  search.set('perpage', perpage.value);
  search.set('page', pageNumber);

  if (searchField.value && query.value) {
    search.set('q', query.value);
    search.set('key', searchField.value);
    title += `. (Search: ${query.value})`;
  }

  useHead({title})

  let newUrl = window.location.pathname + '?' + search.toString();

  isLoading.value = true;
  items.value = [];

  try {
    if (searchField.value && query.value) {
      search.delete('q');
      search.delete('key');
      search.set(searchField.value, query.value)
    }

    const response = await request(`/history/?${search.toString()}`)
    const json = await response.json();

    if (!fromPopState && window.location.href !== newUrl) {
      window.history.pushState({
        page: pageNumber,
        query: query.value,
        key: searchField.value
      }, '', newUrl);
    }

    if ('paging' in json) {
      page.value = json.paging.current_page;
      perpage.value = json.paging.perpage;
      total.value = json.paging.total;
    }

    if (json.history) {
      items.value = json.history
    }

    if (json.searchable) {
      searchable.value = json.searchable
    }

    if (json.error && 404 !== json.error.code) {
      error.value = json.error
    }

    isLoading.value = false;

  } catch (e) {
  }
};

const makePagination = () => {
  let pagination = [];
  let pages = Math.ceil(total.value / perpage.value);

  if (pages < 2) {
    return pagination;
  }

  for (let i = 1; i <= pages; i++) {
    pagination.push({
      page: i,
      text: `Page #${i}`,
      selected: parseInt(page.value) === i,
    });
  }

  return pagination;
}

const clearSearch = () => {
  query.value = '';
  searchField.value = '';
  searchForm.value = false;
  loadContent(1);
}

onMounted(async () => loadContent(page.value ?? 1))
</script>
