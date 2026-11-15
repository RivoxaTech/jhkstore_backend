// The help videos, one flow each. `r.step(...)` writes the banner across the top of the video AND the numbered step shown under it on the Help screen.
// A flow is plain browser driving: r.click, r.type, r.choose (the panel's drop-down lists), r.upload, r.ring. Keep a video to one idea and about a minute.
import { sleep } from './lib.mjs'

const TOP_NOTE = 'Only Active products show on the website'

export const FLOWS = [
  // ------------------------------------------------------------------------------------------------------------------------------ products
  {
    slug: 'add-product',
    topic: 'Products',
    title: 'Add a product',
    summary: 'A new toy from the first field to the website: category, name, price, stock, pictures and the Active status.',
    can: 'products.manage',
    as: 'developer',
    open: { route: 'product-new', text: 'Add a product' },
    start: 'products',
    async run(r, ctx) {
      await r.title('Add a product', 'About one minute')
      await r.step('Open Products and press Add product')
      await r.click({ text: 'Add product', in: 'a.btn' })
      await r.page.waitForSelector('#category')
      await r.step('Choose the category, and the sub-category if there is one')
      await r.choose('#category', 'Building & Blocks')
      await r.choose('#subcategory', 'Wooden Blocks')
      await r.step('Type the product name. The SKU is made for you')
      await r.type('#name', 'Wooden Train Set')
      await r.hold(700)
      await r.step('Write a short description and the full description')
      await r.type('#short', 'A colourful wooden train with three carriages.')
      await r.type('#desc', 'Smooth, painted wood and rounded edges. Easy for small hands to push, link and load.')
      await r.step(`Set the status to Active. ${TOP_NOTE}`)
      await r.choose('#status', 'Active')
      await r.step('Under Options and pricing type the old price, the price and the stock')
      await r.type('#variants-0-mrp', '35', { clear: true })
      await r.type('#variants-0-price', '28', { clear: true })
      await r.type('#variants-0-stock', '40', { clear: true })
      await r.step('Add pictures. The first one is the main picture')
      await (await r.locate('#images')).evaluate((e) => e.scrollIntoView({ block: 'start', behavior: 'smooth' }))
      await sleep(700)
      await r.upload('#images input[type="file"]', ctx.pictures, { pause: 1800 })
      await r.step('Press Add product to save')
      await r.click('[data-testid="save-product"]', { pause: 2500 })
      await r.page.waitForFunction(() => location.pathname.endsWith('/products'), { timeout: 20000 }).catch(() => {})
      await r.step('Done. The product is in the list. The website shows it after the next rebuild, 1 to 3 minutes', { pause: 3200 })
    },
  },

  {
    slug: 'edit-product',
    topic: 'Products',
    title: 'Edit a product',
    summary: 'Find a product, change its status from the list, then change its price and stock, and add another option.',
    can: 'products.manage',
    as: 'developer',
    open: { route: 'products', text: 'Open Products' },
    start: 'products',
    async run(r) {
      await r.title('Edit a product', 'Price, stock, status and options')
      await r.step('Find the product with the search box')
      await r.type('.toolbar input[type="search"]', 'Wooden Train', { pause: 1400 })
      await r.step('Change the status right in the list. Inactive hides the product, Active shows it')
      await r.choose('tbody tr:first-child select.status-select', 'Inactive', { pause: 1100 })
      await r.choose('tbody tr:first-child select.status-select', 'Active', { pause: 900 })
      await r.step('Press the pencil to open the product')
      await r.click('tbody tr:first-child a[aria-label="Edit"]', { pause: 1200 })
      await r.page.waitForSelector('#options', { timeout: 20000 })
      await r.step('Go to Options and pricing. Open it if it is closed')
      const head = await r.locate('#options > .card__head')
      if ((await head.evaluate((e) => e.getAttribute('aria-expanded'))) === 'false') await r.click('#options > .card__head', { pause: 900 })
      else { await r.reveal(head); await r.ring(head, 1000); await r.page.evaluate(() => window.__hvRing(null)) }
      await r.page.waitForSelector('#variants-0-price', { visible: true })
      await r.step('Change the price. The old price has to be higher than the price')
      await r.type('#variants-0-price', '25', { clear: true })
      await r.step('Change the stock when you receive or sell toys')
      await r.type('#variants-0-stock', '55', { clear: true })
      await r.step('Need a second size or colour? Press Add option and fill it in')
      await r.click('[data-testid="add-option"]', { pause: 700 })
      await r.type('#variants-1-name', 'Big set with 6 carriages')
      await r.type('#variants-1-mrp', '60', { clear: true })
      await r.type('#variants-1-price', '49', { clear: true })
      await r.type('#variants-1-stock', '15', { clear: true })
      await r.step('Press Save product')
      await r.click('[data-testid="save-product"]', { pause: 2200 })
      await r.step('Saved. Customers pay the price shown in the cart, and it is live at once on the product page', { pause: 3000 })
    },
  },

  // ------------------------------------------------------------------------------------------------------------------------------ categories
  {
    slug: 'categories',
    topic: 'Categories',
    title: 'Categories and sub-categories',
    summary: 'Add a category, then a sub-category inside it. Products are placed in them when you add or edit a product.',
    can: 'categories.manage',
    as: 'developer',
    open: { route: 'categories', text: 'Open Categories' },
    start: 'categories',
    async run(r) {
      await r.title('Categories and sub-categories', 'Organise your products')
      await r.step('Open Categories and press Add category')
      await r.click({ text: 'Add category', in: '.page-actions button' })
      await r.page.waitForSelector('#cname', { visible: true })
      await r.step('Type the name of the category')
      await r.type('#cname', 'Wooden Toys')
      await r.step('Active shows it on the website. Top puts it on the home page (the list shows how many fit)')
      await sleep(600)   // the window has finished opening
      const top = await r.locate('label.switch:has([data-field="is_top"])')
      await r.ring(top, 2200)
      await r.page.evaluate(() => window.__hvRing(null))
      await r.step('Press Save')
      await r.click('button[form="category-form"]', { pause: 1800 })
      await r.step('To add a sub-category, press the + next to the category')
      await r.click('button[aria-label="Add sub-category to Wooden Toys"]', { pause: 900 })
      await r.page.waitForSelector('#cname', { visible: true })
      await r.step('Type the name of the sub-category and press Save')
      await r.type('#cname', 'Trains and Cars')
      await r.click('button[form="category-form"]', { pause: 1800 })
      await r.step('Done. Use the pencil to rename a category, or the switch to hide it from the website', { pause: 3000 })
    },
  },

  // ------------------------------------------------------------------------------------------------------------------------------ discount
  {
    slug: 'add-discount',
    topic: 'Discounts',
    title: 'Add a discount',
    summary: 'A discount lowers prices by itself, with no code. Choose how much, on what, and for how long.',
    can: 'discounts.manage',
    as: 'admin',
    open: { route: 'discounts', text: 'Open Discounts' },
    start: 'discounts',
    async run(r) {
      await r.title('Add a discount', 'Lower prices without a code')
      await r.step('Open Discounts and press Add discount')
      await r.click('a[data-add]')
      await r.page.waitForSelector('#pname')
      await r.step('Name the discount. Only you see this name')
      await r.type('#pname', 'Soft toys sale')
      await r.step('Choose Percentage or Fixed amount, then type the value')
      await r.choose('#ptype', 'Percentage (%)')
      await r.type('#pvalue', '15', { clear: true })
      await r.step('Choose what it applies to: the whole store, or chosen categories or products')
      await r.choose('#pscope', 'Chosen categories')
      await r.step('Search and click the categories to add')
      await r.click('.tp-input', { pause: 700 })
      await r.page.keyboard.type('Soft', { delay: 70 })
      await sleep(1100)
      await r.click('.tp-option', { pause: 900 })
      await r.page.keyboard.press('Tab')   // leaves the box: the list closes
      await sleep(600)
      await r.step('Starts and Ends are optional. Leave them empty to start now and never end')
      const when = await r.locate('#pstart')
      await r.ring(when, 2400)
      await r.page.evaluate(() => window.__hvRing(null))
      await r.step('Press Add discount. The new price shows on the website and in the cart')
      await r.click('button[form="promo-form"]', { pause: 2400 })
      await r.step('Done. Switch Active off in the list to stop a discount without deleting it', { pause: 3000 })
    },
  },

  // ------------------------------------------------------------------------------------------------------------------------------ coupon
  {
    slug: 'add-coupon',
    topic: 'Coupons',
    title: 'Add a coupon',
    summary: 'A coupon is a code customers type at checkout. Set the code, the amount, and limits such as a minimum order.',
    can: 'coupons.manage',
    as: 'admin',
    open: { route: 'coupons', text: 'Open Coupons' },
    start: 'coupons',
    async run(r) {
      await r.title('Add a coupon', 'A code customers type at checkout')
      await r.step('Open Coupons and press Add coupon')
      await r.click('a[data-add]')
      await r.page.waitForSelector('#pcode')
      await r.step('Type the code customers will enter. Letters and digits, no spaces')
      await r.type('#pcode', 'WELCOME15')
      await r.step('Give it a name for yourself')
      await r.type('#pname', 'Welcome offer')
      await r.step('Choose Percentage or Fixed amount, then type the value')
      await r.choose('#ptype', 'Percentage (%)')
      await r.type('#pvalue', '15', { clear: true })
      await r.step('It works on the whole store here. You can also choose categories or products')
      const scope = await r.locate('#pscope')
      await r.ring(scope, 1800)
      await r.page.evaluate(() => window.__hvRing(null))
      await r.step('Set limits: a minimum order, how many times it can be used, and uses per customer')
      await r.type('#pmin', '50', { clear: true })
      await r.type('#plimit', '100', { clear: true })
      await r.type('#pcust', '1', { clear: true })
      await r.step('Free delivery and First order only are switches in the same form')
      const first = await r.locate('label.switch:has([aria-label="First order only"]), label.switch[aria-label="First order only"]')
      await r.ring(first, 2000)
      await r.page.evaluate(() => window.__hvRing(null))
      await r.step('Press Add coupon')
      await r.click('button[form="promo-form"]', { pause: 2400 })
      await r.step('Done. Tell your customers the code. Switch it off in the list when the offer ends', { pause: 3000 })
    },
  },

  // ------------------------------------------------------------------------------------------------------------------------------ settings
  {
    slug: 'settings',
    topic: 'Settings',
    title: 'Settings',
    summary: 'A tour of Settings: your contact details, delivery, payments and notifications, and how to save a change.',
    can: 'settings.*',
    as: 'admin',
    open: { route: 'settings', text: 'Open Settings' },
    start: 'settings',
    async run(r) {
      await r.title('Settings', 'Contact, delivery, payments, notifications')
      await r.step('Settings has one tab for each topic. Start with General')
      await r.ring(await r.locate('.tabs--scroll'), 1800)
      await r.page.evaluate(() => window.__hvRing(null))
      await r.step('Your phone, WhatsApp number and e-mail show on the website, in e-mails and on invoices')
      await r.type('#s-store_phone', '+230 5123 4567', { clear: true })
      await r.type('#s-whatsapp_number', '+230 5123 4567', { clear: true })
      await r.type('#s-store_email', 'hello@jhkstore.mu', { clear: true })
      await r.step('Press Save changes. The bar at the bottom shows when something is not saved')
      await r.click('[data-testid="save-settings"]', { pause: 2000 })
      await r.step('Delivery: choose how the fee is worked out, and the amounts')
      await r.click('#tab-delivery', { pause: 1200 })
      await r.ring(await r.locate('#panel-delivery .card'), 2200)
      await r.page.evaluate(() => window.__hvRing(null))
      await r.step('Payments: switch cash on delivery and bank transfer on or off, and add your bank accounts')
      await r.click('#tab-payments', { pause: 1200 })
      await r.ring(await r.locate('#panel-payments .card'), 2200)
      await r.page.evaluate(() => window.__hvRing(null))
      await r.step('The IBAN is optional. It is shown to customers only when you fill it in')
      await r.ring(await r.locate('.accounts-editor'), 2400)
      await r.page.evaluate(() => window.__hvRing(null))
      await r.step('Notifications: who is told by e-mail and on this device when an order arrives')
      await r.click('#tab-notifications', { pause: 1500 })
      await r.ring(await r.locate('#panel-notifications > *'), 2400)
      await r.page.evaluate(() => window.__hvRing(null))
      await r.step('Every change shows on the website after the next rebuild, about 1 to 3 minutes', { pause: 3000 })
    },
  },

// ------------------------------------------------------------------------------------------------------------------------------ the mail account
  {
    slug: 'mail-account',
    topic: 'Settings',
    title: 'Connect the e-mail account',
    summary: 'Set the mail account that sends order e-mails to your customers: the server, the login and the sender address, then send a test.',
    can: 'settings.mail',
    as: 'developer',
    needs: 'smtp',
    open: { route: 'settings', text: 'Open Settings' },
    start: 'settings',
    async run(r, ctx) {
      await r.title('Connect the e-mail account', 'So order e-mails reach your customers')
      await r.step('Open Settings and the E-mail tab')
      await r.click('#tab-mail', { pause: 1200 })
      await r.step('Press your mail service to fill in the server, the port and the encryption')
      await r.click('[data-preset="Gmail"]', { pause: 400 })
      await r.ring(await r.locate('[data-testid="mail-preset-note"]'), 2600)
      await r.page.evaluate(() => window.__hvRing(null))
      await r.step('Or type the mail server your provider gave you (here a test server is used)')
      await r.type('#s-mail_host', '127.0.0.1', { clear: true })
      await r.type('#s-mail_port', String(ctx.smtpPort), { clear: true })
      await r.choose('#s-mail_encryption', 'None')
      await r.step('Type the user name, the password and the sender address')
      await r.type('#s-mail_username', 'shop@myshop.com')
      await r.type('#s-mail_password', 'an-app-password')
      await r.type('#s-mail_from_address', 'shop@myshop.com')
      await r.step('Switch on Send with this mail account')
      await r.click('label.switch:has(#s-mail_custom)', { pause: 700 })
      await r.step('Press Save changes')
      await r.click('[data-testid="save-settings"]', { pause: 1800 })
      await r.step('Send a test e-mail to an address of yours. If it is not sent, the message says why')
      await r.type('#mail-test-to', 'owner@myshop.com')
      await r.click('[data-testid="send-test-email"]', { pause: 400 })
      await r.page.waitForSelector('[data-testid="test-email-result"]', { timeout: 20000 })
      await r.ring(await r.locate('[data-testid="test-email-result"]'), 2600)
      await r.page.evaluate(() => window.__hvRing(null))
      await r.step('Last, check that Send e-mails is on in the Notifications tab')
      await r.click('#tab-notifications', { pause: 1200 })
      await r.ring(await r.locate('label[for="s-mail_enabled"]'), 2600)
      await r.page.evaluate(() => window.__hvRing(null))
      await r.step('Done. Order e-mails now go out with this account', { pause: 2800 })
    },
  },
]
