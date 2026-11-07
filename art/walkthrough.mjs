// Browser walk-through of the whole request path against the workbench (MariaDB + a real queue worker), and the
// source of the screenshots in art/. Run through scripts/art (Playwright in Docker); see art/README-art.md in the
// plugin repo's PLUGINS.md entry. Everything on screen is invented demo data.
//
//   docker run --rm --add-host=host.docker.internal:host-gateway -v $PWD/..:/plugins mcr.microsoft.com/playwright:v1.48.0-noble \
//     node /plugins/filament-gdpr-requests/art/walkthrough.mjs
import { chromium } from '/plugins/scripts/art/node_modules/playwright/index.mjs'
import { openStamper } from '/plugins/scripts/art/stamp.mjs'
import { mkdirSync, statSync, readFileSync } from 'node:fs'

const base = process.env.BASE ?? 'http://host.docker.internal:8765'
const out = '/plugins/filament-gdpr-requests/art'
const problems = []
const log = (...a) => console.log(...a)
const must = (cond, msg) => { if (!cond) { problems.push(msg); console.log('FAIL:', msg) } else console.log('ok:', msg) }

const browser = await chromium.launch()
const stamp = await openStamper(browser)

const open = async (email, { scheme = 'light', width = 1440, height = 900 } = {}) => {
    const context = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: 2, colorScheme: scheme, acceptDownloads: true })
    const page = await context.newPage()
    await page.addInitScript((value) => { if (!localStorage.getItem('filament:theme:mode')) localStorage.setItem('filament:theme:mode', value) }, scheme)
    page.on('pageerror', (e) => problems.push(`pageerror ${email}: ${e.message}`))
    page.on('console', (m) => { if (m.type() === 'error') problems.push(`console ${email}: ${m.text().slice(0, 200)}`) })
    page.on('response', (r) => { if (r.status() >= 500) problems.push(`HTTP ${r.status()} ${r.url()}`) })
    await page.goto(base + '/admin/login')
    await page.fill('input[type=email]', email)
    await page.fill('input[type=password]', 'password')
    await Promise.all([page.waitForURL((u) => !u.pathname.endsWith('/login')), page.click('button[type=submit]')])

    return page
}

const snap = async (page, name, { full = false } = {}) => {
    await page.mouse.move(0, 0)
    await page.waitForTimeout(500)
    const file = `${out}/${name}.jpg`
    await page.screenshot({ path: file, type: 'jpeg', quality: 90, fullPage: full })
    if (!name.endsWith('-source')) await stamp(file)
    log('shot', name)
}

const pick = async (page, id, option) => {
    await page.click(`#${id.replaceAll('.', '\\.')}`)
    await page.getByRole('option', { name: option, exact: true }).click()
}

const modal = (page) => page.locator('.fi-modal-window')
const button = (page, name) => page.getByRole('button', { name, exact: true })
const submitModal = async (page, label = 'Submit') => { await modal(page).getByRole('button', { name: label, exact: true }).click() }
const toast = (page, text) => page.locator('.fi-no-notification', { hasText: text }).first()
const goto = async (page, path) => page.goto(base + path, { waitUntil: 'networkidle' })
const noHorizontalScroll = async (page, label) => must(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), `${label}: no horizontal page scroll`)
const typeReference = async (page) => {
    const text = await modal(page).innerText()
    const ref = text.match(/reference\s+([0-9A-Z]{8})/)?.[1]
    must(ref, 'the modal asks for a typed reference')
    await modal(page).locator('input[type=text]').fill(ref)
    return ref
}
const openRequest = async (page, email) => {
    await goto(page, '/admin/gdpr-requests')
    await page.getByText(email).first().click()
    await page.waitForURL(/gdpr-requests\/[0-9a-z]{26}/)
    await page.waitForLoadState('networkidle')
}

// ---------------------------------------------------------------------------------------------------- Marta (DPO)
const marta = await open('marta@example.test')
await goto(marta, '/admin/gdpr-requests')
must(await marta.getByText('Bruno Sample').first().isVisible(), 'the list shows the seeded requests')
await snap(marta, 'list')

