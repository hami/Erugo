<script setup>
import { ref, watch, computed } from 'vue'
import {
  Settings2,
  Users,
  KeyRound,
  Save,
  CircleX,
  UserPlus,
  Link2,
  Copy,
  Check,
  ArrowLeft
} from 'lucide-vue-next'
import { useToast } from 'vue-toastification'
import { useTranslate } from '@tolgee/vue'
import { updateShare, changeShareAccess, addShareRecipient } from '../../api'
import LockedHiddenOptions from '../lockedHiddenOptions.vue'
import ShareRecipientRow from './shareRecipientRow.vue'
import RecipientSuggestions from '../recipientSuggestions.vue'

const { t } = useTranslate()
const toast = useToast()

const props = defineProps({
  // The share to manage; null closes the dialog
  share: {
    type: Object,
    default: null
  },
  // Which part to open on: 'details', 'recipients' or 'access'
  section: {
    type: String,
    default: 'details'
  },
  // With section 'access', which change to start on: 'link', 'password' or 'both'
  accessMode: {
    type: String,
    default: 'link'
  }
})

// close(changed): changed is true if anything was saved, so the list can reload
const emit = defineEmits(['close'])

const active = computed(() => props.share !== null)
const current = ref(null)
const tab = ref('details')
const changed = ref(false)
const busy = ref(false)

// Details
const name = ref('')
const removePassword = ref(false)
const lockedHidden = ref([])
const nameError = ref(null)

// Recipients
const newRecipientName = ref('')
const newRecipientEmail = ref('')
const sendToNew = ref(true)
const recipientQuery = ref('')

// Link & password: step 'choose' then step 'notify'
const accessStep = ref('choose')
const mode = ref('link')
const password = ref('')
const passwordConfirm = ref('')
const passwordError = ref(null)
const notifyWho = ref('all') // 'all', 'some' or 'none'
const chosen = ref({})
const includePassword = ref(false)
const copied = ref(false)

watch(
  () => props.share,
  (share) => {
    if (!share) {
      return
    }
    current.value = JSON.parse(JSON.stringify(share))
    current.value.recipients = current.value.recipients || []
    changed.value = false
    tab.value = props.section
    resetDetails()
    resetAccess(props.accessMode)
    newRecipientName.value = ''
    newRecipientEmail.value = ''
    sendToNew.value = true
  }
)

const resetDetails = () => {
  name.value = current.value.name
  removePassword.value = false
  lockedHidden.value = [...(current.value.locked_hidden || [])]
  nameError.value = null
}

const resetAccess = (startMode = 'link') => {
  accessStep.value = 'choose'
  mode.value = startMode
  password.value = ''
  passwordConfirm.value = ''
  passwordError.value = null
  notifyWho.value = current.value.recipients.length ? 'all' : 'none'
  chosen.value = Object.fromEntries(current.value.recipients.map((r) => [r.id, true]))
  includePassword.value = false
}

const recipients = computed(() => current.value?.recipients || [])
const shareUrl = computed(() => (current.value ? `${window.location.origin}/shares/${current.value.long_id}` : ''))
const changesPassword = computed(() => mode.value === 'password' || mode.value === 'both')
const changesLink = computed(() => mode.value === 'link' || mode.value === 'both')

// Whether the share will have a password once the details are saved
const willBeProtected = computed(() => current.value?.password_protected && !removePassword.value)

const close = () => emit('close', changed.value)


const updateRecipients = (list) => {
  current.value.recipients = list
  // keep the choice for people still on the list; newcomers start ticked
  chosen.value = Object.fromEntries(list.map((r) => [r.id, chosen.value[r.id] ?? true]))
  changed.value = true
}

// --- Details ---

const saveDetails = async () => {
  nameError.value = null
  if (!name.value.trim()) {
    nameError.value = t.value('settings.share.manage.name_required')
    return
  }
  const changes = { name: name.value.trim(), locked_hidden: lockedHidden.value }
  if (removePassword.value) {
    changes.remove_password = true
  }
  busy.value = true
  try {
    const share = await updateShare(current.value.id, changes)
    current.value = { ...current.value, ...share, recipients: current.value.recipients }
    resetDetails()
    changed.value = true
    toast.success(t.value('settings.share.manage.saved'))
  } catch (error) {
    toast.error(t.value('settings.share.manage.save_failed'))
  } finally {
    busy.value = false
  }
}

