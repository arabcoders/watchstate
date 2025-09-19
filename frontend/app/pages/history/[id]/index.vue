<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix">
        <span class="title is-4">
          <span class="is-unselectable">
            <span class="icon"><i class="fas fa-history" />&nbsp;</span>
            <NuxtLink to="/history">History</NuxtLink>
            :
          </span>{{ headerTitle }}
        </span>
        <div class="is-pulled-right" v-if="data?.via">
          <div class="field is-grouped">
            <p class="control">
              <button @click="api_show_photos = !api_show_photos" class="button is-purple"
                v-tooltip.bottom="`${api_show_photos ? 'Hide' : 'Show'} fanart`">
                <span class="icon"><i class="fas fa-image" /></span>
              </button>
            </p>
            <p class="control" v-if="data?.files?.length > 0">
              <button @click="navigateTo(`/play/${data.id}`)" class="button has-text-white has-background-danger-50"
                v-tooltip.bottom="`${data.content_exists ? 'Play media' : 'Media is inaccessible'}`"
                :disabled="!data.content_exists">
                <span class="icon"><i class="fas fa-play" /></span>
              </button>
            </p>
            <p class="control">
              <button class="button" @click="toggleWatched"
                :class="{ 'is-success': !data.watched, 'is-danger': data.watched }"
                v-tooltip.bottom="'Toggle watch state'">
                <span class="icon">
                  <i class="fas" :class="{ 'fa-eye-slash': data.watched, 'fa-eye': !data.watched }" />
                </span>
              </button>
            </p>
            <p class="control">
              <button class="button is-danger" @click="deleteItem(data)" v-tooltip.bottom="'Delete the record'"
                :disabled="isDeleting || isLoading" :class="{ 'is-loading': isDeleting }">
                <span class="icon"><i class="fas fa-trash" /></span>
              </button>
            </p>
            <p class="control">
              <button class="button is-info" @click="loadContent(id)" :class="{ 'is-loading': isLoading }">
                <span class="icon"><i class="fas fa-sync" /></span>
              </button>
            </p>
          </div>
        </div>
        <div class="subtitle is-5" v-if="data?.via && (data?.content_title || data?.content_overview)">
          <template v-if="data?.content_title">
            <span class="is-unselectable icon">
              <i class="fas fa-tv" :class="{ 'fa-tv': 'episode' === data.type, 'fa-film': 'movie' === data.type }" />
            </span>
            {{ data?.content_title }}
          </template>
          <div v-if="data?.content_overview" class="is-hidden-mobile is-clickable"
            @click="expandOverview = !expandOverview" :class="{ 'is-text-overflow': !expandOverview }">
            <span class="is-unselectable icon" v-if="!data?.content_title">
              <i class="fas fa-tv" :class="{ 'fa-tv': 'episode' === data.type, 'fa-film': 'movie' === data.type }" />
            </span>
            {{ expandOverview ? data.content_overview : data.content_overview }}
          </div>
          <div v-if="data?.content_genres && data.content_genres.length > 0" class="is-hidden-mobile is-clickable"
            :class="{ 'is-text-overflow': !expandGenres }" @click="expandGenres = !expandGenres">
            <span class="tag is-info is-clickable mr-1" v-for="(genre, genreIndex) in data.content_genres"
              :key="`head-genre-${genreIndex}`">
              <span class="icon"><i class="fas fa-tag" /></span>
              <span class="is-capitalized" v-text="genre" />
            </span>
          </div>
        </div>
      </div>

      <div class="column is-12" v-if="!data?.via && isLoading">
        <Message message_class="has-background-info-90 has-text-dark" title="Loading" icon="fas fa-spinner fa-spin"
          message="Loading data. Please wait..." />
      </div>

      <div class="column is-12" v-if="(data?.duplicate_reference_ids || 0) > 0">
        <Message message_class="has-background-info-90 has-text-dark">
          <p>
            <span class="icon"><i class="fas fa-info-circle" /></span>
            This record shares the same file path with other records.
            <Popover v-if="(data?.duplicate_reference_ids?.length || 0) > 0" placement="bottom" trigger="click"
              :show-delay="0" :hide-delay="200" :offset="8" content-class="p-0">
              <template #trigger>
                <span class="has-text-danger is-underlined is-pointer-help">
                  Click here
                </span>
              </template>
              <template #content>
                <DuplicateRecordList :ids="data.duplicate_reference_ids ?? []" />
              </template>
            </Popover> to see the other records.
          </p>
        </Message>
      </div>

      <div class="column is-12" v-if="data?.not_reported_by && data.not_reported_by.length > 0">
        <Message message_class="has-background-warning-80 has-text-dark">
          <p>
            <span class="icon"><i class="fas fa-exclamation" /></span>
            There are no metadata regarding this <strong>{{ data.type }}</strong> from (
            <span class="tag mr-1 has-text-dark" v-for="backend in data.not_reported_by" :key="`nr-${backend}`">
              <NuxtLink :to="`/backend/${backend}`">{{ backend }}</NuxtLink>
            </span>).
          </p>
        </Message>
      </div>

    </div>

    <div class="columns is-multiline" :style="styleInfo" :class="{ 'bg-fanart': styleInfo }">
      <div class="column is-12" v-if="data?.via">
        <div class="card" :class="{ 'is-success': parseInt(data.watched), 'transparent-bg': styleInfo }">
          <header class="card-header">
            <div class="card-header-title is-clickable is-unselectable" @click="data._toggle = !data._toggle">
              <span class="icon">
                <i class="fas" :class="{ 'fa-arrow-up': data?._toggle, 'fa-arrow-down': !data?._toggle }" />
              </span>
              <span>Latest local metadata via</span>
            </div>
            <div class="card-header-icon">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-server" /></span>
                <span>
                  <NuxtLink :to="`/backend/${data.via}`">
                    {{ data.via }}
                  </NuxtLink>
                </span>
              </span>
            </div>
          </header>
          <div class="card-content" v-if="data?._toggle">
            <div class="columns is-multiline is-mobile">
              <div class="column is-6">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-passport" /></span>
                  <span>
                    <span class="is-hidden-mobile">ID:&nbsp;</span>
                    <NuxtLink :to="`/history/${data.id}`">{{ data.id }}</NuxtLink>
                  </span>
                </span>
              </div>

              <div class="column is-6 has-text-right">
                <span class="icon-text" v-if="parseInt(data.progress)">
                  <span class="icon"><i class="fas fa-bars-progress" /></span>
                  <span><span class="is-hidden-mobile">Progress:</span> {{ formatDuration(data.progress) }}</span>
                </span>
                <span v-else>-</span>
              </div>

              <div class="column is-6 has-text-left">
                <span class="icon-text">
                  <span class="icon">
                    <i class="fas fa-eye-slash" v-if="!data.watched" />
                    <i class="fas fa-eye" v-else />
                  </span>
                  <span>
                    <span class="is-hidden-mobile">Status:</span>
                    {{ data.watched ? 'Played' : 'Unplayed' }}
                  </span>
                </span>
              </div>
              <div class="column is-6 has-text-right">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-envelope" /></span>
                  <span>
                    <span class="is-hidden-mobile">Event:</span>
                    {{ ag(data.extra, `${data.via}.event`, 'Unknown') }}
                  </span>
                </span>
              </div>
              <div class="column is-6 has-text-left">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-calendar" /></span>
                  <span>
                    <span class="is-hidden-mobile">Updated:&nbsp;</span>
                    <span class="has-tooltip"
                      v-tooltip="`Backend updated this record at: ${moment.unix(data.updated).format(TOOLTIP_DATE_FORMAT)}`">
                      {{ moment.unix(data.updated).fromNow() }}
                    </span>
                  </span>
                </span>
              </div>

              <div class="column is-6 has-text-right">
                <span class="icon-text">
                  <span class="icon" v-if="'episode' === data.type"><i class="fas fa-tv" /></span>
                  <span class="icon" v-else><i class="fas fa-film" /></span>
                  <span>
                    <span class="is-hidden-mobile">Type:&nbsp;</span>
                    <NuxtLink :to="makeSearchLink('type', data.type)">{{ ucFirst(data.type) }}</NuxtLink>
                  </span>
                </span>
              </div>

              <div class="column is-6 has-text-left" v-if="'episode' === data.type">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-tv" /></span>
                  <span><span class="is-hidden-mobile">Season:&nbsp;</span>
                    <NuxtLink :to="makeSearchLink('season', data.season)">{{ data.season }}</NuxtLink>
                  </span>
                </span>
              </div>

              <div class="column is-6 has-text-right" v-if="'episode' === data.type">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-tv" /></span>
                  <span><span class="is-hidden-mobile">Episode:&nbsp;</span>
                    <NuxtLink :to="makeSearchLink('episode', data.episode)">{{ data.episode }}</NuxtLink>
                  </span>
                </span>
              </div>

              <div class="column is-12" v-if="data.guids && Object.keys(data.guids).length > 0">
                <span class="icon-text is-clickable" v-tooltip="'Globally unique identifier for this item'">
                  <span class="icon"><i class="fas fa-link" /></span>
                  <span>GUIDs:&nbsp;</span>
                </span>
                <span class="tag mr-1" v-for="(guid, source) in data.guids" :key="`guid-${id}-${source}-${guid}`">
                  <NuxtLink target="_blank" :to="makeGUIDLink(data.type, source.split('guid_')[1], guid, data)">
                    {{ source.split('guid_')[1] }}://{{ guid }}
                  </NuxtLink>
                </span>
              </div>

              <div class="column is-12" v-if="data.rguids && Object.keys(data.rguids).length > 0">
                <span class="icon-text is-clickable" v-tooltip="'Relative Globally unique identifier for this episode'">
                  <span class="icon"><i class="fas fa-link" /></span>
                  <span>rGUIDs:&nbsp;</span>
                </span>
                <span class="tag mr-1" v-for="(guid, source) in data.rguids" :key="`rguid-${id}-${source}-${guid}`">
                  <NuxtLink :to="makeSearchLink('rguid', `${source.split('guid_')[1]}://${guid}`)">
                    {{ source.split('guid_')[1] }}://{{ guid }}
                  </NuxtLink>
                </span>
              </div>

              <div class="column is-12" v-if="data.parent && Object.keys(data.parent).length > 0">
                <span class="icon-text is-clickable" v-tooltip="'Globally unique identifier for the series'">
                  <span class="icon"><i class="fas fa-link" /></span>
                  <span>Series GUIDs:&nbsp;</span>
                </span>
                <span class="tag mr-1" v-for="(guid, source) in data.parent"
                  :key="`parent-guid-${id}-${source}-${guid}`">
                  <NuxtLink target="_blank" :to="makeGUIDLink('series', source.split('guid_')[1], guid, data)">
                    {{ source.split('guid_')[1] }}://{{ guid }}
                  </NuxtLink>
                </span>
              </div>

              <div class="column is-12" v-if="data?.content_title">
                <div class="is-text-overflow">
                  <span class="icon"><i class="fas fa-heading" /></span>
                  <span class="is-hidden-mobile">Subtitle:&nbsp;</span>
                  <NuxtLink :to="makeSearchLink('subtitle', data.content_title)">{{ data.content_title }}</NuxtLink>
                </div>
              </div>

              <div class="column is-12" v-if="data?.content_path">
                <div class="is-text-overflow">
                  <span class="icon"><i class="fas fa-file" /></span>
                  <span class="is-hidden-mobile">File:&nbsp;</span>
                  <NuxtLink :to="makeSearchLink('path', data.content_path)">{{ data.content_path }}</NuxtLink>
                </div>
              </div>

              <div class="column is-6 has-text-left" v-if="data.created_at">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-database" /></span>
                  <span>
                    <span class="is-hidden-mobile">Created:&nbsp;</span>
                    <span class="has-tooltip"
                      v-tooltip="`DB record created at: ${moment.unix(data.created_at).format(TOOLTIP_DATE_FORMAT)}`">
                      {{ moment.unix(data.created_at).fromNow() }}
                    </span>
                  </span>
                </span>
              </div>

              <div class="column is-6 has-text-right" v-if="data.updated_at">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-database" /></span>
                  <span>
                    <span class="is-hidden-mobile">Updated:&nbsp;</span>
                    <span class="has-tooltip"
                      v-tooltip="`DB record updated at: ${moment.unix(data.updated_at).format(TOOLTIP_DATE_FORMAT)}`">
                      {{ moment.unix(data.updated_at).fromNow() }}
                    </span>
                  </span>
                </span>
              </div>

              <div class="is-hidden-tablet column is-12" v-if="data?.content_genres && data?.content_genres.length > 0">
                <div class="is-clickable" :class="{ 'is-text-overflow': !expandGenres }"
                  @click="expandGenres = !expandGenres">
                  <span class="icon"><i class="fas fa-tag" /></span>
                  <span class="is-hidden-mobile">Genres:&nbsp;</span>
                  <span class="tag is-info mr-1 is-capitalized" v-for="genre in data.content_genres"
                    :key="`latest-${genre}`" v-text="genre" />
                </div>
              </div>

              <div class="is-hidden-tablet column is-12" v-if="data?.content_overview">
                <span class="icon"><i class="fas fa-comment" /></span>
                <span>Content Summary</span>
                <br>
                <div class="is-clickable" :class="{ 'is-text-overflow': !expandOverview }"
                  @click="expandOverview = !expandOverview">
                  {{ data.content_overview }}
                </div>
              </div>

            </div>
          </div>
        </div>
      </div>

      <div class="column is-12" v-if="data?.via && Object.keys(data.metadata).length > 0">
        <div class="card" v-for="(item, key) in data.metadata" :key="key"
          :class="{ 'is-success': parseInt(item.watched), 'transparent-bg': styleInfo }">
          <header class="card-header">
            <div class="card-header-title is-clickable is-unselectable" @click="item._toggle = !item._toggle">
              <span class="icon">
                <i class="fas" :class="{ 'fa-arrow-up': item?._toggle, 'fa-arrow-down': !item?._toggle }" />
              </span>
              &nbsp;
              <i class="fas" :class="{
                'fa-spinner fa-spin': undefined === item?.validated,
                'fa-check has-text-success': true === item?.validated,
                'fa-xmark has-text-danger': false === item?.validated,
              }" />&nbsp;
              Metadata via
            </div>
            <div class="card-header-icon">
              <div class="field is-grouped">
                <div class="control">
                  <NuxtLink
                    @click="Object.keys(data.metadata).length > 1 ? deleteMetadata(data, key) : deleteItem(data)">
                    <span class="icon-text has-text-danger">
                      <span class="icon"><i class="fas fa-trash" /></span>
                      <span>Delete</span>
                    </span>
                  </NuxtLink>
                </div>
                <div class="control">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-server" /></span>
                    <span>
                      <NuxtLink :to="`/backend/${key}`">{{ key }}</NuxtLink>
                    </span>
                  </span>
                </div>
              </div>
            </div>
          </header>
          <div class="card-content" v-if="item?._toggle">
            <div class="columns is-multiline is-mobile">
              <div class="column is-12" v-if="false === item?.validated && item.validated_message">
                <span class="has-text-danger">({{ item.validated_message }})</span>
              </div>
              <div class="column is-6">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-passport" /></span>
                  <span>
                    <span class="is-hidden-mobile">ID:&nbsp;</span>
                    <NuxtLink :to="item?.webUrl" target="_blank" v-if="item?.webUrl">
                      {{ item.id }}
                    </NuxtLink>
                    <span v-else v-text="item.id" />
                  </span>
                </span>
              </div>

              <div class="column is-6 has-text-right">
                <span class="icon-text" v-if="parseInt(item?.progress)">
                  <span class="icon"><i class="fas fa-bars-progress" /></span>
                  <span><span class="is-hidden-mobile">Progress:</span> {{ formatDuration(item.progress) }}</span>
                </span>
                <span v-else>-</span>
              </div>

              <div class="column is-6">
                <span class="icon-text">
                  <span class="icon">
                    <i class="fas fa-eye-slash" :class="parseInt(item.watched) ? 'fa-eye-slash' : 'fa-eye'" />
                  </span>
                  <span>
                    <span class="is-hidden-mobile">Status:</span>
                    {{ parseInt(item.watched) ? 'Played' : 'Unplayed' }}
                  </span>
                </span>
              </div>

              <div class="column is-6 has-text-right">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-envelope" /></span>
                  <span>
                    <span class="is-hidden-mobile">Event:</span>
                    {{ ag(data.extra, `${key}.event`, 'Unknown') }}
                  </span>
                </span>
              </div>

              <div class="column is-6">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-calendar" /></span>
                  <span>
                    <span class="is-hidden-mobile">Updated:&nbsp;</span>
                    <span class="has-tooltip"
                      v-tooltip="`Backend last activity: ${getMoment(ag(data.extra, `${key}.received_at`, data.updated)).format(TOOLTIP_DATE_FORMAT)}`">
                      {{ getMoment(ag(data.extra, `${key}.received_at`, data.updated)).fromNow() }}
                    </span>
                  </span>
                </span>
              </div>

              <div class="column is-6 has-text-right">
                <span class="icon-text">
                  <span class="icon" v-if="'episode' === item.type"><i class="fas fa-tv" /></span>
                  <span class="icon" v-else><i class="fas fa-film" /></span>
                  <span>
                    <span class="is-hidden-mobile">Type:&nbsp;</span>
                    <NuxtLink :to="makeSearchLink('type', item.type)">{{ ucFirst(item.type) }}</NuxtLink>
                  </span>
                </span>
              </div>

              <div class="column is-6" v-if="'episode' === item.type">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-tv" /></span>
                  <span>
                    <span class="is-hidden-mobile">Season:&nbsp;</span>
                    <NuxtLink :to="makeSearchLink('season', item.season)">{{ item.season }}</NuxtLink>
                  </span>
                </span>
              </div>

              <div class="column is-6 has-text-right" v-if="'episode' === item.type">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-tv" /></span>
                  <span>
                    <span class="is-hidden-mobile">Episode:&nbsp;</span>
                    <NuxtLink :to="makeSearchLink('episode', item.episode)">{{ item.episode }}</NuxtLink>
                  </span>
                </span>
              </div>

              <div class="column is-12" v-if="item.guids && Object.keys(item.guids).length > 0">
                <span class="icon-text is-clickable" v-tooltip="'Globally unique identifier for this item'">
                  <span class="icon"><i class="fas fa-link" /></span>
                  <span>GUIDs:&nbsp;</span>
                </span>
                <span class="tag mr-1" v-for="(guid, source) in item.guids" :key="`guid-${item.id}-${source}-${guid}`">
                  <NuxtLink target="_blank" :to="makeGUIDLink(item.type, source.split('guid_')[1], guid, item)">
                    {{ source.split('guid_')[1] }}://{{ guid }}
                  </NuxtLink>
                </span>
              </div>

              <div class="column is-12" v-if="item.parent && Object.keys(item.parent).length > 0">
                <span class="is-clickable icon-text" v-tooltip="'Globally unique identifier for the series'">
                  <span class="icon"><i class="fas fa-link" /></span>
                  <span>Series GUIDs:&nbsp;</span>
                </span>
                <span class="tag mr-1" v-for="(guid, source) in item.parent"
                  :key="`parent-guid-${item.id}-${source}-${guid}`">
                  <NuxtLink target="_blank" :to="makeGUIDLink('series', source.split('guid_')[1], guid, item)">
                    {{ source.split('guid_')[1] }}://{{ guid }}
                  </NuxtLink>
                </span>
              </div>

              <div class="column is-12" v-if="item?.extra?.title">
                <div class="is-text-overflow">
                  <span class="icon"><i class="fas fa-heading" /></span>
                  <span class="is-hidden-mobile">Subtitle:&nbsp;</span>
                  <NuxtLink :to="makeSearchLink('subtitle', item.extra.title)">{{ item.extra.title }}</NuxtLink>
                </div>
              </div>

              <div class="column is-12" v-if="item?.extra?.genres && item.extra.genres.length > 0">
                <div class="is-clickable" :class="{ 'is-text-overflow': !item?.expandGenres }"
                  @click="item.expandGenres = !item?.expandGenres">
                  <span class="icon"><i class="fas fa-tag" /></span>
                  <span class="is-hidden-mobile">Genres:&nbsp;</span>
                  <span class="tag is-info mr-1 is-capitalized" v-for="genre in item.extra.genres"
                    :key="`${item.id}-${genre}`" v-text="genre" />
                </div>
              </div>

              <div class="column is-12" v-if="item?.extra?.overview">
                <span class="icon"><i class="fas fa-comment" /></span>
                <span>Content Summary</span>
                <br>
                <div class="is-clickable" :class="{ 'is-text-overflow': !item?.expandOverview }"
                  @click="item.expandOverview = !item?.expandOverview">
                  {{ item.extra.overview }}
                </div>
              </div>

              <div class="column is-12" v-if="item?.path">
                <div class="is-text-overflow">
                  <span class="icon"><i class="fas fa-file" /></span>
                  <span class="is-hidden-mobile">File:&nbsp;</span>
                  <NuxtLink :to="makeSearchLink('path', item.path)">{{ item.path }}</NuxtLink>
                </div>
              </div>

            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="columns is-multiline">
      <div class="column is-12">
        <span class="title is-4 is-clickable" @click="showRawData = !showRawData">
          <span class="icon-text">
            <span class="icon">
              <i v-if="showRawData" class="fas fa-arrow-up" />
              <i v-else class="fas fa-arrow-down" />
            </span>
            <span>Show raw unfiltered data</span>
          </span>
        </span>
        <p class="subtitle">Useful for debugging.</p>
        <div v-if="showRawData" class="mt-2" style="position: relative; max-height: 400px; overflow-y: auto;">
          <code class="is-terminal is-block is-pre-wrap p-4">{{
            JSON.stringify(Object.keys(data)
              .filter(key => !['files', 'hardware', 'content_exists', '_toggle'].includes(key))
              .reduce((obj, key) => {
                obj[key] = data[key];
                return obj;
              }, {}), null, 2)
          }}</code>
          <button class="button m-4" v-tooltip="'Copy text'" @click="() => copyText(JSON.stringify(data, null, 2))"
            style="position: absolute; top:0; right:0;">
            <span class="icon"><i class="fas fa-copy" /></span>
          </button>
        </div>
      </div>

      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
          @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">
          <ul>
            <li>
              To see if your media backends are reporting different metadata for the same file, click on the file link
              which will filter your history based on that file.
            </li>
            <li>Clicking on the ID in <code>metadata via</code> boxes will take you directly to the item in the source
              backend. While clicking on the GUIDs will take you to that source link, similarly clicking on the series
              GUIDs will take you to the series link that was provided by the external source.
            </li>
            <li>
              <code>rGUIDSs</code> are relative globally unique identifiers for episodes based on <code>series
            GUID</code>. They are formatted as <code>GUID://seriesID/season_number/episode_number</code>. We use
              <code>rGUIDs</code>, to identify specific episode. This is more reliable than using episode specific
              <code>GUID</code>, as they are often misreported in the source data.
            </li>
            <template v-if="data?.not_reported_by && data.not_reported_by.length > 0">
              <li>
                The warning on top of the page usually is accurate, and it is recommended to check the backend metadata
                for the item.
                <template v-if="'episode' === data.type">
                  For episodes, we use <code>rGUIDs</code> to identify the episode, and <strong>important part</strong>
                  of that GUID is the <code>series GUID</code>. We need at least one reported series GUIDs to match
                  between your backends. If none are matching, it will be treated as separate series.
                </template>
              </li>
            </template>
          </ul>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup>
