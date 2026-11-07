<script setup>
import { ref, onMounted, inject, defineExpose } from 'vue'
import { getMyShares, expireShare, deleteShare, extendShare, setDownloadLimit, pruneExpiredShares } from '../../api'
import {
  SquareArrowOutUpRight,
  CalendarPlus,
  CalendarX2,
  HardDriveDownload,
  MessageCircleQuestion,
  Rocket,
  Lock,
  LockOpen,
  ArrowLeftRight,
  Pencil,
  Users,
  Link2,
  KeyRound,
  Trash2
} from 'lucide-vue-next'
import { useToast } from 'vue-toastification'
import { niceFileSize, niceDate, niceFileName, niceNumber } from '../../utils'
import HelpTip from '../helpTip.vue'
import ShareManager from './shareManager.vue'
import { useTranslate } from '@tolgee/vue'
import { store } from '../../store'
import { domData } from '../../domData'

const { t } = useTranslate()

const showHelpTip = inject('showHelpTip')
const hideHelpTip = inject('hideHelpTip')

const toast = useToast()
const maxFilesToShow = 4
const loadedShares = ref(false)

const shares = ref([])
const showDeletedShares = ref(false)
onMounted(async () => {
  showDeletedShares.value = localStorage.getItem('showDeletedShares') === 'true'
  loadShares()
})

const loadShares = async () => {
  shares.value = await getMyShares(showDeletedShares.value)
  loadedShares.value = true
}

const handleExpireShareClick = async (share) => {
  expireShare(share.id)
    .then(() => {
      toast.success(t.value('settings.success.shareExpired'))
      loadShares()
    })
    .catch((error) => {
      toast.error(t.value('settings.error.shareExpired'))
    })
}

// Admins can always delete; owners only while the system allows it
const canDelete = store.isAdmin() || domData().owner_share_delete_enabled !== false

const handleDeleteShareClick = async (share) => {
  if (!confirm(t.value('settings.confirm.deleteShare', { name: share.name }))) {
    return
  }
  try {
    await deleteShare(share.id)
    toast.success(t.value('settings.success.shareDeleted'))
    loadShares()
  } catch (error) {
    toast.error(t.value('settings.error.shareDeleted'))
  }
}

const handleExtendShareClick = async (share) => {
  extendShare(share.id)
    .then(() => {
      toast.success(t.value('settings.success.shareExtended'))
      loadShares()
    })
    .catch((error) => {
      toast.error(t.value('settings.error.shareExtended'))
    })
}

const handleDownloadLimitChange = async (share) => {
  let newLimit = null
  if (share.download_limit == '' || share.download_limit == null) {
    newLimit = -1
  } else {
    newLimit = parseInt(share.download_limit)
  }

  if (isNaN(newLimit)) {
    return
  }
  setDownloadLimit(share.id, newLimit)
    .then(() => {
      toast.success('Download limit changed')
      loadShares()
    })
    .catch((error) => {
      toast.error('Failed to change download limit')
    })
}

// The share open in the Manage dialog, and where to open it
const managedShare = ref(null)
const manageSection = ref('details')
const manageAccessMode = ref('link')

const manageShare = (share, section, accessMode = 'link') => {
  manageSection.value = section
  manageAccessMode.value = accessMode
  managedShare.value = share
}

const handleManageClose = (changed) => {
  managedShare.value = null
  if (changed) {
    loadShares()
  }
}

const downloadShare = async (share) => {
  window.location.href = `/api/shares/${share.long_id}/download`
}

const enableExpireShareButton = (share) => {
  return !share.expired && !share.deleted
}

const enableExtendShareButton = (share) => {
  return !share.deleted
}

const enableDownloadButton = (share) => {
  return !share.expired && !share.deleted
}