// --- Recipients ---

const addRecipient = async () => {
  if (!newRecipientName.value.trim() || !newRecipientEmail.value.trim()) {
    toast.error(t.value('settings.share.recipients.need_name_email'))
    return
  }
  busy.value = true
  try {
    const data = await addShareRecipient(
      current.value.id,
      { name: newRecipientName.value.trim(), email: newRecipientEmail.value.trim() },
      sendToNew.value
    )
    updateRecipients(data.recipients)
    toast.success(
      sendToNew.value
        ? t.value('settings.share.recipients.added_sent', { name: data.recipient.name })
        : t.value('settings.share.recipients.added', { name: data.recipient.name })
    )
    newRecipientName.value = ''
    newRecipientEmail.value = ''
    recipientQuery.value = ''
  } catch (error) {
    toast.error(error.errors?.email ? t.value('settings.share.recipients.invalid_email') : error.message)
  } finally {
    busy.value = false
  }
}

const useSuggestion = (picked) => {
  newRecipientName.value = picked.name
  newRecipientEmail.value = picked.email
  recipientQuery.value = ''
}

// --- Link & password ---

const toNotifyStep = () => {
  passwordError.value = null
  if (changesPassword.value) {
    if (!password.value) {
      passwordError.value = t.value('uploader.password_required')
      return
    }
    if (password.value !== passwordConfirm.value) {
      passwordError.value = t.value('uploader.password_mismatch')
      return
    }
  }
  accessStep.value = 'notify'
}

const notifyIds = computed(() => {
  if (notifyWho.value === 'all') {
    return recipients.value.map((r) => r.id)
  }
  if (notifyWho.value === 'some') {
    return recipients.value.filter((r) => chosen.value[r.id]).map((r) => r.id)
  }
  return []
})

const applyAccess = async () => {
  const changes = { new_link: changesLink.value, notify: notifyIds.value }
  if (changesPassword.value) {
    changes.password = password.value
    changes.password_confirmation = passwordConfirm.value
    changes.include_password = includePassword.value
  }
  busy.value = true
  try {
    const result = await changeShareAccess(current.value.id, changes)
    current.value = { ...current.value, ...result.share }
    changed.value = true
    const key = `settings.share.access.done_${mode.value}`
    toast.success(
      result.notified.length
        ? t.value(`${key}_notified`, { value: result.notified.length })
        : t.value(key)
    )
    resetAccess(mode.value)
  } catch (error) {
    toast.error(error.message || t.value('settings.share.access.failed'))
  } finally {
    busy.value = false
  }
}

const copyUrl = () => {
  navigator.clipboard.writeText(shareUrl.value)
  copied.value = true
  setTimeout(() => (copied.value = false), 1000)
}
</script>

