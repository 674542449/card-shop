import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import createDOMPurify from 'dompurify';
import { sanitizeEditorHtml, protectEditorHtmlInputs } from '../src/utils/editorHtml.js';

const hostile = '<p onclick="window.__DUMMY_XSS=1">Text<img src="/missing" onerror="window.__DUMMY_XSS=2"><a href="javascript:window.__DUMMY_XSS=3">link</a></p><svg onload="window.__DUMMY_XSS=4"></svg><iframe srcdoc="<script>window.__DUMMY_XSS=5</script>"></iframe>';
const environment = () => {
  const dom = new JSDOM('<body></body>', { url: 'http://127.0.0.1:8002/admin', runScripts: 'dangerously' });
  const purifier = createDOMPurify(dom.window);
  return { dom, sanitize: input => sanitizeEditorHtml(input, purifier) };
};

test('stored and pasted hostile HTML cannot install event handlers in the editor DOM', () => {
  const { dom, sanitize } = environment();
  const element = dom.window.document.createElement('div');
  element.innerHTML = sanitize(hostile);
  dom.window.document.body.appendChild(element);
  element.querySelector('p').dispatchEvent(new dom.window.Event('click'));
  element.querySelector('img').dispatchEvent(new dom.window.Event('error'));
  assert.equal(dom.window.__DUMMY_XSS, undefined);
  assert.equal(element.querySelector('svg,iframe,script'), null);
  assert.equal(element.querySelector('img').hasAttribute('onerror'), false);
  assert.equal(element.querySelector('a').hasAttribute('href'), false);
  dom.window.close();
});

test('editor HTML sanitization preserves formatting, table cells and image metadata', () => {
  const { dom, sanitize } = environment();
  const markup = '<p style="text-align:center;color:rgb(255, 0, 0)"><strong>bold</strong></p><table><tbody><tr><td colspan="2">cell</td></tr></tbody></table><p><img src="/storage/uploads/a.png" data-w-e-type="image" data-w-e-is-void width="100" alt="image"></p>';
  const element = dom.window.document.createElement('div');
  element.innerHTML = sanitize(markup);
  assert.equal(element.querySelector('strong').textContent, 'bold');
  assert.equal(element.querySelector('p').style.textAlign, 'center');
  assert.equal(element.querySelector('td').getAttribute('colspan'), '2');
  assert.equal(element.querySelector('img').getAttribute('data-w-e-type'), 'image');
  assert.equal(element.querySelector('img').getAttribute('src'), '/storage/uploads/a.png');
  dom.window.close();
});

test('editor CSS keeps colors and dimensions without page overlays or resource URLs', () => {
  const { dom, sanitize } = environment();
  const element = dom.window.document.createElement('div');
  element.innerHTML = sanitize('<p style="position:fixed;z-index:99999;opacity:0;pointer-events:none;background-image:url(https://dummy.invalid);text-align:center;color:red">text</p><table style="width:100px;background-color:blue"><tr><td style="color:green;height:20px">cell</td></tr></table>');
  const paragraph = element.querySelector('p');
  for (const property of ['position', 'z-index', 'opacity', 'pointer-events', 'background-image']) assert.equal(paragraph.style.getPropertyValue(property), '');
  assert.equal(paragraph.style.textAlign, 'center');
  assert.equal(paragraph.style.color, 'red');
  assert.equal(element.querySelector('table').style.width, '100px');
  assert.equal(element.querySelector('table').style.backgroundColor, 'blue');
  assert.equal(element.querySelector('td').style.color, 'green');
  assert.equal(element.querySelector('td').style.height, '20px');
  dom.window.close();
});

test('programmatic set/insert and clipboard/drop fragments pass through purification', () => {
  const { dom, sanitize } = environment();
  const observed = [];
  const editor = {
    setHtml: html => observed.push(['setHtml', html]),
    dangerouslyInsertHtml: html => observed.push(['insertHtml', html]),
    insertData: data => observed.push(['insertData', data.getData('text/html'), data.getData('application/x-slate-fragment'), data.getData('text/plain')]),
  };
  protectEditorHtmlInputs(editor, sanitize);
  editor.setHtml(hostile);
  editor.dangerouslyInsertHtml(hostile);
  editor.insertData({ getData: type => ({ 'text/html': hostile, 'application/x-slate-fragment': 'DUMMY-RAW-NODE-FRAGMENT', 'text/plain': 'Text' }[type] || ''), files: [] });
  for (const row of observed) assert.equal(/onerror|onclick|javascript:|<iframe|<svg/.test(row[1]), false);
  assert.equal(observed[2][2], '');
  assert.equal(observed[2][3], 'Text');
  dom.window.close();
});
