// The panel's own drop-down list and calendar, in place of the browser's pop-ups (they are drawn by the browser: too wide, in the wrong place, never
// in the dark theme). Added to every `select.select` and every `input[type=date]` of the panel without touching a single screen: the real element stays
// in the page, keeps its value and still fires `input` / `change`, so v-model, the tests and the keyboard keep working. A phone gets the same list.
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
const DAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su']
const SELECT = 'select.select:not([multiple]):not(:disabled)'
const DATE = 'input[type="date"]:not(:disabled):not([readonly])'

let current = null      // { el, owner, off }
let openedAt = 0

const make = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e }
const two = (n) => String(n).padStart(2, '0')
const iso = (d) => `${d.getFullYear()}-${two(d.getMonth() + 1)}-${two(d.getDate())}`
function parse(v) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v || '')
  return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : null
}

export function closePopover() {
  if (!current) return
  current.off()
  current.el.remove()
  current = null
}

/** Put the pop-up under (or, when there is no room, over) the field, inside the screen. */
function place(pop, anchor, minWidth) {
  const r = anchor.getBoundingClientRect()
  const vw = document.documentElement.clientWidth
  const vh = window.innerHeight
  pop.style.visibility = 'hidden'
  pop.style.minWidth = `${Math.min(Math.max(r.width, minWidth || 0), vw - 16)}px`
  document.body.appendChild(pop)
  const below = vh - r.bottom - 12
  const above = r.top - 12
  const h = pop.scrollHeight + 14
  const down = below >= Math.min(h, 260) || below >= above
  pop.style.maxHeight = `${Math.max(140, Math.min(360, down ? below : above))}px`
  const w = pop.offsetWidth
  const hh = pop.offsetHeight
  pop.style.left = `${Math.min(Math.max(8, r.left), Math.max(8, vw - w - 8))}px`
  pop.style.top = `${down ? r.bottom + 6 : Math.max(8, r.top - 6 - hh)}px`
  pop.style.visibility = ''
}

function track(pop, owner, extra) {
  const outside = (e) => { if (!pop.contains(e.target) && e.target !== owner) closePopover() }
  const away = (e) => { if (!pop.contains(e.target)) closePopover() }   // any scrolling behind the pop-up closes it
  document.addEventListener('mousedown', outside, true)
  window.addEventListener('scroll', away, true)
  window.addEventListener('resize', closePopover)
  pop.addEventListener('mousedown', (e) => e.preventDefault())          // the field keeps the focus
  current = { el: pop, owner, off: () => { document.removeEventListener('mousedown', outside, true); window.removeEventListener('scroll', away, true); window.removeEventListener('resize', closePopover); extra?.() } }
  openedAt = Date.now()
}

function fire(el) {
  el.dispatchEvent(new Event('input', { bubbles: true }))
  el.dispatchEvent(new Event('change', { bubbles: true }))
}

// ---------------------------------------------------------------------------------------------------------------- the list
function openSelect(sel) {
  if (current?.owner === sel) return closePopover()
  closePopover()
  const pop = make('div', 'pop pop--list')
  pop.setAttribute('role', 'listbox')
  pop.dataset.for = sel.getAttribute('aria-label') || sel.id || ''
  const buttons = [...sel.options].map((o) => {
    const b = make('button', 'pop__opt', o.textContent.trim() || ' ')
    b.type = 'button'
    b.setAttribute('role', 'option')
    b.dataset.value = o.value
    if (o.disabled) b.disabled = true
    if (o.selected) { b.classList.add('is-selected'); b.setAttribute('aria-selected', 'true') }
    pop.appendChild(b)
    return b
  })
  let active = Math.max(0, sel.selectedIndex)
  const mark = () => buttons.forEach((b, i) => b.classList.toggle('is-active', i === active))
  const pick = (i) => {
    const b = buttons[i]
    if (!b || b.disabled) return
    closePopover()
    if (sel.value !== b.dataset.value) { sel.value = b.dataset.value; fire(sel) }
    sel.focus({ preventScroll: true })
  }
  pop.addEventListener('click', (e) => { const b = e.target.closest('.pop__opt'); if (b) pick(buttons.indexOf(b)) })
  const key = (e) => {
    if (e.key === 'Escape') { e.preventDefault(); closePopover(); sel.focus({ preventScroll: true }) }
    else if (e.key === 'Tab') closePopover()
    else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault()
      const step = e.key === 'ArrowDown' ? 1 : -1
      for (let i = active + step; i >= 0 && i < buttons.length; i += step) if (!buttons[i].disabled) { active = i; break }
      mark(); buttons[active]?.scrollIntoView({ block: 'nearest' })
    } else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(active) }
    else if (e.key === 'Home' || e.key === 'End') { e.preventDefault(); active = e.key === 'Home' ? 0 : buttons.length - 1; mark(); buttons[active]?.scrollIntoView({ block: 'nearest' }) }
  }
  document.addEventListener('keydown', key, true)
  place(pop, sel, 180)
  track(pop, sel, () => document.removeEventListener('keydown', key, true))
  mark()
  buttons[active]?.scrollIntoView({ block: 'nearest' })
}

