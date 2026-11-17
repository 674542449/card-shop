import DOMPurify from 'dompurify';

// The editor parses stored HTML in a temporary element attached to document.body.
// Frontend rendering purification does not protect that separate DOM entry point.
const editorPolicy = {
  ALLOWED_TAGS: ['p','div','br','hr','h1','h2','h3','h4','h5','h6','strong','b','em','i','u','s','strike','del','ins','sub','sup','span','ul','ol','li','blockquote','pre','code','table','thead','tbody','tfoot','tr','th','td','a','img'],
  ALLOWED_ATTR: ['style','class','href','title','target','rel','src','alt','width','height','colspan','rowspan','start','data-w-e-type','data-w-e-is-void','data-w-e-is-inline'],
  ALLOW_DATA_ATTR: false,
  ALLOW_ARIA_ATTR: false,
  SANITIZE_NAMED_PROPS: true,
};
const allowedCss = new Set(['text-align','text-indent','text-decoration','color','background-color',
  'font-size','font-weight','font-style','font-family','line-height','width','height','max-width','margin-left','padding-left']);
const configuredPurifiers = new WeakSet();

function configureCss(purifier) {
  if (configuredPurifiers.has(purifier)) return;
  purifier.addHook('uponSanitizeAttribute', (node, attribute) => {
    if (attribute.attrName !== 'style') return;
    const parsed = node.ownerDocument.createElement('span').style;
    parsed.cssText = attribute.attrValue;
    const clean = node.ownerDocument.createElement('span').style;
    for (let index = 0; index < parsed.length; index += 1) {
      const property = parsed.item(index);
      if (!allowedCss.has(property)) continue;
      const value = parsed.getPropertyValue(property);
      const decoded = value.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\\([\da-f]{1,6})\s?|\\([^\r\n])/gi, (_, hex, char) => {
        const point = hex ? parseInt(hex, 16) : 0;
        return hex ? (point > 0 && point <= 0x10ffff ? String.fromCodePoint(point) : '\uFFFD') : char;
      });
      if (/(?:url|expression)\s*\(/i.test(decoded)) continue;
      clean.setProperty(property, value);
    }
    attribute.attrValue = clean.cssText;
    if (!attribute.attrValue) attribute.keepAttr = false;
  });
  configuredPurifiers.add(purifier);
}

export function sanitizeEditorHtml(value, purifier = DOMPurify) {
  configureCss(purifier);
  return purifier.sanitize(typeof value === 'string' ? value : '', editorPolicy);
}

/** Covers programmatic inserts and clipboard/drop HTML, including Slate fragments. */
export function protectEditorHtmlInputs(editor, sanitize = sanitizeEditorHtml) {
  for (const method of ['setHtml', 'dangerouslyInsertHtml']) {
    if (typeof editor[method] !== 'function') continue;
    const original = editor[method].bind(editor);
    editor[method] = (html, ...args) => original(sanitize(html), ...args);
  }
  if (typeof editor.insertData !== 'function') return;
  const insertData = editor.insertData.bind(editor);
  editor.insertData = data => {
    if (!data?.getData) return insertData(data);
    const html = sanitize(data.getData('text/html'));
    // Untrusted application/x-slate-fragment bypasses HTML parsing entirely.
    // Preserve ordinary HTML/text/files, and rebuild its nodes through the parser.
    const safeData = new Proxy(data, {
      get(target, property) {
        if (property === 'getData') return type => type === 'application/x-slate-fragment' ? '' :
          type === 'text/html' ? html : target.getData(type);
        const value = Reflect.get(target, property, target);
        return typeof value === 'function' ? value.bind(target) : value;
      },
    });
    return insertData(safeData);
  };
}
