<template>
  <div class="file-diff-container">
    <!-- No differences state -->
    <div v-if="!diffResult.hasDifferences" class="notification is-success is-light">
      <span class="icon-text">
        <span class="icon has-text-success">
          <i class="fas fa-check-circle"/>
        </span>
        <span>All backends have identical file paths</span>
      </span>
    </div>

    <!-- Compact tooltip mode -->
    <div v-else-if="compact" class="file-diff-compact">
      <div class="mb-3">
        <div class="is-size-7 has-text-weight-semibold has-text-primary is-dark mb-1">
          <span class="icon is-small">
            <i class="fas fa-star"/>
          </span>
          Reference: {{ diffResult.referenceBackend }}
        </div>
        <div class="px-2 py-1 is-size-7"
             style="border-radius: 3px;
                    border-left: 3px solid var(--bulma-warning);
                    background-color: var(--bulma-scheme-main-bis);
                    color: var(--bulma-text);
                    word-break: break-all;">
          <template v-if="diffResult.referenceSegments && diffResult.referenceSegments.length > 0">
            <span v-for="(segment, segIndex) in diffResult.referenceSegments" :key="`ref-seg-${segIndex}`"
                  :class="getCompactSegmentClass(segment)">
              {{ segment.segment }}
            </span>
          </template>
          <template v-else>
            {{ diffResult.referencePath }}
          </template>
        </div>
      </div>

      <div v-for="(chunk, chunkIndex) in diffResult.chunks" :key="`chunk-${chunkIndex}`" class="mb-2">
        <div class="is-size-7 has-text-weight-semibold has-text-warning mb-1">
          <span class="icon is-small">
            <i class="fas fa-exclamation-triangle"/>
          </span>
          {{ chunk.header }}
        </div>
        <div v-for="(line, lineIndex) in chunk.lines" :key="`${chunkIndex}-${lineIndex}`">
          <div class="px-2 py-1 is-size-7"
               style="border-radius: 3px;
                      border-left: 3px solid var(--bulma-warning);
                      background-color: var(--bulma-scheme-main-bis);
                      color: var(--bulma-text);
                      word-break: break-all;">
            <template v-if="line.pathSegments && line.pathSegments.length > 0">
              <span v-for="(segment, segIndex) in line.pathSegments" :key="`seg-${segIndex}`"
                    :class="getCompactSegmentClass(segment)">
                {{ segment.segment }}
              </span>
            </template>
            <template v-else>
              {{ line.content }}
            </template>
          </div>
        </div>
      </div>

      <div class="is-size-7 has-text-grey mt-2 pt-1" style="border-top: 1px solid var(--bulma-border);">
        <span class="icon"><i class="fas fa-info-circle"/></span>
        <span>{{ diffResult.stats.modifications }} difference{{ diffResult.stats.modifications > 1 ? 's' : '' }} found</span>
      </div>
    </div>

    <!-- Full card mode -->
    <div v-else>
      <!-- Reference backend header -->
      <div class="notification is-info is-light mb-4">
        <div class="is-flex is-align-items-center">
          <span class="icon-text">
            <span class="icon">
              <i class="fas fa-star"/>
            </span>
            <span class="has-text-weight-semibold">Reference Backend: {{ diffResult.referenceBackend }}</span>
          </span>
        </div>
        <div class="mt-2">
          <div class="path-segments" style="font-family: 'Courier New', monospace;">
            <template v-if="diffResult.referenceSegments && diffResult.referenceSegments.length > 0">
              <span v-for="(segment, segIndex) in diffResult.referenceSegments" :key="`ref-card-seg-${segIndex}`"
                    :class="getSegmentDisplayClass(segment)">
                {{ segment.segment }}
              </span>
            </template>
            <template v-else>
              <div class="tag is-info is-light is-medium">
                <span class="icon"><i class="fas fa-folder"/></span>
                <span>{{ diffResult.referencePath }}</span>
              </div>
            </template>
          </div>
        </div>
      </div>

      <!-- Different backends -->
      <div class="columns is-multiline">
        <div v-for="(chunk, chunkIndex) in diffResult.chunks" :key="`chunk-${chunkIndex}`"
             class="column is-full">
          <div class="card">
            <header class="card-header has-background-warning-light">
              <div class="card-header-title">
                <span class="icon-text">
                  <span class="icon has-text-warning">
                    <i class="fas fa-exclamation-triangle"/>
                  </span>
                  <span>{{ chunk.header }} Backend</span>
                </span>
              </div>
            </header>
            <div class="card-content">
              <div v-for="(line, lineIndex) in chunk.lines" :key="`${chunkIndex}-${lineIndex}`">
                <div class="field">
                  <label class="label is-small has-text-grey">File Path</label>
                  <div class="path-comparison">
                    <template v-if="line.pathSegments && line.pathSegments.length > 0">
                      <div class="path-segments">
                        <span v-for="(segment, segIndex) in line.pathSegments" :key="`seg-${segIndex}`"
                              :class="getSegmentDisplayClass(segment)">
                          {{ segment.segment }}
                        </span>
                      </div>
                    </template>
                    <template v-else>
                      <div class="tag is-warning is-light is-medium" style="font-family: 'Courier New', monospace;">
                        {{ line.content }}
                      </div>
                    </template>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Summary -->
      <div class="notification is-light mt-4" v-if="diffResult.stats.modifications > 0">
        <div class="content is-small">
          <p class="has-text-weight-semibold">
            <span class="icon has-text-info">
              <i class="fas fa-info-circle"/>
            </span>
            Summary: {{ diffResult.stats.modifications }}
            backend{{ diffResult.stats.modifications > 1 ? 's have' : ' has' }} different file paths
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import {computed} from 'vue'
import type {FileDiffChunk, FileDiffInput, FileDiffLine, FileDiffResult, FileDiffPathSegment} from '~/types'