// ---------------------------------------------------------------------------------------------------------------- the calendar
function openDate(input) {
  if (current?.owner === input) return closePopover()
  closePopover()
  const min = parse(input.min)
  const max = parse(input.max)
  const chosen = parse(input.value)
  const today = new Date()
  let view = new Date((chosen || today).getFullYear(), (chosen || today).getMonth(), 1)
  const pop = make('div', 'pop pop--cal')
  pop.setAttribute('role', 'dialog')
  pop.setAttribute('aria-label', 'Choose a date')

  const pickDay = (value) => { closePopover(); if (input.value !== value) { input.value = value; fire(input) } input.focus({ preventScroll: true }) }
  const render = () => {
    pop.replaceChildren()
    const head = make('div', 'cal__head')
    const nav = (label, text, years, dir) => {
      const b = make('button', 'cal__nav', text)
      b.type = 'button'
      b.setAttribute('aria-label', label)
      b.addEventListener('click', () => { view = new Date(view.getFullYear() + (years ? dir : 0), view.getMonth() + (years ? 0 : dir), 1); render() })
      return b
    }
    head.append(nav('Previous year', '«', true, -1), nav('Previous month', '‹', false, -1), make('strong', 'cal__title', `${MONTHS[view.getMonth()]} ${view.getFullYear()}`), nav('Next month', '›', false, 1), nav('Next year', '»', true, 1))
    const week = make('div', 'cal__week')
    DAYS.forEach((d) => week.appendChild(make('span', '', d)))
    const grid = make('div', 'cal__grid')
    const lead = (view.getDay() + 6) % 7                      // Monday first
    const start = new Date(view.getFullYear(), view.getMonth(), 1 - lead)
    for (let i = 0; i < 42; i++) {
      const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i)
      const b = make('button', 'cal__day', String(d.getDate()))
      b.type = 'button'
      const value = iso(d)
      if (d.getMonth() !== view.getMonth()) b.classList.add('is-out')
      if (value === iso(today)) b.classList.add('is-today')
      if (chosen && value === iso(chosen)) b.classList.add('is-sel')
      if ((min && d < min) || (max && d > max)) b.disabled = true
      b.addEventListener('click', () => pickDay(value))
      grid.appendChild(b)
    }
    const foot = make('div', 'cal__foot')
    const clear = make('button', 'cal__link', 'Clear')
    clear.type = 'button'
    clear.addEventListener('click', () => pickDay(''))
    const now = make('button', 'cal__link', 'Today')
    now.type = 'button'
    now.disabled = (min && today < min) || (max && today > max) || false
    now.addEventListener('click', () => pickDay(iso(today)))
    foot.append(clear, now)
    pop.append(head, week, grid, foot)
  }
  render()
  const key = (e) => { if (e.key === 'Escape' || e.key === 'Tab') { closePopover(); if (e.key === 'Escape') { e.preventDefault(); input.focus({ preventScroll: true }) } } }
  document.addEventListener('keydown', key, true)
  place(pop, input, 276)
  track(pop, input, () => document.removeEventListener('keydown', key, true))
}

// ---------------------------------------------------------------------------------------------------------------- the hooks
function open(target) { if (target.matches(SELECT)) openSelect(target); else openDate(target) }
const mine = (e) => (e.target instanceof Element ? e.target.closest(`${SELECT}, ${DATE}`) : null)

export function installPopovers() {
  if (window.__popovers) return
  window.__popovers = true
  document.addEventListener('mousedown', (e) => {
    if (e.button !== 0) return
    const t = mine(e)
    if (!t) return
    e.preventDefault()                     // no pop-up of the browser, no stray text selection
    t.focus({ preventScroll: true })
    open(t)
  }, true)
  document.addEventListener('click', (e) => {   // a label forwards a click to its field without a mouse-down; a touch gives both
    const t = mine(e)
    if (!t) return
    e.preventDefault()
    if (Date.now() - openedAt > 400) open(t)
  }, true)
  document.addEventListener('keydown', (e) => {
    if (current) return
    const t = e.target instanceof Element ? e.target.closest(`${SELECT}, ${DATE}`) : null
    if (!t) return
    const wants = t.matches(SELECT) ? (e.key === ' ' || e.key === 'Enter' || (e.key === 'ArrowDown' && e.altKey)) : (e.key === 'ArrowDown' && e.altKey) || e.key === 'F4'
    if (wants) { e.preventDefault(); open(t) }
  }, true)
}