// A) access request: verify identity -> export -> download -> fulfil -> close
await openRequest(marta, 'bruno@customer.example')
must(await marta.getByText('Not verified').first().isVisible(), 'a new request starts unverified')
must(await button(marta, 'Prepare export').isDisabled(), 'export is locked until the identity is verified')
await button(marta, 'Link record').click()
await marta.locator('.fi-modal-window .fi-select-input-btn').first().click()
await marta.getByRole('option', { name: 'Customer', exact: true }).click()
await submitModal(marta)
await toast(marta, 'Record linked').waitFor()
await button(marta, 'Verify identity').click()
await modal(marta).locator('textarea').fill('Replied from the registered address and confirmed the last order number.')
await snap(marta, 'verify')
await submitModal(marta)
await toast(marta, 'Identity recorded as verified').waitFor()
await marta.waitForSelector('text=Verified')
await button(marta, 'Prepare export').click()
await submitModal(marta, 'Confirm')
await toast(marta, 'Export is being prepared').waitFor()
let ready = false
for (let i = 0; i < 20 && !ready; i++) {
    await marta.waitForTimeout(1500)
    await marta.reload({ waitUntil: 'networkidle' })
    ready = (await marta.getByText('Ready', { exact: true }).count()) > 0
}
must(ready, 'the queue worker built the export')
await snap(marta, 'export')
const [download] = await Promise.all([marta.waitForEvent('download'), button(marta, 'Download export').click()])
const zipPath = '/tmp/export.zip'
await download.saveAs(zipPath)
const zip = readFileSync(zipPath)
must(zip.subarray(0, 2).toString() === 'PK' && zip.length > 200, `the signed link downloads a zip (${zip.length} bytes)`)
must(!zip.includes('Ada Example') && !zip.includes('ada@customer.example'), 'the archive of Bruno does not contain Ada')
const unzipped = zip.toString('latin1')
must(unzipped.includes('data/01-customer.json') && unzipped.includes('manifest.json'), 'the archive has the declared data files and a manifest')
await marta.getByRole('button', { name: 'More' }).click()
await marta.getByRole('menuitem', { name: 'Mark as fulfilled' }).click()
await submitModal(marta, 'Confirm')
await marta.waitForSelector('text=Fulfilled')
await marta.getByRole('button', { name: 'More' }).click()
await marta.getByRole('menuitem', { name: 'Close request' }).click()
await submitModal(marta, 'Confirm')
await toast(marta, 'Request closed').waitFor()
await marta.waitForSelector('text=Closed')
await goto(marta, '/admin/gdpr-requests')

// B) erasure request: new -> verify -> preview -> confirm
await marta.getByRole('link', { name: 'New Data request' }).click()
await marta.waitForURL(/create/)
await pick(marta, 'form.type', 'Erasure')
await marta.fill('#form\\.name', 'Chloe Placeholder')
await marta.fill('#form\\.email', 'chloe@customer.example')
await marta.fill('#form\\.message', 'Please delete everything you hold about me.')
await pick(marta, 'form.subject_model', 'Customer')
await snap(marta, 'create')
await button(marta, 'Create').click()
await marta.waitForURL(/gdpr-requests\/[0-9a-z]{26}$/)
await marta.waitForSelector('text=Not verified')
const requestUrl = marta.url()
must(await marta.getByText('Customer #3').first().isVisible(), 'the e-mail was matched exactly to the customer record')
await button(marta, 'Verify identity').click()
await modal(marta).locator('textarea').fill('Called back on the number in the customer file; answered the security questions.')
await submitModal(marta)
await marta.waitForSelector('text=Verified')
await snap(marta, 'view')
await marta.getByRole('link', { name: 'Erasure preview' }).click()
await marta.waitForURL(/erasure$/)
await marta.waitForLoadState('networkidle')
must(await marta.getByText('Erasure is final').first().isVisible(), 'the preview states that erasure is final')
must(await marta.getByText('Order items').first().isVisible() && await marta.getByText('Invoices').first().isVisible(), 'the preview lists every mapped entry')
must(await marta.getByText('Legal basis').first().isVisible(), 'a retained entry shows its legal basis')
const erasureUrl = marta.url()
await snap(marta, 'preview')
// dark mode and phone
await marta.evaluate(() => localStorage.setItem('filament:theme:mode', 'dark'))
await marta.reload({ waitUntil: 'networkidle' })
await snap(marta, 'preview-dark')
await marta.setViewportSize({ width: 390, height: 844 })
await marta.waitForTimeout(400)
await noHorizontalScroll(marta, 'phone, erasure preview')
await snap(marta, 'preview-phone')
await goto(marta, requestUrl.replace(base, ''))
await noHorizontalScroll(marta, 'phone, request view')
await snap(marta, 'view-phone')
await marta.evaluate(() => localStorage.setItem('filament:theme:mode', 'light'))
await marta.setViewportSize({ width: 1440, height: 900 })
await goto(marta, erasureUrl.replace(base, ''))

