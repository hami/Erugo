<script setup>
import { ref, watch } from 'vue'
import { getRecipientSuggestions } from '../api'

// Suggests people the user has sent shares to before, for whatever is typed in `query`
const props = defineProps({
  query: {
    type: String,
    default: ''
  },
  // Emails already chosen, left out of the list
  exclude: {
    type: Array,
    default: () => []
  }
})

const emit = defineEmits(['pick'])

const suggestions = ref([])
let timer = null
let lastRequest = 0

watch(
  () => props.query,
  (query) => {
    clearTimeout(timer)
    const q = (query || '').trim()
    if (q.length < 2) {
      suggestions.value = []
      return
    }
    timer = setTimeout(async () => {
      const request = ++lastRequest
      try {
        const found = await getRecipientSuggestions(q)
        if (request === lastRequest) {
          const excluded = props.exclude.map((email) => (email || '').toLowerCase())
          suggestions.value = found.filter((r) => !excluded.includes(r.email.toLowerCase()))
        }
      } catch (e) {
        suggestions.value = []
      }
    }, 200)
  }
)

const pick = (recipient) => {
  suggestions.value = []
  emit('pick', { name: recipient.name, email: recipient.email })
}
</script>

<template>
  <ul class="recipient-suggestions" v-if="suggestions.length" role="listbox">
    <li v-for="recipient in suggestions" :key="recipient.email" role="option">
      <button type="button" @mousedown.prevent @click="pick(recipient)">
        <span class="suggestion-name">{{ recipient.name }}</span>
        <span class="suggestion-email">{{ recipient.email }}</span>
      </button>
    </li>
  </ul>
</template>

<style lang="scss" scoped>
.recipient-suggestions {
  list-style: none;
  margin: 0;
  padding: 4px;
  width: 100%;
  background: var(--panel-section-background-color);
  border-radius: 5px;
  display: flex;
  flex-direction: column;
  gap: 2px;
  li {
    margin: 0;
  }
  button {
    all: unset;
    box-sizing: border-box;
    width: 100%;
    display: flex;
    flex-direction: column;
    padding: 6px 8px;
    border-radius: 4px;
    cursor: pointer;
    color: var(--panel-text-color);
    &:hover,
    &:focus-visible {
      background: var(--panel-section-background-color-alt);
    }
  }
  .suggestion-name {
    font-size: 0.85rem;
    font-weight: bold;
  }
  .suggestion-email {
    font-size: 0.75rem;
    opacity: 0.7;
  }
}
</style>
