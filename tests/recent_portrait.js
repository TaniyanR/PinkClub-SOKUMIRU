'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const key = 'pcf_recently_viewed_v1';
const portrait = 'https://img.sokmil.com/image/capture/ss_fixture001.jpg';
const landscape = 'https://img.sokmil.com/image/capture/ol_fixture001.jpg';
const read = name => fs.readFileSync(path.join(__dirname, '../public/assets/js/', name), 'utf8');
function context(history, cover = portrait) {
  const values = new Map([[key, JSON.stringify(history)]]);
  const events = [];
  const location = {origin: 'https://example.test', href: 'https://example.test/item.php?id=101', pathname: '/item.php', protocol: 'https:', host: 'example.test', search: '?id=101'};
  const ctx = {URL, URLSearchParams, Event, navigator: {webdriver: true},
    localStorage: {getItem: k => values.get(k) ?? null, setItem: (k, v) => values.set(k, v), removeItem: k => values.delete(k)},
    document: {title: 'Test', getElementById: () => null, querySelectorAll: () => [], querySelector: selector => {
      if (selector === '[data-recent-front-cover]') return {dataset: {recentFrontCover: cover}};
      if (selector === 'meta[property="og:title"]') return {content: 'Test'};
      if (selector === 'meta[property="og:image"]') return {content: landscape};
      return null;
    }},
    window: {location, addEventListener() {}, dispatchEvent(event) { events.push(event.type); }}}
  vm.createContext(ctx);
  return {ctx, values, events};
}
(async () => {
  for (const cover of [portrait, '']) {
    const {ctx, values} = context([], cover);
    vm.runInContext(read('recently-viewed.js'), ctx);
    const item = JSON.parse(values.get(key))[0];
    assert.equal(item.image, cover);
    assert.equal(item.imageType, 'front-cover');
    assert.equal(item.viewCount, 1);
  }
  for (const removeDuringFetch of [false, true]) {
    const entries = [{id: 101, title: 'Old', image: landscape, viewedAt: 123, viewCount: 4}];
    const {ctx, values, events} = context(entries);
    ctx.document.getElementById = () => ({});
    let complete;
    ctx.fetch = url => {
      assert.equal(new URL(url).searchParams.get('v'), 'portrait-2');
      return new Promise(resolve => { complete = resolve; });
    };
    vm.runInContext(read('recently-viewed-front-cover.js'), ctx);
    if (removeDuringFetch) values.delete(key);
    complete({ok: true, json: async () => ({images: {'101': portrait}})});
    await new Promise(resolve => setImmediate(resolve));
    const saved = JSON.parse(values.get(key) || '[]');
    if (removeDuringFetch) assert.deepEqual(saved, []);
    else {
      assert.equal(saved[0].image, portrait);
      assert.equal(saved[0].imageType, 'front-cover');
      assert.equal(saved[0].viewCount, 4);
      assert.equal(saved[0].viewedAt, 123);
    }
    assert.ok(events.includes('pcf-recent-images-updated'));
  }
  console.log('PASS: portrait recording, no OGP fallback, old history repair, and no resurrection after removal');
})().catch(error => {console.error(error); process.exitCode = 1;});
