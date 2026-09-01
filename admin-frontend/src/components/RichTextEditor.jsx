import React, { useState, useEffect, useRef, useMemo } from 'react';
import { message } from 'antd';
import '@wangeditor/editor/dist/css/style.css';
import { Editor, Toolbar } from '@wangeditor/editor-for-react';
import { uploadImage } from '../services/api';

// wangEditor renders its toolbar panels and modals inside the editor container.
// Inside an antd Drawer (z-index 1000) those panels lose the stacking fight, and
// .w-e-text-container creates its own stacking context that traps them, so the
// z-indexes are lifted here rather than patched per page.
const EDITOR_CSS = `
.rte-wrapper {
  position: relative;
  z-index: 0;
  border: 1px solid #d9d9d9;
  border-radius: 6px;
}
.rte-wrapper .w-e-bar {
  position: relative;
  z-index: 1002;
  border-bottom: 1px solid #f0f0f0;
  border-radius: 6px 6px 0 0;
  flex-wrap: wrap;
}
.rte-wrapper .w-e-text-container {
  z-index: auto;
  border-radius: 0 0 6px 6px;
}
.rte-wrapper .w-e-drop-panel,
.rte-wrapper .w-e-select-list,
.rte-wrapper .w-e-bar-item-menus-container {
  z-index: 1003;
}
.rte-wrapper .w-e-modal {
  z-index: 1010;
}
.rte-wrapper .w-e-text-placeholder {
  top: 10px;
  font-style: normal;
}
`;

const EXCLUDED_MENUS = ['group-video', 'insertVideo', 'uploadVideo', 'emotion', 'todo', 'fullScreen'];

/**
 * Controlled rich text field. `value` / `onChange` carry an HTML string, so the
 * component sits directly inside a ProForm.Item / Form.Item.
 */
export default function RichTextEditor({ value, onChange, placeholder = '请输入内容', height = 300 }) {
  const [editor, setEditor] = useState(null);
  const [html, setHtml] = useState(value || '');
  // What we last handed to (or took from) the form. Guards the sync effect so a
  // value echoed back by the form never resets the caret mid-typing.
  const lastEmitted = useRef(value || '');

  useEffect(() => {
    const next = value || '';
    if (next !== lastEmitted.current) {
      lastEmitted.current = next;
      setHtml(next);
    }
  }, [value]);

  // wangEditor instances hold DOM listeners and a global registry entry. Without an
  // explicit destroy they leak and the next Drawer/Modal open renders a dead editor.
  useEffect(() => {
    return () => {
      if (editor) {
        editor.destroy();
      }
    };
  }, [editor]);

  const toolbarConfig = useMemo(() => ({ excludeKeys: EXCLUDED_MENUS }), []);

  const editorConfig = useMemo(
    () => ({
      placeholder,
      // wangEditor 的 autoFocus 默认是 true，编辑器一挂载就把焦点抢过去。系统设置页
      // 有两个富文本编辑器，于是「后挂载的那个赢」，页面刚打开焦点就莫名其妙落在
      // 公告编辑器里。编辑器是内容区，不该在用户还没点它的时候拿焦点。
      autoFocus: false,
      MENU_CONF: {
        uploadImage: {
          async customUpload(file, insertFn) {
            try {
              const res = await uploadImage(file);
              const url = res.data?.url;
              if (!url) {
                throw new Error('上传失败');
              }
              insertFn(url, file.name || '', url);
            } catch (err) {
              message.error(err.response?.data?.message || '图片上传失败');
            }
          },
        },
      },
    }),
    [placeholder]
  );

  const handleChange = (ed) => {
    const raw = ed.getHtml();
    // Keep the raw html for the editor itself, but report an empty string upwards
    // when there is nothing in it, so `required` rules behave as an operator expects.
    lastEmitted.current = ed.isEmpty() ? '' : raw;
    setHtml(raw);
    onChange?.(lastEmitted.current);
  };

  return (
    <div className="rte-wrapper">
      <style>{EDITOR_CSS}</style>
      <Toolbar editor={editor} defaultConfig={toolbarConfig} mode="default" />
      <Editor
        defaultConfig={editorConfig}
        value={html}
        onCreated={setEditor}
        onChange={handleChange}
        mode="default"
        style={{ minHeight: height, height, overflowY: 'hidden' }}
      />
    </div>
  );
}