const props = withDefaults(defineProps<{
  /** Array of backend-file pairs to compare */
  items: Array<FileDiffInput>
  /** Maximum context lines to show around differences */
  contextLines?: number
  /** Whether to show compact version for tooltips */
  compact?: boolean
}>(), {
  contextLines: 3,
  compact: false
})

/**
 * Simple path comparison - removes identical text from front and back
 */
const getPathDifference = (referencePath: string, otherPath: string): {
  commonStart: string,
  refDiff: string,
  otherDiff: string,
  commonEnd: string
} => {
  // Find common prefix
  let commonStart = ''
  const minLength = Math.min(referencePath.length, otherPath.length)

  for (let i = 0; i < minLength; i++) {
    if (referencePath[i] === otherPath[i]) {
      commonStart += referencePath[i]
    } else {
      break
    }
  }

  // Remove common prefix from both paths
  const refWithoutStart = referencePath.substring(commonStart.length)
  const otherWithoutStart = otherPath.substring(commonStart.length)

  // Find common suffix
  let commonEnd = ''
  const suffixMinLength = Math.min(refWithoutStart.length, otherWithoutStart.length)

  for (let i = 1; i <= suffixMinLength; i++) {
    const refChar = refWithoutStart[refWithoutStart.length - i]
    const otherChar = otherWithoutStart[otherWithoutStart.length - i]
    if (refChar === otherChar) {
      commonEnd = refChar + commonEnd
    } else {
      break
    }
  }

  // Get the differing middle parts
  const refDiff = refWithoutStart.substring(0, refWithoutStart.length - commonEnd.length)
  const otherDiff = otherWithoutStart.substring(0, otherWithoutStart.length - commonEnd.length)

  return {
    commonStart,
    refDiff,
    otherDiff,
    commonEnd
  }
}

/**
 * Creates display segments for a path difference
 */
const createDisplaySegments = (diff: {
  commonStart: string,
  refDiff: string,
  otherDiff: string,
  commonEnd: string
}, isReference: boolean): Array<{ segment: string, isDifferent: boolean }> => {
  const segments: Array<{ segment: string, isDifferent: boolean }> = []

  // Add prefix indicator if there's common start
  if (diff.commonStart) {
    segments.push({segment: '...', isDifferent: false})
  }

  // Add the different part
  const diffPart = isReference ? diff.refDiff : diff.otherDiff
  if (diffPart) {
    segments.push({segment: diffPart, isDifferent: true})
  }

  // Add suffix indicator if there's common end
  if (diff.commonEnd) {
    segments.push({segment: '...', isDifferent: false})
  }

  return segments
}

/**
 * Creates display segments for the reference path showing common vs different parts
 */
const createReferenceSegments = (referencePath: string, otherPaths: Array<string>): Array<{
  segment: string,
  isDifferent: boolean
}> => {
  if (0 === otherPaths.length) {
    return [{segment: referencePath, isDifferent: false}]
  }

  // Find the longest common prefix and suffix across all paths
  let longestCommonStart = referencePath
  let longestCommonEnd = referencePath

  for (const otherPath of otherPaths) {
    const diff = getPathDifference(referencePath, otherPath)

    // Update longest common start (take the shorter one)
    const currentCommonStart = diff.commonStart
    if (currentCommonStart.length < longestCommonStart.length) {
      longestCommonStart = currentCommonStart
    }

    // Update longest common end (take the shorter one)
    const currentCommonEnd = diff.commonEnd
    if (currentCommonEnd.length < longestCommonEnd.length) {
      longestCommonEnd = currentCommonEnd
    }
  }

  const segments: Array<{ segment: string, isDifferent: boolean }> = []

  // Add common start
  if (longestCommonStart) {
    segments.push({segment: longestCommonStart, isDifferent: false})
  }

  // Add different middle part
  const startPos = longestCommonStart.length
  const endPos = referencePath.length - longestCommonEnd.length
  const middlePart = referencePath.substring(startPos, endPos)

  if (middlePart) {
    segments.push({segment: middlePart, isDifferent: true})
  }

  // Add common end
  if (longestCommonEnd) {
    segments.push({segment: longestCommonEnd, isDifferent: false})
  }

  return segments
}