// A wrong reference is refused; the right one confirms
await button(marta, 'Confirm erasure').click()
await modal(marta).locator('input[type=text]').fill('WRONG123')
await submitModal(marta, 'Confirm')
await marta.waitForSelector('text=The reference does not match.')
must(true, 'a wrong typed reference is rejected')
await typeReference(marta)
await snap(marta, 'confirm')
await submitModal(marta, 'Confirm')
await toast(marta, 'Erasure confirmed').waitFor()
await marta.waitForSelector('text=Erasure confirmed')

// four eyes: the confirming person cannot run it
await button(marta, 'Run erasure').click()
await typeReference(marta)
await submitModal(marta, 'Erase now')
await toast(marta, 'Four eyes').waitFor()
must(true, 'four eyes: the person who confirmed is refused')
await snap(marta, 'four-eyes')

// ---------------------------------------------------------------------------------------------------- Oleh (second DPO)
const oleh = await open('oleh@example.test')
await goto(oleh, erasureUrl.replace(base, ''))
await oleh.getByText('Chloe').count()
await button(oleh, 'Run erasure').click()
await typeReference(oleh)
await snap(oleh, 'run')
await submitModal(oleh, 'Erase now')
await toast(oleh, 'Erasure started').waitFor()
let finished = false
for (let i = 0; i < 25 && !finished; i++) {
    await oleh.waitForTimeout(2000)
    await oleh.reload({ waitUntil: 'networkidle' })
    finished = (await oleh.getByText('Erasure finished', { exact: true }).count()) > 0
}
must(finished, 'the queue worker finished the erasure (status Fulfilled)')
await snap(oleh, 'report')
await goto(oleh, requestUrl.replace(base, ''))
must(await oleh.getByText('Fulfilled').first().isVisible(), 'the request shows Fulfilled')
must(await oleh.getByText('Erased with four eyes DISABLED').count() === 0, 'four eyes stayed on for this run')
await oleh.getByText('Action log').scrollIntoViewIfNeeded()
await snap(oleh, 'log', { full: true })
await oleh.getByRole('button', { name: 'More' }).click()
await oleh.getByRole('menuitem', { name: 'Close request' }).click()
await submitModal(oleh, 'Confirm')
await toast(oleh, 'Request closed').waitFor()
await goto(oleh, '/admin/gdpr-requests')
await oleh.getByRole('button', { name: 'Filters' }).click().catch(() => {})
await snap(oleh, 'list-after')

// dark list for the art
await oleh.evaluate(() => localStorage.setItem('filament:theme:mode', 'dark'))
await goto(oleh, '/admin/gdpr-requests')
await snap(oleh, 'list-dark')

// A manager cannot reach export / erase
const nina = await open('nina@example.test')
await goto(nina, '/admin/gdpr-requests')
await nina.getByText('Ada Example').first().click()
await nina.waitForURL(/gdpr-requests\/[0-9a-z]{26}/)
must((await nina.getByRole('button', { name: 'Prepare export' }).count()) === 0, 'a manager does not see the export button')
must((await nina.getByRole('link', { name: 'Erasure preview' }).count()) === 0, 'a manager does not see the erasure preview')
const forbidden = await nina.goto(base + erasureUrl.replace(base, '').replace(/[0-9a-z]{26}/, (m) => m), { waitUntil: 'networkidle' })
must(forbidden.status() === 403, 'a manager gets 403 on the erasure page')

await browser.close()
log('\nPROBLEMS:', problems.length)
for (const p of problems) log(' -', p)
process.exit(problems.length ? 1 : 0)
