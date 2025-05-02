<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-cogs"/></span>
          Getting Started
        </span>
        <div class="is-pulled-right"></div>
        <div class="is-hidden-mobile">
          <span class="subtitle">A step by step guide to get you started with specific tasks.</span>
        </div>
      </div>
    </div>

    <div class="columns is-multiline">
      <div class="column is-12">
        <InteractionGuide>
          <template #add_backend>
            <div id="add_backend">
              <h3 class="title is-5">Step: Add Backend</h3>
              <p>
                1. ...
              </p>
            </div>
          </template>

          <template #import_data>
            <div id="import_data">
              <h3 class="title is-5">Step: Import Data</h3>
              <p>
                1. ...
              </p>
            </div>
          </template>

          <template #force_export>
            <div id="force_export">
              <h3 class="title is-5">Step: Force Export</h3>
              <p>
                1. ...
              </p>
            </div>
          </template>

          <template #enabled_import>
            <div id="enabled_import">
              <h3 class="title is-5">Step: Enable Import</h3>
              <p>
                1. ...
              </p>
            </div>
          </template>
        </InteractionGuide>
      </div>
    </div>
  </div>
</template>

<script setup>
import InteractionGuide from "~/components/InteractionGuide.vue"
</script>
