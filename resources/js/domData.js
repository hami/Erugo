export const domData = () => {
  const data = {
    version: document.body.getAttribute('data-version'),
    ...getSettings()
  }
  return data
}

export const domError = () => {
  const error = document.body.getAttribute('data-error')
  return error || ''
}

export const domSuccess = () => {
  const success = document.body.getAttribute('data-success')
  return success || ''
}

const getSettings = () => {
  const body = document.body
  if(!body) {
    return {}
  }
  const settings = JSON.parse(body.getAttribute('data-settings'))
  return settings
}
// The details a password-protected share hides while locked, as the system defaults set them
export const LOCKED_HIDEABLE = ['files', 'stats', 'message', 'sender', 'name']

export const defaultLockedHidden = () => {
  const data = domData()
  return LOCKED_HIDEABLE.filter((detail) => data[`locked_hidden_default_${detail}`] === true)
}