const handlePruneExpiredShares = async () => {
  const confirmed = confirm(t.value('settings.confirm.pruneExpiredShares'))
  if (!confirmed) {
    return
  }
  try {
    await pruneExpiredShares()
    toast.success(t.value('settings.success.pruneExpiredShares'))
    loadShares()
  } catch (error) {
    toast.error(t.value('settings.error.pruneExpiredShares'))
  }
}

const setShowDeletedShares = (value) => {
  showDeletedShares.value = value
  loadShares()
}

defineExpose({
  handlePruneExpiredShares,
  setShowDeletedShares
})
</script>

<template>
  <div>
    <HelpTip id="download-limit-help-tip" :header="$t('settings.help.downloadLimit.title')">
      <p>
        {{ $t('settings.help.downloadLimit.description') }}
      </p>
      <p>
        {{ $t('settings.help.downloadLimit.description2') }}
      </p>
    </HelpTip>
    <table v-if="shares.length > 0">
      <thead>
        <tr>
          <th>{{ $t('settings.table.name') }}</th>
          <th>{{ $t('settings.table.files') }}</th>
          <th>
            {{ $t('settings.table.downloads') }}
            <MessageCircleQuestion @click.stop="showHelpTip($event, '#download-limit-help-tip')" />
          </th>
          <th>{{ $t('settings.table.dates') }}</th>
          <th>{{ $t('settings.table.actions') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="share in shares" :key="share.id" :class="{ 'reverse-share': share.shared_with_me }">
          <td width="1" style="white-space: nowrap">
            <div class="slide-text">
              <strong class="content">
                <ArrowLeftRight v-if="share.shared_with_me" class="reverse-share-icon" />
                {{ share.name }}
              </strong>
            </div>
            <a :href="`/shares/${share.long_id}`" target="_blank" class="share_long_id">
              <SquareArrowOutUpRight />
              {{ share.long_id }}
            </a>
            <div class="protection-status">
              <template v-if="share.password_protected">
                <Lock />
                {{ $t('share.passwordProtected') }}
              </template>
              <template v-else>
                <LockOpen />
                {{ $t('share.passwordNotProtected') }}
              </template>
            </div>
          </td>
          <td style="vertical-align: top">
            <h6 class="file-count">
              {{ $t('share.files.count', { count: share.files.length, value: share.files.length }) }}
              <template v-if="share.files.length > maxFilesToShow">
                {{ $t('share.files.including') }}
              </template>
            </h6>
            <div class="files-container pt-1">
              <div class="file" v-for="file in share.files.slice(0, maxFilesToShow)" :key="file.id">
                <div class="file-name" :title="file.name">{{ niceFileName(file.name) }}</div>
                <div class="file-size">
                  {{ niceFileSize(file.size) }}
                </div>
              </div>
              <div class="some-more" v-if="share.files.length > maxFilesToShow">
                <span>And {{ share.files.length - maxFilesToShow }} more</span>
              </div>
            </div>
          </td>
          <td width="1" style="white-space: nowrap" class="text-center">
            <div class="download_limit_manager">
              <div class="limit-label">{{ $t('limit') }}</div>
              <div class="download_count">
                <label class="count_label">{{ $t('settings.table.downloads') }}</label>
                {{ niceNumber(share.download_count) }}
                <span>/</span>
              </div>
              <input
                class="download_limit_input"
                v-model="share.download_limit"
                @change="handleDownloadLimitChange(share)"
                placeholder="∞"
              />
            </div>
          </td>
          <td width="1" style="white-space: nowrap">
            <div class="date-container">
              <div class="date">
                <span>{{ $t('share.created') }}:</span>
                {{ niceDate(share.created_at) }}
              </div>
              <div class="date">
                <span>{{ $t('share.expires') }}:</span>
                <template v-if="share.expired">
                  <strong class="ps-1 text-danger">{{ $t('share.expired') }}</strong>
                </template>
                <template v-else>
                  {{ niceDate(share.expires_at) }}
                </template>
              </div>
              <div class="date">
                <span>{{ $t('share.deletes') }}:</span>
                <template v-if="share.deleted">
                  <strong class="ps-1 text-danger">{{ $t('share.deleted') }}</strong>
                </template>
                <template v-else>
                  {{ niceDate(share.deletes_at) }}
                </template>
              </div>
            </div>
          </td>
          <td width="1" style="white-space: nowrap">
            <div class="manage-buttons">
              <button
                class="secondary icon-only recipients-button"
                :title="$t('settings.share.manage.recipients_title')"
                :disabled="share.deleted"
                @click="manageShare(share, 'recipients')"
              >
                <Users />
                <span class="count" v-if="share.recipients && share.recipients.length">{{ share.recipients.length }}</span>
              </button>
              <button
                class="secondary icon-only"
                :title="$t('settings.share.access.mode_link')"
                :disabled="share.deleted"
                @click="manageShare(share, 'access', 'link')"
              >
                <Link2 />
              </button>
              <button
                class="secondary icon-only"
                :title="$t('settings.share.access.mode_password')"
                :disabled="share.deleted"
                @click="manageShare(share, 'access', 'password')"
              >
                <KeyRound />
              </button>
              <button
                class="secondary icon-only"
                :title="$t('settings.share.manage.details_title')"
                :disabled="share.deleted"
                @click="manageShare(share, 'details')"
              >
                <Pencil />
              </button>
              <button
                @click="downloadShare(share)"
                class="secondary icon-only"
                title="Download all files"
                :disabled="!enableDownloadButton(share)"
              >
                <HardDriveDownload />
              </button>
              <button
                class="secondary icon-only delete-share-button"
                v-if="canDelete"
                :title="$t('share.button.delete')"
                :disabled="share.deleted"
                @click="handleDeleteShareClick(share)"
              >
                <Trash2 />
              </button>
            </div>
            <div class="date-buttons">
              <button
                @click="handleExpireShareClick(share)"
                class="clear-button"
                :disabled="!enableExpireShareButton(share)"
              >
                <CalendarX2 />
                {{ $t('share.button.expireNow') }}
              </button>
              <button
                @click="handleExtendShareClick(share)"
                class="secondary"
                :disabled="!enableExtendShareButton(share)"
              >
                <CalendarPlus />
                {{ $t('share.button.extend') }}
              </button>
            </div>
          </td>
        </tr>
      </tbody>
    </table>
    <div v-else-if="loadedShares" class="center-message">
      <Rocket />
      <p>{{ $t('settings.noShares') }}</p>
    </div>
    <div v-else class="center-message">
      <p>{{ $t('settings.loading') }}</p>
    </div>
    <ShareManager
      :share="managedShare"
      :section="manageSection"
      :access-mode="manageAccessMode"
      @close="handleManageClose"
    />
  </div>
</template>

<style lang="scss" scoped>
.manage-buttons,
.date-buttons {
  display: flex;
  gap: 5px;
}

// Expire Now and Extend share the row's width evenly
.date-buttons button {
  flex: 1;
  margin: 0;
}

.manage-buttons {
  margin-bottom: 5px;
  button {
    flex: 1;
  }
  .recipients-button {
    position: relative;
    .count {
      position: absolute;
      top: 2px;
      right: 2px;
      min-width: 16px;
      height: 16px;
      padding: 0 4px;
      border-radius: 8px;
      font-size: 0.65rem;
      line-height: 16px;
      text-align: center;
      background: var(--primary-button-background-color);
      color: var(--primary-button-text-color);
    }
  }
}

.files-container {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 10px;
  .file {
    display: flex;
    flex-direction: column;
    background: var(--panel-section-background-color-alt);
    border-radius: 5px;
    padding: 5px 10px;
    gap: 1px;
    .file-name {
      font-size: 0.85rem;
      font-weight: bold;
      color: var(--panel-section-text-color);
    }
    .file-size {
      font-size: 0.7rem;
      color: var(--panel-section-text-color);
    }
  }
  .some-more {
    font-size: 0.7rem;
    color: var(--panel-section-text-color);
    margin-left: 10px;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
  }
}

.date-container {
  display: flex;
  flex-direction: column;
  gap: 5px;
  .date {
    background: var(--panel-section-background-color);
    border-radius: 5px;
    padding: 5px 10px;
    gap: 5px;
    span {
      display: inline-block;
      font-weight: bold;
      background: var(--panel-section-background-color-alt);
      border-radius: 5px;
      padding: 5px 10px;
      margin-left: -10px;
      margin-bottom: -5px;
      margin-top: -5px;
      height: calc(100% + 10px);
      min-width: 100px;
      margin-right: 10px;
    }
  }
}

.share_long_id {
  display: block;
  font-size: 1rem;
  color: var(--panel-section-text-color);
  text-decoration: none;
  font-weight: bold;

  svg {
    width: 1rem;
    height: 1rem;
    margin-right: 5px;
    margin-top: -2px;
    color: var(--panel-section-text-color);
  }
}

.file-count {
  background: var(--panel-section-background-color-alt);
  margin-left: -10px;
  margin-top: -10px;
  margin-right: -10px;
  padding: 5px 10px;

  color: var(--panel-section-text-color-alt);
  font-weight: 500;
}

td {
  a {
    color: var(--panel-section-text-color);
    text-decoration: none;
    cursor: pointer;
    font-size: 0.75rem;
    margin-top: 10px;
    display: block;
    &:hover {
      text-decoration: underline;
    }
  }
}

.download_limit_manager {
  position: relative;
  --height: 40px;
  display: flex;
  flex-direction: row;
  align-items: center;
  background: var(--panel-section-background-color-alt);
  height: var(--height);
  border-radius: 5px;
  .limit-label {
    position: absolute;
    left: 90px;
    width: 90px;
    top: 0;
    bottom: 0;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    opacity: 0.3;
    font-size: 0.5rem;
    font-weight: normal;
    padding-bottom: 1.5px;
    color: var(--panel-section-text-color);
    z-index: 1;
    pointer-events: none;
  }
  .download_count {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    padding-left: 10px;
    padding-right: 10px;
    border-radius: 3px;
    border: none;
    color: var(--panel-section-text-color);
    outline: none;
    height: var(--height);
    background: var(--panel-section-background-color-alt);
    font-weight: bold;
    width: 90px;
    padding-bottom: 6px !important;
    span {
      position: absolute;
      left: 86.5px;
      opacity: 0.3;
      z-index: 10;
    }
    .count_label {
      position: absolute;
      left: 0;
      right: 0;
      top: 0;
      bottom: 0;
      display: flex;
      align-items: flex-end;
      justify-content: center;
      opacity: 0.3;
      font-size: 0.5rem;
      font-weight: normal;
      padding-bottom: 1.5px;
    }
  }
  .download_limit_input {
    position: relative !important;
    background: var(--panel-section-background-color-alt);
    height: var(--height);
    border: none;
    border-radius: 0 3px 3px 0;
    text-align: center;
    margin: 0;
    width: 90px;
    padding-bottom: 16px !important;
    &:focus {
      outline: none;
    }
  }
}
.center-message {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  width: 100%;
  min-height: 300px;
  font-size: 1.5rem;
  color: var(--panel-section-text-color);
  svg {
    width: 4rem;
    height: 4rem;
    margin-right: 10px;
    margin-top: -20px;
  }
}

.protection-status {
  margin-top: 10px;
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 5px;
  font-size: 0.6rem;
  color: var(--panel-section-text-color);
  svg {
    width: 1rem;
    height: 1rem;
    margin-top: -2px;
  }
}

.reverse-share {
  background: var(--panel-section-background-color-alt);
  border-radius: 5px;
  padding: 5px 10px;
  gap: 5px;
}

.reverse-share-icon {
  width: 1rem;
  height: 1rem;
  margin-right: 5px;
  vertical-align: middle;
  opacity: 0.7;
}
</style>