import { request, ag, copyText, formatDuration, makeGUIDLink, makeName, makeSearchLink, notification, TOOLTIP_DATE_FORMAT, ucFirst } from '~/utils'
import moment from 'moment'
import { useBreakpoints, useStorage } from '@vueuse/core'
import Message from '~/components/Message.vue'
import { useDialog } from '~/composables/useDialog'
const id = useRoute().params.id

useHead({ title: `History : ${id}` })

const show_page_tips = useStorage('show_page_tips', true)
const api_show_photos = useStorage('api_show_photos', true)
const breakpoints = useBreakpoints({ mobile: 0, desktop: 640 })
const dialog = useDialog()

const isLoading = ref(true)
const showRawData = ref(false)
const isDeleting = ref(false)
const loadedImages = ref({ poster: null, background: null })
const expandOverview = ref(false)
const expandGenres = ref(false)
const bgImage = ref()

const styleInfo = computed(() => {
  if (!bgImage.value || !api_show_photos.value) {
    return ''
  }

  return {
    backgroundImage: `linear-gradient(rgba(0, 0, 0, 0.5), rgba(0, 0, 0, 0.5)), url(${bgImage.value})`,
  }
})

const data = ref({
  id: id,
  title: `${id}`,
  via: null,
  metadata: {},
  guids: {},
  parent: {},
  rguids: {},
  not_reported_by: [],
});

