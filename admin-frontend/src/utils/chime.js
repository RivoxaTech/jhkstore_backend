import chimeUrl from '@/assets/chime.wav'

// The in-panel alert sound: one short bundled file (no request to another site). Browsers only let a page play sound after the visitor has clicked
// or pressed a key on it, so `unlock()` runs on the first such gesture; until then `play()` does nothing and the panel shows a one-line hint.
// A site cannot change the sound of a push notification (the system plays its own); this chime is for the open panel.
let audio = null
let lastPlayed = 0
const MIN_GAP_MS = 4000   // a push message and the badge poll can announce the same order: one chime

const element = () => {
  if (!audio) {
    audio = new Audio(chimeUrl)
    audio.preload = 'auto'
  }
  return audio
}

/** Called on the first click or key press: plays the file silently once so the browser allows later sounds. Resolves true when sound is allowed. */
export async function unlock() {
  try {
    const a = element()
    a.muted = true
    await a.play()
    a.pause()
    a.currentTime = 0
    a.muted = false
    return true
  } catch {
    return false
  }
}

/** Plays the chime. Resolves false when the browser refused (no click on the page yet) or when one just played. */
export async function play() {
  const now = Date.now()
  if (now - lastPlayed < MIN_GAP_MS) return false
  try {
    const a = element()
    a.muted = false
    a.currentTime = 0
    await a.play()
    lastPlayed = now
    return true
  } catch {
    return false
  }
}
