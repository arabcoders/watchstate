<template>
  <div class="file-diff-container">
    <div v-if="!diffResult.hasDifferences" class="notification is-success is-light">
      <span class="icon-text">
        <span class="icon has-text-success">
          <i class="fas fa-check-circle" />
        </span>
        <span>All backends have identical file paths</span>
      </span>
    </div>
    
    <div v-else class="file-diff-compact">
      <div class="mb-3">
        <div class="is-size-7 has-text-weight-semibold has-text-primary is-dark mb-1">
          <span class="icon is-small"><i class="fas fa-star" /></span>
          Reference: {{ diffResult.referenceBackend }}
        </div>

        <div class="px-2 py-1 is-size-7" style="border-radius: 3px;
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
            <i class="fas fa-exclamation-triangle" />
          </span>
          {{ chunk.header }}
        </div>
        <div v-for="(line, lineIndex) in chunk.lines" :key="`${chunkIndex}-${lineIndex}`">
          <div class="px-2 py-1 is-size-7" style="border-radius: 3px;
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
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { FileDiffChunk, FileDiffInput, FileDiffLine, FileDiffResult, FileDiffPathSegment } from '~/types'

const props = withDefaults(defineProps<{ items: Array<FileDiffInput>, contextLines?: number }>(), {
  contextLines: 3,
  compact: false
})

const getPathDifference = (referencePath: string, otherPath: string): {
  commonStart: string,
  refDiff: string,
  otherDiff: string,
  commonEnd: string
} => {
  let commonStart = ''
  const minLength = Math.min(referencePath.length, otherPath.length)

  for (let i = 0; i < minLength; i++) {
    if (referencePath[i] === otherPath[i]) {
      commonStart += referencePath[i]
    } else {
      break
    }
  }

  const refWithoutStart = referencePath.substring(commonStart.length)
  const otherWithoutStart = otherPath.substring(commonStart.length)

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

  const refDiff = refWithoutStart.substring(0, refWithoutStart.length - commonEnd.length)
  const otherDiff = otherWithoutStart.substring(0, otherWithoutStart.length - commonEnd.length)

  return { commonStart, refDiff, otherDiff, commonEnd }
}

const createDisplaySegments = (diff: {
  commonStart: string,
  refDiff: string,
  otherDiff: string,
  commonEnd: string
}, isReference: boolean): Array<{ segment: string, isDifferent: boolean }> => {
  const segments: Array<{ segment: string, isDifferent: boolean }> = []

  if (diff.commonStart) {
    segments.push({ segment: '...', isDifferent: false })
  }

  const diffPart = isReference ? diff.refDiff : diff.otherDiff
  if (diffPart) {
    segments.push({ segment: diffPart, isDifferent: true })
  }

  if (diff.commonEnd) {
    segments.push({ segment: '...', isDifferent: false })
  }

  return segments
}

const createReferenceSegments = (referencePath: string, otherPaths: Array<string>): Array<{
  segment: string,
  isDifferent: boolean
}> => {
  if (0 === otherPaths.length) {
    return [{ segment: referencePath, isDifferent: false }]
  }

  let longestCommonStart = referencePath
  let longestCommonEnd = referencePath

  for (const otherPath of otherPaths) {
    const diff = getPathDifference(referencePath, otherPath)

    const currentCommonStart = diff.commonStart
    if (currentCommonStart.length < longestCommonStart.length) {
      longestCommonStart = currentCommonStart
    }

    const currentCommonEnd = diff.commonEnd
    if (currentCommonEnd.length < longestCommonEnd.length) {
      longestCommonEnd = currentCommonEnd
    }
  }

  const segments: Array<{ segment: string, isDifferent: boolean }> = []

  if (longestCommonStart) {
    segments.push({ segment: longestCommonStart, isDifferent: false })
  }

  const startPos = longestCommonStart.length
  const endPos = referencePath.length - longestCommonEnd.length
  const middlePart = referencePath.substring(startPos, endPos)

  if (middlePart) {
    segments.push({ segment: middlePart, isDifferent: true })
  }

  if (longestCommonEnd) {
    segments.push({ segment: longestCommonEnd, isDifferent: false })
  }

  return segments
}

const chooseReference = (items: Array<FileDiffInput>): FileDiffInput => {
  if (0 === items.length) {
    return { backend: '', file: '' }
  }

  if (1 === items.length) {
    return items[0]!
  }

  const pathGroups = new Map<string, Array<FileDiffInput>>()

  for (const item of items) {
    const existing = pathGroups.get(item.file)
    if (existing) {
      existing.push(item)
    } else {
      pathGroups.set(item.file, [item])
    }
  }

  let largestGroup: Array<FileDiffInput> = []
  let maxSize = 0

  for (const group of pathGroups.values()) {
    if (group.length > maxSize) {
      maxSize = group.length
      largestGroup = group
    }
  }

  return largestGroup[0] || items[0]!
}

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

    chunks.push({ referenceStart: 1, referenceLines: 1, lines: lines, header: item.backend })
  }

  return chunks
}

const diffResult = computed<FileDiffResult>(() => {
  if (props.items.length < 2) {
    return {
      referencePath: props.items[0]?.file || '',
      referenceBackend: props.items[0]?.backend || '',
      chunks: [],
      hasDifferences: false,
      stats: { additions: 0, deletions: 0, modifications: 0 },
      referenceSegments: props.items[0]?.file ? [{ segment: props.items[0].file, isDifferent: false }] : []
    }
  }

  const reference = chooseReference(props.items)
  const others = props.items
  const chunks = createDiffChunks(reference, others)

  const otherPaths = others.filter(item => item.file !== reference.file).map(item => item.file)
  const referenceSegments = createReferenceSegments(reference.file, otherPaths)

  let modifications = 0
  for (const chunk of chunks) {
    modifications += chunk.lines.filter(line => 'modification' === line.type).length
  }

  return {
    referencePath: reference.file,
    referenceBackend: reference.backend,
    chunks: chunks,
    hasDifferences: chunks.length > 0,
    stats: { additions: 0, deletions: 0, modifications: modifications },
    referenceSegments: referenceSegments
  }
})

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
