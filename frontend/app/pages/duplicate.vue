<template>
    <div>
        <div class="columns is-multiline">
            <div class="column is-12 is-clearfix is-unselectable">
                <span class="title is-4">
                    <span class="icon"><i class="fas fa-copy" /></span>
                    <span>
                        <template v-if="isMobile">DFR</template>
                        <template v-else>Duplicate File Reference</template>
                    </span>
                </span>
                <div class="is-pulled-right">
                    <div class="field is-grouped">
                        <div class="control has-icons-left" v-if="showFilter">
                            <input type="search" v-model.lazy="filter" class="input" id="filter"
                                placeholder="Filter displayed results.">
                            <span class="icon is-left"><i class="fas fa-filter" /></span>
                        </div>

                        <div class="control">
                            <button class="button is-danger is-light" @click="toggleFilter">
                                <span class="icon"><i class="fas fa-filter" /></span>
                                <span v-if="!isMobile">Filter</span>
                            </button>
                        </div>

                        <div class="control">
                            <button class="button is-danger" @click="deleteRecords">
                                <span class="icon"><i class="fas fa-trash" /></span>
                                <span v-if="!isMobile">Delete</span>
                            </button>
                        </div>

                        <p class="control">
                            <button class="button is-info" @click.prevent="loadContent(page, true, true)"
                                :disabled="isLoading" :class="{ 'is-loading': isLoading }">
                                <span class="icon"><i class="fas fa-sync" /></span>
                                <span v-if="!isMobile">Reload</span>
                            </button>
                        </p>
                    </div>
                </div>
                <div class="is-hidden-mobile">
                    <span class="subtitle">
                        This tool is useful to discover if your backends are reporting different metadata for same
                        files.
                    </span>
                </div>
            </div>

            <div class="column is-12" v-if="total && last_page > 1">
                <div class="field is-grouped">
                    <div class="control" v-if="page !== 1">
                        <button rel="first" class="button" @click="loadContent(1)" :disabled="isLoading"
                            :class="{ 'is-loading': isLoading }">
                            <span>&lt;&lt;</span>
                        </button>
                    </div>
                    <div class="control" v-if="page > 1 && (page - 1) !== 1">
                        <button rel="prev" class="button" @click="loadContent(page - 1)" :disabled="isLoading"
                            :class="{ 'is-loading': isLoading }">
                            <span>&lt;</span>
                        </button>
                    </div>
                    <div class="control">
                        <div class="select">
                            <select v-model="page" @change="loadContent(page)" :disabled="isLoading">
                                <option v-for="(item, index) in makePagination(page, last_page)" :key="index"
                                    :value="item.page">
                                    {{ item.text }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <div class="control" v-if="page !== last_page && (page + 1) !== last_page">
                        <button rel="next" class="button" @click="loadContent(page + 1)" :disabled="isLoading"
                            :class="{ 'is-loading': isLoading }">
                            <span>&gt;</span>
                        </button>
                    </div>
                    <div class="control" v-if="page !== last_page">
                        <button rel="last" class="button" @click="loadContent(last_page)" :disabled="isLoading"
                            :class="{ 'is-loading': isLoading }">
                            <span>&gt;&gt;</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="column is-12">
                <div class="columns is-multiline" v-if="filteredRows(items)?.length > 0">
                    <template v-for="item in items" :key="item.id">
                        <Lazy :unrender="true" :min-height="270" class="column is-6-tablet" v-if="filterItem(item)">
                            <div class="card" :class="{ 'is-success': item.watched }">
                                <header class="card-header">
                                    <p class="card-header-title is-text-overflow pr-1">
                                        <FloatingImage :image="`/history/${item.id}/images/poster`"
                                            :item_class="'scaled-image'" v-if="poster_enable">
                                            <NuxtLink :to="'/history/' + item.id">
                                                {{ item?.full_title || makeName(item) }}
                                            </NuxtLink>
                                        </FloatingImage>
                                        <NuxtLink :to="'/history/' + item.id" v-else>
                                            {{ item?.full_title || makeName(item) }}
                                        </NuxtLink>
                                    </p>
                                    <span class="card-header-icon is-flex is-align-items-center">
                                        <Popover v-if="(item?.duplicate_reference_ids?.length || 0) > 0" placement="top"
                                            trigger="hover" :show-delay="200" :hide-delay="200" :offset="8"
                                            content-class="p-0">
                                            <template #trigger>
                                                <span class="tag is-warning is-bold is-clickable is-size-7">
                                                    <span class="icon is-small mr-1"><i
                                                            class="fas fa-layer-group" /></span>
                                                    <span>{{ item.duplicate_reference_ids?.length }}</span>
                                                </span>
                                            </template>
                                            <template #content>
                                                <DuplicateRecordList :ids="item.duplicate_reference_ids ?? []" />
                                            </template>
                                        </Popover>
                                        <span class="icon">
                                            <i class="fas"
                                                :class="'episode' === item.type.toLowerCase() ? 'fa-tv' : 'fa-film'" />
                                        </span>
                                    </span>
                                </header>
                                <div class="card-content">
                                    <div class="columns is-multiline is-mobile">
                                        <div class="column is-12">
                                            <div class="field is-grouped">
                                                <div class="control" @click="item.expand_title = !item?.expand_title">
                                                    <span class="icon"><i class="fas fa-heading" /></span>
                                                </div>
                                                <div class="control is-expanded is-clickable"
                                                    :class="{ 'is-text-overflow': !item?.expand_title, 'is-text-contents': item?.expand_title }">
                                                    <template v-if="item?.content_title">
                                                        <NuxtLink :to="makeSearchLink('subtitle', item.content_title)">
                                                            {{ item.content_title }}
                                                        </NuxtLink>
                                                    </template>
                                                    <template v-else>
                                                        <NuxtLink :to="makeSearchLink('subtitle', item.title)">
                                                            {{ item.title }}
                                                        </NuxtLink>
                                                    </template>
                                                </div>
                                                <div class="control">
                                                    <span class="icon is-clickable"
                                                        @click="copyText(item?.content_title ?? item.title, false)">
                                                        <i class="fas fa-copy" /></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="column is-12">
                                            <div class="field is-grouped">
                                                <div class="control" @click="item.expand_path = !item?.expand_path">
                                                    <span class="icon"><i class="fas fa-file" /></span>
                                                </div>
                                                <div class="control is-expanded is-clickable"
                                                    :class="{ 'is-text-overflow': !item?.expand_path, 'is-text-contents': item?.expand_path }">
                                                    <div class="is-flex is-align-items-center">
                                                        <!-- Popover wrapping the NuxtLink directly (only if differences exist) -->
                                                        <Popover v-if="item?.content_path && hasFileDifferences(item)"
                                                            placement="bottom-start" trigger="hover" :show-delay="200"
                                                            :hide-delay="200" :offset="8" content-class="p-0">
                                                            <template #trigger>
                                                                <NuxtLink
                                                                    :to="makeSearchLink('path', item.content_path)"
                                                                    :class="{ 'is-text-overflow': !item?.expand_path, 'is-text-contents': item?.expand_path }"
                                                                    style="display: block; width: 100%;">
                                                                    {{ item.content_path }}
                                                                </NuxtLink>
                                                            </template>
                                                            <template #content>
                                                                <div class="file-diff-popover"
                                                                    style="min-width: 300px; max-width: 500px;">
                                                                    <div
                                                                        class="has-background-warning px-4 py-3 has-text-dark">
                                                                        <div class="is-size-6 has-text-weight-semibold">
                                                                            <span class="icon is-small"><i
                                                                                    class="fas fa-exclamation-circle" /></span>
                                                                            Path Differences Found
                                                                        </div>
                                                                    </div>
                                                                    <div class="p-3">
                                                                        <FileDiff :items="getFileDiffData(item)" />
                                                                    </div>
                                                                </div>
                                                            </template>
                                                        </Popover>
                                                        <NuxtLink v-else-if="item?.content_path"
                                                            :to="makeSearchLink('path', item.content_path)"
                                                            :class="{ 'is-text-overflow': !item?.expand_path, 'is-text-contents': item?.expand_path }"
                                                            style="display: block; width: 100%;">
                                                            {{ item.content_path }}
                                                        </NuxtLink>
                                                        <span v-else>No path found.</span>
                                                    </div>
                                                </div>
                                                <div class="control">
                                                    <span class="icon is-clickable"
                                                        @click="copyText(item?.content_path || '', false)">
                                                        <i class="fas fa-copy" /></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="column is-12">
                                            <div class="field is-grouped">
                                                <div class="control is-expanded is-unselectable">
                                                    <span class="icon"><i class="fas fa-info" />&nbsp;</span>
                                                    <span>Has metadata from</span>
                                                </div>
                                                <div class="control">
                                                    <NuxtLink v-for="backend in item.reported_by"
                                                        :key="`${item.id}-rb-${backend}`" :to="`/backend/${backend}`"
                                                        class="tag ml-1"
                                                        :class="hasUniqueFilePath(item, backend) ? 'is-warning' : 'is-primary'">
                                                        {{ backend }}
                                                    </NuxtLink>
                                                    <NuxtLink v-for="backend in item.not_reported_by"
                                                        :key="`${item.id}-nrb-${backend}`" :to="`/backend/${backend}`"
                                                        class="tag is-danger ml-1">
                                                        {{ backend }}
                                                    </NuxtLink>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer">
                                    <div class="card-footer-item">
                                        <span class="icon">
                                            <i class="fas"
                                                :class="{ 'fa-eye': item.watched, 'fa-eye-slash': !item.watched }" />&nbsp;
                                        </span>
                                        <span class="has-text-success" v-if="item.watched">Played</span>
                                        <span class="has-text-danger" v-else>Unplayed</span>
                                    </div>
                                    <div class="card-footer-item">
                                        <span class="icon"><i class="fas fa-calendar" />&nbsp;</span>
                                        <span class="has-tooltip"
                                            v-tooltip="`Record updated at: ${moment.unix(item.updated_at).format(TOOLTIP_DATE_FORMAT)}`">
                                            {{ moment.unix(item.updated_at).fromNow() }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </lazy>
                    </template>
                </div>

                <div class="column is-12" v-else>
                    <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
                        icon="fas fa-spinner fa-spin" message="Loading data. Please wait..." />
                    <template v-else>
                        <Message message_class="has-background-warning-80 has-text-dark"
                            v-if="filter && items.length > 1" title="Information" icon="fas fa-check">
                            The filter <code>{{ filter }}</code> did not match any thing.
                        </Message>
                        <Message message_class="has-background-success-90 has-text-dark"
                            v-if="!filter || items.length < 1" title="Success" icon="fas fa-check">
                            There are no duplicate file references in the database.
                        </Message>
                    </template>
                </div>

                <div class="column is-12">
                    <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
                        @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips"
                        icon="fas fa-info-circle">
                        <ul>
                            <li>This checker will only works <b>if your media servers are actually using same file
                                    paths</b>.</li>
                            <li>If you see multi-episode records, that mean your metadata need to be forcibly updated.
                                Go to backends
                                page and select the <code>9th</code> option to force metadata update for that backend.
                            </li>
                            <li>The initial request is quite slow as we traverse the entire database looking for
                                duplicate file
                                references. Once the initial request is done, the subsequent requests will be much
                                faster as we cache
                                the results. To force cache invalidation, you have to click on the reload button.
                            </li>
                        </ul>
                    </Message>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useHead, useRoute, useRouter } from '#app'
import { useMediaQuery, useStorage } from '@vueuse/core'
import moment from 'moment'
import Message from '~/components/Message.vue'
import Lazy from '~/components/Lazy.vue'
import FloatingImage from '~/components/FloatingImage.vue'
import FileDiff from '~/components/FileDiff.vue'
import Popover from '~/components/Popover.vue'
import DuplicateRecordList from '~/components/DuplicateRecordList.vue'
import { NuxtLink } from '#components'
import { useDialog } from '~/composables/useDialog'
import {
    awaitElement,
    copyText,
    makeName,
    makePagination,
    makeSearchLink,
    notification,
    parse_api_response,
    request,
    TOOLTIP_DATE_FORMAT
} from '~/utils'
import type { FileDiffInput, HistoryItem } from '~/types'

type DuplicateItemWithUI = HistoryItem & {
    /** UI state: whether title is expanded for display */
    expand_title?: boolean
    /** UI state: whether path is expanded for display */
    expand_path?: boolean
}

const route = useRoute()
const router = useRouter()

useHead({ title: 'DFR' })

const show_page_tips = useStorage('show_page_tips', true)
const poster_enable = useStorage('poster_enable', true)
const isMobile = useMediaQuery('(max-width: 1024px)')

const items = ref<Array<DuplicateItemWithUI>>([])
const page = ref<number>(Number(route.query.page) || 1)
const perpage = ref<number>(Number(route.query.perpage) || 50)
const total = ref<number>(0)
const last_page = computed<number>(() => Math.ceil(total.value / perpage.value))
const isLoading = ref<boolean>(false)
const filter = ref<string>(String(route.query.filter || ''))
const showFilter = ref<boolean>(!!filter.value)

const toggleFilter = (): void => {
    showFilter.value = !showFilter.value
    if (!showFilter.value) {
        filter.value = ''
        return
    }

    awaitElement('#filter', (_, elm) => (elm as HTMLInputElement).focus())
}

const hasFileDifferences = (item: DuplicateItemWithUI): boolean => getFileDiffData(item).length > 0

const getFileDiffData = (item: DuplicateItemWithUI): Array<FileDiffInput> => {
    if (!item?.metadata) {
        return []
    }

    const fileGroups: Record<string, Array<string>> = {}

    for (const bName of Object.keys(item.metadata)) {
        const bNameTyped = bName as keyof typeof item.metadata
        if (!item.metadata[bNameTyped]) {
            continue
        }

        const file = item.metadata[bNameTyped]?.path || ''

        if (!file) {
            continue
        }

        if (!fileGroups[file]) {
            fileGroups[file] = []
        }

        fileGroups[file].push(bName)
    }

    let referenceFile = ''
    let maxBackends = 0

    for (const [file, backends] of Object.entries(fileGroups)) {
        if (backends.length > maxBackends) {
            maxBackends = backends.length
            referenceFile = file
        }
    }

    if (!referenceFile || Object.keys(fileGroups).length <= 1) {
        return []
    }

    const diffItems: Array<FileDiffInput> = []

    // Add the reference file first
    const referenceBackends = fileGroups[referenceFile] || []
    const referenceBackendName = referenceBackends.length > 1 ? referenceBackends.sort().join(', ') : referenceBackends[0] || ''

    diffItems.push({
        backend: referenceBackendName,
        file: referenceFile
    })

    // Add only the backends that have different file paths from reference
    for (const [file, backends] of Object.entries(fileGroups)) {
        if (file !== referenceFile) {
            const mergedBackendName = backends.length > 1 ? backends.sort().join(', ') : backends[0] || ''
            diffItems.push({
                backend: mergedBackendName,
                file: file
            })
        }
    }

    return diffItems
}

const hasUniqueFilePath = (item: DuplicateItemWithUI, targetBackend: string): boolean => {
    if (!item?.metadata) {
        return false
    }

    const reportedBackends = Object.keys(item.metadata).filter(bName => item.metadata[bName as keyof typeof item.metadata])
    if (reportedBackends.length <= 1) {
        return false
    }


    const targetMetadata = item.metadata[targetBackend as keyof typeof item.metadata]
    const targetFile = targetMetadata?.path || ''

    if (!targetFile) {
        return false
    }

    let backendsWithSameFile = 0
    for (const bName of Object.keys(item.metadata)) {
        const bNameTyped = bName as keyof typeof item.metadata
        if (!item.metadata[bNameTyped]) {
            continue
        }

        const file = item.metadata[bNameTyped]?.path || ''
        if (file === targetFile) {
            backendsWithSameFile++
        }
    }

    return 1 === backendsWithSameFile
}

const loadContent = async (pageNumber: number, fromPopState = false, fromReload = false): Promise<void> => {
    pageNumber = parseInt(String(pageNumber))

    if (isNaN(pageNumber) || 1 > pageNumber) {
        pageNumber = 1
    }

    const search = new URLSearchParams()
    search.set('perpage', String(perpage.value))
    search.set('page', String(pageNumber))

    let pageTitle = `DFR: Page #${pageNumber}`

    if (filter.value) {
        search.set('filter', filter.value)
        pageTitle += ` - Filter: ${filter.value}`
    }

    useHead({ title: pageTitle })

    const newUrl = window.location.pathname + '?' + search.toString()
    isLoading.value = true
    items.value = []

    page.value = pageNumber

    try {
        if (true === fromReload) {
            search.set('no_cache', '1')
        }

        const response = await request(`/system/duplicate/?${search.toString()}`)

        const json = await parse_api_response<{
            items: Array<DuplicateItemWithUI>
            paging: { current_page: number, perpage: number, total: number }
        }>(response)

        if ('duplicate' !== useRoute().name) {
            return
        }

        if ('error' in json) {
            notification('error', 'Error', `API Error. ${json.error.code}: ${json.error.message}`)
            return
        }

        if (!fromPopState && newUrl !== window.location.href) {
            await router.push({ path: '/duplicate', query: Object.fromEntries(search) })
        }

        if ('paging' in json && json.paging) {
            page.value = json.paging.current_page
            perpage.value = json.paging.perpage
            total.value = json.paging.total
        } else {
            page.value = 1
            total.value = 0
        }

        if (json.items) {
            items.value = json.items
        }

    } catch (e: unknown) {
        const error = e as Error
        notification('error', 'Error', `Request error. ${error.message}`)
    } finally {
        isLoading.value = false
    }
}

onMounted(async () => {
    await loadContent(page.value || 1)
    window.addEventListener('popstate', stateCallBack)
})

onBeforeUnmount(() => window.removeEventListener('popstate', stateCallBack))

const filteredRows = (items: Array<DuplicateItemWithUI>): Array<DuplicateItemWithUI> => {
    if (!filter.value) {
        return items
    }

    return items.filter(i => Object.values(i).some(v => 'string' === typeof v ? v.toLowerCase().includes(filter.value.toLowerCase()) : false))
}

const filterItem = (item: DuplicateItemWithUI): boolean => {
    if (!filter.value || !item) {
        return true
    }

    return Object.values(item).some(v => 'string' === typeof v ? v.toLowerCase().includes(filter.value.toLowerCase()) : false)
}

watch(filter, (val: string) => {
    if (!val) {
        if (!route?.query['filter']) {
            return
        }

        router.push({
            path: '/duplicate',
            query: {
                ...route.query,
                filter: undefined
            }
        })
        return
    }

    if (val === route?.query['filter']) {
        return
    }

    router.push({
        path: '/duplicate',
        query: {
            ...route.query,
            filter: val
        }
    })
})

const stateCallBack = async (e: PopStateEvent): Promise<void> => {
    if (!e.state) {
        return
    }

    const route = useRoute()
    page.value = Number(route.query.page) || 1
    perpage.value = Number(route.query.perpage) || 50
    filter.value = String(route.query.filter || '')
    if (filter.value) {
        showFilter.value = true
    }
    await loadContent(page.value, true)
}

const deleteRecords = async (): Promise<void> => {
    const { status: confirmStatus } = await useDialog().confirmDialog({
        message: `Delete '${total.value}' items?`,
        confirmColor: 'is-danger',
    })

    if (true !== confirmStatus) {
        return
    }

    try {
        const response = await request('/system/duplicate', { method: 'DELETE' })
        if (!response.ok) {
            const json = await parse_api_response(response)
            if ('error' in json) {
                notification('error', 'Error', `API Error. ${json.error.code}: ${json.error.message}`)
            }
            return
        }

        notification('success', 'Success', `Successfully deleted '${total.value}' items.`)
        await loadContent(page.value, true, true)
    } catch (error: unknown) {
        const err = error as Error
        notification('error', 'Error', `Request error. ${err.message}`)
    }
}
</script>