const loadContent = async (id) => {
  isLoading.value = true

  const response = await request(`/history/${id}?files=true`)
  const json = await response.json()

  if (useRoute().name !== 'history-id') {
    return
  }

  isLoading.value = false

  if (200 !== response.status) {
    notification('Error', 'Error loading data', `${json.error.code}: ${json.error.message}`);
    if (404 === response.status) {
      await navigateTo({ name: 'history' })
    }
    return
  }

  data.value = json
  data.value._toggle = true

  useHead({ title: `History : ${makeName(json) ?? id}` })
  await loadImage()
  await nextTick();
  await validateItem()
  await checkDuplicates()
}

watch(breakpoints.active(), async () => await loadImage())
watch(api_show_photos, async v => {
  if (!v) {
    return enableOpacity()
  }

  disableOpacity()
  await loadImage()
})

const loadImage = async (t = null) => {
  if (!api_show_photos.value) {
    return
  }

  try {
    let bgType = t;
    if (null === t) {
      bgType = 'mobile' === breakpoints.active().value ? 'poster' : 'background'
    }

    if (loadedImages.value[bgType]) {
      bgImage.value = loadedImages.value[bgType]
      return
    }

    const imgRequest = await request(`/history/${id}/images/${bgType}`)
    loadedImages.value[bgType] = URL.createObjectURL(await imgRequest.blob())
    bgImage.value = loadedImages.value[bgType]
  } catch { }
}