<template>
  <!-- .self: content swaps between steps, so a click inside can land on an element already gone -->
  <div class="user-form-overlay" :class="{ active }" @click.self="close">
    <div class="user-form" v-if="current">
      <h2>
        {{ current.name }}
      </h2>

      <div class="manage-tabs" role="tablist">
        <button
          role="tab"
          :aria-selected="tab === 'details'"
          :class="{ active: tab === 'details' }"
          @click="tab = 'details'"
        >
          <Settings2 />
          {{ $t('settings.share.manage.details') }}
        </button>
        <button
          role="tab"
          :aria-selected="tab === 'recipients'"
          :class="{ active: tab === 'recipients' }"
          @click="tab = 'recipients'"
        >
          <Users />
          {{ $t('settings.share.manage.recipients_tab', { value: recipients.length }) }}
        </button>
        <button
          role="tab"
          :aria-selected="tab === 'access'"
          :class="{ active: tab === 'access' }"
          @click="tab = 'access'"
        >
          <KeyRound />
          {{ $t('settings.share.manage.access_tab') }}
        </button>
      </div>

      <!-- Details -->
      <template v-if="tab === 'details'">
        <div class="input-container">
          <label for="manage_share_name">{{ $t('Share Name') }}</label>
          <input
            type="text"
            id="manage_share_name"
            v-model="name"
            maxlength="255"
            :class="{ error: nameError }"
            @keyup.enter="saveDetails"
          />
          <div class="error-message" v-if="nameError">{{ nameError }}</div>
        </div>

        <div class="checkbox-container" v-if="current.password_protected">
          <input type="checkbox" id="manage_share_remove_password" v-model="removePassword" />
          <label for="manage_share_remove_password">{{ $t('settings.share.remove_password') }}</label>
        </div>

        <LockedHiddenOptions v-if="willBeProtected" v-model="lockedHidden" id-prefix="manage_share_locked_hidden" />

        <div class="button-bar">
          <button @click="saveDetails" :disabled="busy">
            <Save />
            {{ $t('settings.share.manage.save') }}
          </button>
          <button class="secondary close-button" @click="close">
            <CircleX />
            {{ $t('settings.close') }}
          </button>
        </div>
      </template>

      <!-- Recipients -->
      <template v-if="tab === 'recipients'">
        <div class="recipient-list" v-if="recipients.length">
          <ShareRecipientRow
            v-for="recipient in recipients"
            :key="recipient.id"
            :share-id="current.id"
            :recipient="recipient"
            :can-resend="!current.deleted && !current.expired"
            @updated="updateRecipients"
          />
        </div>
        <p v-else class="muted">{{ $t('settings.share.recipients.none') }}</p>

        <div class="add-recipient">
          <div class="add-recipient-fields">
            <input
              type="text"
              v-model="newRecipientName"
              :placeholder="$t('settings.users.name')"
              @input="recipientQuery = newRecipientName"
            />
            <input
              type="email"
              v-model="newRecipientEmail"
              :placeholder="$t('settings.users.email')"
              @input="recipientQuery = newRecipientEmail"
              @keyup.enter="addRecipient"
            />
          </div>
          <RecipientSuggestions
            :query="recipientQuery"
            :exclude="recipients.map((r) => r.email)"
            @pick="useSuggestion"
          />
          <div class="checkbox-container">
            <input type="checkbox" id="manage_send_to_new" v-model="sendToNew" />
            <label for="manage_send_to_new">{{ $t('settings.share.recipients.send_now') }}</label>
          </div>
          <button @click="addRecipient" :disabled="busy || current.deleted">
            <UserPlus />
            {{ $t('settings.share.recipients.add') }}
          </button>
        </div>

        <div class="button-bar">
          <button class="secondary close-button" @click="close">
            <CircleX />
            {{ $t('settings.close') }}
          </button>
        </div>
      </template>

      <!-- Link & password -->
      <template v-if="tab === 'access' && accessStep === 'choose'">
        <div class="share-link">
          <div class="share-link-label">{{ $t('Share URL') }}</div>
          <div class="share-link-row">
            <code>{{ shareUrl }}</code>
            <button class="secondary icon-only" :title="$t('settings.share.access.copy')" @click="copyUrl">
              <Check v-if="copied" />
              <Copy v-else />
            </button>
          </div>
        </div>

        <div class="mode-choices" role="radiogroup">
          <label class="mode-choice choice" :class="{ selected: mode === 'link' }">
            <input type="radio" value="link" v-model="mode" />
            <span class="radio-dot"></span>
            <span class="mode-title">{{ $t('settings.share.access.mode_link') }}</span>
            <span class="mode-help">{{ $t('settings.share.access.mode_link_help') }}</span>
          </label>
          <label class="mode-choice choice" :class="{ selected: mode === 'password' }">
            <input type="radio" value="password" v-model="mode" />
            <span class="radio-dot"></span>
            <span class="mode-title">
              {{
                current.password_protected
                  ? $t('settings.share.access.mode_password')
                  : $t('settings.share.access.mode_set_password')
              }}
            </span>
            <span class="mode-help">{{ $t('settings.share.access.mode_password_help') }}</span>
          </label>
          <label class="mode-choice choice" :class="{ selected: mode === 'both' }">
            <input type="radio" value="both" v-model="mode" />
            <span class="radio-dot"></span>
            <span class="mode-title">{{ $t('settings.share.access.mode_both') }}</span>
            <span class="mode-help">{{ $t('settings.share.access.mode_both_help') }}</span>
          </label>
        </div>

        <template v-if="changesPassword">
          <div class="input-container">
            <label for="manage_new_password">{{ $t('settings.share.manage.new_password') }}</label>
            <input type="password" id="manage_new_password" v-model="password" autocomplete="new-password" />
          </div>
          <div class="input-container">
            <label for="manage_new_password_confirm">{{ $t('settings.share.password_confirm') }}</label>
            <input
              type="password"
              id="manage_new_password_confirm"
              v-model="passwordConfirm"
              autocomplete="new-password"
              :class="{ error: passwordError }"
              @keyup.enter="toNotifyStep"
            />
            <div class="error-message" v-if="passwordError">{{ passwordError }}</div>
          </div>
        </template>

        <div class="button-bar">
          <button @click="toNotifyStep" :disabled="busy || current.deleted">
            {{ $t('settings.share.access.next') }}
          </button>
          <button class="secondary close-button" @click="close">
            <CircleX />
            {{ $t('settings.close') }}
          </button>
        </div>
      </template>

      <template v-if="tab === 'access' && accessStep === 'notify'">
        <p class="warning" v-if="changesLink">{{ $t('settings.share.access.link_warning') }}</p>
        <p class="notify-heading">{{ $t('settings.share.access.notify_heading') }}</p>

        <div class="notify-choices" role="radiogroup">
          <label class="notify-choice choice" :class="{ selected: notifyWho === 'all' }" v-if="recipients.length">
            <input type="radio" value="all" v-model="notifyWho" />
            <span class="radio-dot"></span>
            {{ $t('settings.share.access.notify_all', { value: recipients.length }) }}
          </label>
          <label class="notify-choice choice" :class="{ selected: notifyWho === 'some' }" v-if="recipients.length">
            <input type="radio" value="some" v-model="notifyWho" />
            <span class="radio-dot"></span>
            {{ $t('settings.share.access.notify_some') }}
          </label>
          <label class="notify-choice choice" :class="{ selected: notifyWho === 'none' }">
            <input type="radio" value="none" v-model="notifyWho" />
            <span class="radio-dot"></span>
            {{ $t('settings.share.access.notify_none') }}
          </label>
        </div>

        <div class="recipient-list" v-if="notifyWho === 'some'">
          <ShareRecipientRow
            v-for="recipient in recipients"
            :key="recipient.id"
            :share-id="current.id"
            :recipient="recipient"
            :selectable="true"
            :can-resend="false"
            v-model:selected="chosen[recipient.id]"
            @updated="updateRecipients"
          />
        </div>
        <p v-if="!recipients.length" class="muted">{{ $t('settings.share.recipients.none') }}</p>

        <div class="checkbox-container" v-if="changesPassword && notifyWho !== 'none'">
          <input type="checkbox" id="manage_include_password" v-model="includePassword" />
          <label for="manage_include_password">{{ $t('settings.share.access.include_password') }}</label>
        </div>

        <div class="button-bar">
          <button @click="applyAccess" :disabled="busy || (notifyWho === 'some' && !notifyIds.length)">
            <Link2 v-if="changesLink" />
            <KeyRound v-else />
            {{ $t(`settings.share.access.apply_${mode}`) }}
          </button>
          <button class="secondary close-button" @click="accessStep = 'choose'" :disabled="busy">
            <ArrowLeft />
            {{ $t('settings.share.access.back') }}
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

