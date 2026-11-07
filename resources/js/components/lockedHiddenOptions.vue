<script setup>
import { ref, computed } from 'vue'
import { ChevronDown } from 'lucide-vue-next'
import { useTranslate } from '@tolgee/vue'
import { LOCKED_HIDEABLE } from '../domData'

const { t } = useTranslate()

// v-model: the list of details to hide while the share is locked
const hidden = defineModel({ type: Array, default: () => [] })

const props = defineProps({
  idPrefix: {
    type: String,
    required: true
  }
})

const open = ref(false)

// One line saying what is hidden, shown while the options are folded away
const summary = computed(() => {
  const chosen = LOCKED_HIDEABLE.filter((detail) => hidden.value.includes(detail))
  if (chosen.length === 0) {
    return t.value('settings.share.locked_hidden.summary_none')
  }
  if (chosen.length === LOCKED_HIDEABLE.length) {
    return t.value('settings.share.locked_hidden.summary_all')
  }
  return chosen.map((detail) => t.value(`settings.share.locked_hidden.short.${detail}`)).join(', ')
})
</script>

<template>
  <div class="locked-hidden-options" :class="{ open }">
    <button
      type="button"
      class="locked-hidden-toggle"
      :aria-expanded="open"
      :aria-controls="`${props.idPrefix}_options`"
      @click="open = !open"
    >
      <span class="locked-hidden-label">{{ $t('settings.share.locked_hidden.label') }}</span>
      <span class="locked-hidden-summary">{{ summary }}</span>
      <ChevronDown class="locked-hidden-chevron" />
    </button>
    <div class="locked-hidden-list" :id="`${props.idPrefix}_options`" v-show="open">
      <div class="checkbox-container" v-for="detail in LOCKED_HIDEABLE" :key="detail">
        <input type="checkbox" :id="`${props.idPrefix}_${detail}`" :value="detail" v-model="hidden" />
        <label :for="`${props.idPrefix}_${detail}`">{{ $t(`settings.share.locked_hidden.${detail}`) }}</label>
      </div>
      <p class="help-text">{{ $t('settings.share.locked_hidden.help') }}</p>
    </div>
  </div>
</template>

<style lang="scss" scoped>
.locked-hidden-options {
  width: 100%;
  background: var(--panel-section-background-color);
  border-radius: 5px;
}

// Reset the shared button styles: this is a disclosure row, not an action
.locked-hidden-options .locked-hidden-toggle {
  all: unset;
  box-sizing: border-box;
  width: 100%;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px;
  cursor: pointer;
  color: var(--panel-text-color);

  &:focus-visible {
    outline: 2px solid var(--checkbox-checked-background-color);
    outline-offset: -2px;
    border-radius: 5px;
  }

  .locked-hidden-label {
    font-size: 0.8rem;
    font-weight: bold;
    white-space: nowrap;
  }

  .locked-hidden-summary {
    flex-grow: 1;
    min-width: 0;
    font-size: 0.8rem;
    opacity: 0.7;
    text-align: right;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .locked-hidden-chevron {
    flex-shrink: 0;
    width: 16px;
    height: 16px;
    transition: transform 0.2s ease;
  }
}

.locked-hidden-options.open .locked-hidden-chevron {
  transform: rotate(180deg);
}

.locked-hidden-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 0 10px 10px;

  // The shared style keeps a second grid row for help text under every box
  .checkbox-container {
    grid-template-rows: auto;
  }

  .help-text {
    margin: 4px 0 0;
  }
}
</style>
