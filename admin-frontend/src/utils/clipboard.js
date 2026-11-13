// Puts text on the clipboard: the clipboard API, and for http pages / old browsers a hidden field and the old copy command. True when it worked.
export async function copyText(value) {
  const text = String(value ?? '')
  if (!text) return false
  try {
    await navigator.clipboard.writeText(text)
    return true
  } catch {
    const field = document.createElement('textarea')
    field.value = text
    field.style.cssText = 'position:fixed;left:-9999px;top:0;opacity:0'
    document.body.appendChild(field)
    field.select()
    let ok = false
    try { ok = document.execCommand('copy') } catch { ok = false }
    field.remove()
    return ok
  }
}