const deleteItem = async (item) => {
  if (isDeleting.value) {
    return
  }

  const { status: confirmStatus } = await dialog.confirmDialog({
    message: `Delete '${makeName(item)}' local record?`,
    opacityControl: true,
    confirmColor: 'is-danger',
  })

  if (true !== confirmStatus) {
    return
  }

  isDeleting.value = true

  try {
    const response = await request(`/history/${id}`, { method: 'DELETE' })

    if (200 !== response.status) {
      const json = await response.json()
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    notification('success', 'Success!', `Deleted '${makeName(item)}'.`)
    await navigateTo({ name: 'history' })
  } catch (e) {
    notification('error', 'Error', e.message)
  } finally {
    isDeleting.value = false
  }
};

const toggleWatched = async () => {
  if (!data.value) {
    return
  }

  const { status: confirmStatus } = await dialog.confirmDialog({
    message: `Mark '${makeName(data.value)}' as ${data.value.watched ? 'unplayed' : 'played'}?`,
    opacityControl: true,
  })

  if (true !== confirmStatus) {
    return
  }

  try {
    const response = await request(`/history/${data.value.id}/watch`, {
      method: data.value.watched ? 'DELETE' : 'POST'
    })

    const json = await response.json()

    if (200 !== response.status) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    data.value = json

    notification('success', '', `Marked '${makeName(data.value)}' as ${data.value.watched ? 'played' : 'unplayed'}`)
    await validateItem()

  } catch (e) {
    notification('error', 'Error', `Request error. ${e}`)
  }
}

const validateItem = async () => {
  try {
    const response = await request(`/history/${id}/validate`)

    if (!response.ok) {
      return
    }

    const json = await response.json()

    for (const [backend, item] of Object.entries(json)) {
      if (data.value.metadata[backend] === undefined) {
        continue
      }

      data.value.metadata[backend]['validated'] = item.status
      data.value.metadata[backend]['validated_message'] = item.message
    }
  } catch { }
}

const deleteMetadata = async (item, backend) => {

  const { status: confirmStatus } = await dialog.confirmDialog({
    message: `Remove '${backend}' metadata from '${makeName(item)}' data?`,
    opacityControl: true,
    confirmColor: 'is-danger',
  })

  if (true !== confirmStatus) {
    return
  }

  try {
    const response = await request(`/history/${id}/metadata/${backend}`, { method: 'DELETE' })

    if (200 !== response.status) {
      const json = await parse_api_response(response)
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    notification('success', 'Success!', `Deleted '${backend}' metadata.`)
    await loadContent(id);
  } catch (e) {
    notification('error', 'Error', `Request error. ${e}`)
  }
}

const checkDuplicates = async () => {
  try {
    const response = await request(`/history/${id}/duplicates`)

    if (!response.ok) {
      return
    }

    const json = await parse_api_response(response)
    if ('error' in json) {
      return
    }

    data.value.duplicate_reference_ids = json.duplicate_reference_ids

    if (json.duplicates && json.duplicates.length > 0) {
      notification('info', 'Info', `There are ${json.duplicates.length} duplicate items for this record.`, 10000)
    }
  } catch { }
}

const getMoment = (time) => time.toString().length < 13 ? moment.unix(time) : moment(time)
const headerTitle = computed(() => isLoading.value ? id : makeName(data.value))

onUnmounted(() => {
  if (api_show_photos.value) {
    enableOpacity()
  }
})

onMounted(async () => {
  if (api_show_photos.value) {
    disableOpacity()
  }
  await loadContent(id)
})
</script>
