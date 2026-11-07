<script setup>
import { ref } from 'vue'
import { Send, Pencil, Trash2, Check, X } from 'lucide-vue-next'
import { useToast } from 'vue-toastification'
import { useTranslate } from '@tolgee/vue'
import { updateShareRecipient, deleteShareRecipient, resendShareRecipient } from '../../api'
import { niceDate } from '../../utils'

const { t } = useTranslate()
const toast = useToast()

const props = defineProps({
  shareId: {
    type: Number,
    required: true
  },
  recipient: {
    type: Object,
    required: true
  },
  // Show a tick box (used when choosing who to email)
  selectable: {
    type: Boolean,
    default: false
  },
  // Show the "send the link again" action
  canResend: {
    type: Boolean,
    default: true
  }
})

const selected = defineModel('selected', { type: Boolean, default: false })

// Emitted with the share's full recipient list after any change
const emit = defineEmits(['updated'])

const editing = ref(false)
const confirmingDelete = ref(false)
const busy = ref(false)
const editName = ref('')
const editEmail = ref('')

const startEdit = () => {
  editName.value = props.recipient.name
  editEmail.value = props.recipient.email
  confirmingDelete.value = false
  editing.value = true
}

const saveEdit = async () => {
  busy.value = true
  try {
    const data = await updateShareRecipient(props.shareId, props.recipient.id, {
      name: editName.value.trim(),
      email: editEmail.value.trim()
    })
    editing.value = false
    emit('updated', data.recipients)
  } catch (error) {
    toast.error(error.errors?.email ? t.value('settings.share.recipients.invalid_email') : error.message)
  } finally {
    busy.value = false
  }
}

const remove = async () => {
  if (!confirmingDelete.value) {
    confirmingDelete.value = true
    return
  }
  busy.value = true
  try {
    const data = await deleteShareRecipient(props.shareId, props.recipient.id)
    toast.success(t.value('settings.share.recipients.removed', { name: props.recipient.name }))
    emit('updated', data.recipients)
  } catch (error) {
    toast.error(error.message)
    busy.value = false
  }
}

const resend = async () => {
  busy.value = true
  try {
    const data = await resendShareRecipient(props.shareId, props.recipient.id)
    toast.success(t.value('settings.share.recipients.sent', { name: props.recipient.name }))
    emit('updated', data.recipients)
  } catch (error) {
    toast.error(error.message)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="recipient-row" :class="{ editing }">
    <template v-if="!editing">
      <div class="checkbox-container recipient-check" v-if="selectable">
        <input type="checkbox" :id="`recipient_select_${recipient.id}`" v-model="selected" />
        <label :for="`recipient_select_${recipient.id}`" :aria-label="recipient.name"></label>
      </div>
      <div class="recipient-details" @click="selectable && (selected = !selected)" :class="{ clickable: selectable }">
        <div class="recipient-name">{{ recipient.name }}</div>
        <div class="recipient-email">{{ recipient.email }}</div>
        <div class="recipient-sent" v-if="recipient.notified_at">
          {{ $t('settings.share.recipients.last_sent', { date: niceDate(recipient.notified_at) }) }}
        </div>
      </div>
      <div class="recipient-actions">
        <button
          v-if="canResend && !confirmingDelete"
          class="secondary icon-only"
          :title="$t('settings.share.recipients.resend')"
          :disabled="busy"
          @click="resend"
        >
          <Send />
        </button>
        <button
          v-if="!confirmingDelete"
          class="secondary icon-only"
          :title="$t('settings.share.recipients.edit')"
          :disabled="busy"
          @click="startEdit"
        >
          <Pencil />
        </button>
        <button
          class="secondary"
          :class="{ 'icon-only': !confirmingDelete, 'confirm-delete': confirmingDelete }"
          :title="$t('settings.share.recipients.remove')"
          :disabled="busy"
          @click="remove"
        >
          <Trash2 />
          <span v-if="confirmingDelete">{{ $t('settings.share.recipients.confirm_remove') }}</span>
        </button>
        <button
          v-if="confirmingDelete"
          class="secondary icon-only"
          :title="$t('settings.share.manage.cancel')"
          @click="confirmingDelete = false"
        >
          <X />
        </button>
      </div>
    </template>

    <template v-else>
      <div class="recipient-edit">
        <input type="text" v-model="editName" :placeholder="$t('settings.users.name')" @keyup.enter="saveEdit" />
        <input type="email" v-model="editEmail" :placeholder="$t('settings.users.email')" @keyup.enter="saveEdit" />
      </div>
      <div class="recipient-actions">
        <button class="icon-only" :title="$t('settings.share.manage.save')" :disabled="busy" @click="saveEdit">
          <Check />
        </button>
        <button class="secondary icon-only" :title="$t('settings.share.manage.cancel')" @click="editing = false">
          <X />
        </button>
      </div>
    </template>
  </div>
</template>

<style lang="scss" scoped>
.recipient-row {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  background: var(--panel-section-background-color);
  border-radius: 5px;
}

.recipient-check {
  width: auto;
  padding-right: 0;
  grid-template-rows: auto;
  label::before {
    margin-right: 0;
  }
}

.recipient-details {
  flex-grow: 1;
  min-width: 0;
  &.clickable {
    cursor: pointer;
  }
  .recipient-name {
    font-weight: bold;
    font-size: 0.9rem;
  }
  .recipient-email,
  .recipient-sent {
    font-size: 0.75rem;
    opacity: 0.7;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

.recipient-edit {
  flex-grow: 1;
  display: flex;
  flex-direction: column;
  gap: 5px;
  input {
    width: 100%;
    margin: 0;
  }
}

.recipient-actions {
  display: flex;
  gap: 5px;
  flex-shrink: 0;
  button {
    margin: 0;
    height: 36px;
    &.icon-only {
      width: 36px;
      svg {
        width: 18px;
        height: 18px;
        margin-right: 0;
      }
    }
    &.confirm-delete {
      padding: 0 12px;
      display: flex !important;
      align-items: center;
      gap: 5px;
      white-space: nowrap;
    }
  }
}
</style>
