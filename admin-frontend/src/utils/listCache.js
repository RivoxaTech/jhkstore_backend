// The last answer of each list screen (orders, products, wholesale), kept in memory for this visit: coming back to a screen shows its list at once
// and the fresh answer replaces it a moment later (no skeleton, no waiting). Never persisted: a reload or a logout starts empty.
const store = new Map()

export const cachedList = (key) => store.get(key)

export function rememberList(key, value) {
  store.set(key, value)
  if (store.size > 40) store.delete(store.keys().next().value)
}

export const forgetLists = () => store.clear()