<style lang="scss" scoped>
.user-form-overlay {
  border-radius: 10px 10px 0 0;
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: var(--overlay-background-color);
  backdrop-filter: blur(10px);
  z-index: 230;
  opacity: 0;
  pointer-events: none;
  transition: all 0.3s ease;

  h2 {
    margin-bottom: 0;
    font-size: 22px;
    color: var(--panel-text-color);
    width: 100%;
    text-align: center;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  p {
    color: var(--panel-text-color);
    text-align: left;
    margin: 0;
  }

  .user-form {
    position: absolute;
    bottom: 0;
    left: 50%;
    transform: translate(-50%, 100%);
    width: min(520px, 100vw);
    max-height: 100vh;
    overflow-y: auto;
    background: var(--panel-background-color);
    color: var(--panel-text-color);
    padding: 20px;
    border-radius: 10px 10px 0 0;
    box-shadow: 0 0 100px 0 rgba(0, 0, 0, 0.5);
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: flex-start;
    gap: 12px;
    transition: all 0.3s ease;
  }

  &.active {
    opacity: 1;
    pointer-events: auto;
    .user-form {
      transform: translate(-50%, 0%);
    }
  }
}

.manage-tabs {
  width: 100%;
  display: flex;
  gap: 4px;
  padding: 4px;
  background: var(--panel-section-background-color);
  border-radius: 6px;
  button {
    flex: 1;
    height: 36px;
    padding: 0 8px;
    gap: 6px;
    font-size: 0.85rem;
    background: transparent;
    color: var(--panel-text-color);
    white-space: nowrap;
    svg {
      width: 16px;
      height: 16px;
      margin: 0;
    }
    &:hover {
      background: var(--panel-section-background-color-alt);
      color: var(--panel-text-color);
    }
    &.active {
      background: var(--panel-background-color);
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
    }
  }
}

.input-container {
  width: 100%;
}

// The shared style keeps a second grid row for help text these boxes don't have
.checkbox-container {
  grid-template-rows: auto;
}

.button-bar {
  width: 100%;
  display: flex;
  gap: 10px;
  button {
    flex: 1;
  }
}

.recipient-list {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.muted {
  opacity: 0.7;
}

.add-recipient {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 10px;
  border: 1px dashed var(--panel-section-background-color-alt);
  border-radius: 5px;
  .add-recipient-fields {
    display: flex;
    gap: 8px;
    input {
      flex: 1;
      min-width: 0;
      margin: 0;
    }
  }
  button {
    width: 100%;
  }
}

.share-link {
  width: 100%;
  background: var(--panel-section-background-color);
  border-radius: 5px;
  padding: 10px;
  .share-link-label {
    font-size: 0.8rem;
    font-weight: bold;
    margin-bottom: 4px;
  }
  .share-link-row {
    display: flex;
    align-items: center;
    gap: 8px;
    code {
      flex: 1;
      min-width: 0;
      color: var(--panel-text-color);
      word-break: break-all;
    }
    button {
      flex-shrink: 0;
    }
  }
}

.mode-choices,
.notify-choices {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

// Radio cards: the native radio stays for keyboard and screen readers, drawn as .radio-dot
.choice {
  position: relative;
  display: grid;
  grid-template-columns: 18px 1fr;
  column-gap: 10px;
  align-items: center;
  padding: 10px;
  border-radius: 5px;
  background: var(--panel-section-background-color);
  border: 2px solid transparent;
  cursor: pointer;
  color: var(--panel-text-color);
  input {
    position: absolute;
    opacity: 0;
    width: 1px;
    height: 1px;
    margin: 0;
    pointer-events: none;
  }
  .radio-dot {
    grid-row: span 2;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: var(--input-background-color);
    border: 1px solid var(--input-border-color);
    position: relative;
  }
  &.selected {
    border-color: var(--input-border-color-focus);
    .radio-dot::after {
      content: '';
      position: absolute;
      inset: 3px;
      border-radius: 50%;
      background: var(--checkbox-checked-background-color);
    }
  }
  &:focus-within {
    outline: 2px solid var(--input-border-color-focus);
    outline-offset: 1px;
  }
  .mode-title {
    font-weight: bold;
    font-size: 0.9rem;
  }
  .mode-help {
    grid-column: 2;
    font-size: 0.75rem;
    opacity: 0.7;
  }
}

.notify-choice {
  padding: 8px 10px;
  .radio-dot {
    grid-row: auto;
  }
}

.warning {
  width: 100%;
  padding: 10px;
  border-radius: 5px;
  background: var(--panel-section-background-color);
  font-size: 0.85rem;
}

.notify-heading {
  font-weight: bold;
}
</style>
