<template>
  <img ref="imgRef" :src="imageSrc" :alt="title"/>
</template>

<script setup lang="ts">
import {onBeforeUnmount, onMounted, ref} from 'vue'
import request from '~/utils/request'

const props = defineProps<{
  id: number
  type: 'poster' | 'background'
  title?: string
}>()

const fallbackSrc = '/images/placeholder.png'
const imageSrc = ref<string>(fallbackSrc)
const imgRef = ref<HTMLImageElement | null>(null)
let objectUrl: string | null = null
let observer: IntersectionObserver | null = null

const loadImage = async () => {
  try {
    const response = await request(`/history/${props.id}/images/${props.type}`)
    if (!response.ok) return

    const blob = await response.blob()
    objectUrl = URL.createObjectURL(blob)
    imageSrc.value = objectUrl
  } catch {
    // silently fail, fallback already set
  }
}

onMounted(() => {
  if (!imgRef.value) {
    return
  }

  observer = new IntersectionObserver(([entry]) => {
    if (entry?.isIntersecting) {
      loadImage()
      observer?.disconnect()
    }
  }, {threshold: 0.1})

  observer.observe(imgRef.value)
})

onBeforeUnmount(() => {
  if (objectUrl) {
    URL.revokeObjectURL(objectUrl)
  }
  observer?.disconnect()
})
</script>