/**
 * Chooses the reference file (most common path, with first backend by order)
 */
const chooseReference = (items: Array<FileDiffInput>): FileDiffInput => {
  if (0 === items.length) {
    return {backend: '', file: ''}
  }

  if (1 === items.length) {
    return items[0]!
  }

  // Group items by file path
  const pathGroups = new Map<string, Array<FileDiffInput>>()

  for (const item of items) {
    const existing = pathGroups.get(item.file)
    if (existing) {
      existing.push(item)
    } else {
      pathGroups.set(item.file, [item])
    }
  }

  // Find the group with the most items (most common path)
  let largestGroup: Array<FileDiffInput> = []
  let maxSize = 0

  for (const group of pathGroups.values()) {
    if (group.length > maxSize) {
      maxSize = group.length
      largestGroup = group
    }
  }

  // Return the first backend from the largest group
  return largestGroup[0] || items[0]!
}

/**
 * Creates GitHub-style diff chunks from path segments, grouped by backend
 */
const createDiffChunks = (reference: FileDiffInput, others: Array<FileDiffInput>): Array<FileDiffChunk> => {
  const chunks: Array<FileDiffChunk> = []

  // Only show backends that have DIFFERENT paths from the reference path
  const differentFiles = others.filter(item => item.file !== reference.file)

  if (0 === differentFiles.length) {
    return []
  }

  // Create one chunk per backend with different path
  for (const item of differentFiles) {
    const diff = getPathDifference(reference.file, item.file)
    const otherSegments = createDisplaySegments(diff, false)

    const lines: Array<FileDiffLine> = [{
      type: 'modification',
      content: item.file,
      backend: item.backend,
      cssClass: 'diff-modified',
      pathSegments: otherSegments
    }]

    chunks.push({
      referenceStart: 1,
      referenceLines: 1,
      lines: lines,
      header: item.backend, // Use backend name as header
    })
  }

  return chunks
}

/**
 * Computes the file path comparison result
 */
const diffResult = computed<FileDiffResult>(() => {
  if (props.items.length < 2) {
    return {
      referencePath: props.items[0]?.file || '',
      referenceBackend: props.items[0]?.backend || '',
      chunks: [],
      hasDifferences: false,
      stats: {additions: 0, deletions: 0, modifications: 0},
      referenceSegments: props.items[0]?.file ? [{segment: props.items[0].file, isDifferent: false}] : []
    }
  }

  const reference = chooseReference(props.items)
  // Show ALL items (including the reference backend) and filter only by PATH differences
  const others = props.items
  const chunks = createDiffChunks(reference, others)

  // Create reference path segments
  const otherPaths = others.filter(item => item.file !== reference.file).map(item => item.file)
  const referenceSegments = createReferenceSegments(reference.file, otherPaths)

  // Calculate stats
  let modifications = 0
  for (const chunk of chunks) {
    modifications += chunk.lines.filter(line => 'modification' === line.type).length
  }

  return {
    referencePath: reference.file,
    referenceBackend: reference.backend,
    chunks: chunks,
    hasDifferences: chunks.length > 0,
    stats: {
      additions: 0, // File paths don't really have "additions"
      deletions: 0, // File paths don't really have "deletions"
      modifications: modifications
    },
    referenceSegments: referenceSegments
  }
})

/**
 * Gets CSS class for path segments in the new design
 */
const getSegmentDisplayClass = (segment: FileDiffPathSegment): string => {
  if (segment.isDifferent) {
    return 'tag is-warning has-text-dark mr-1 mb-1'
  }
  return 'has-text-grey mr-1 mb-1 px-2 py-1 has-background-light'
}

/**
 * Gets CSS class for path segments in compact mode
 */
const getCompactSegmentClass = (segment: FileDiffPathSegment): string => {
  if (segment.isDifferent) {
    return 'has-background-warning has-text-dark px-1'
  }
  return 'has-text-grey-dark'
}
</script>

<style scoped>
.file-diff-container {
  max-width: 900px;
}

.file-diff-compact {
  font-family: 'Courier New', monospace;
  font-size: 0.8rem;
  max-width: 100%;
}

.path-comparison {
  font-family: 'Courier New', monospace;
  font-size: 0.9rem;
}

.path-segments {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.25rem;
}

/* Ensure proper text wrapping for long paths */
.card-content {
  word-break: break-all;
}

/* Better spacing for icon-text components */
.icon-text .icon {
  margin-right: 0.5rem;
}
</style>
