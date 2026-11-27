import React, { useState, useEffect, useRef, useMemo } from 'react';
import { message } from 'antd';
import '@wangeditor/editor/dist/css/style.css';
import { Editor, Toolbar } from '@wangeditor/editor-for-react';
import { uploadImage } from '../services/api';
import { sanitizeEditorHtml, protectEditorHtmlInputs } from '../utils/editorHtml';

const EXCLUDED_MENUS = ['group-video', 'insertVideo', 'uploadVideo', 'emotion', 'todo', 'fullScreen'];

/**
 * Controlled rich text field. `value` / `onChange` carry an HTML string, so the
 * component sits directly inside a ProForm.Item / Form.Item.
 */
export default function RichTextEditor({ value, onChange, placeholder = '请输入内容', height = 300, disabled = false }) {
  const [editor, setEditor] = useState(null);
  const safeValue = useMemo(() => sanitizeEditorHtml(value || ''), [value]);
  const [html, setHtml] = useState(safeValue);
  // What we last handed to (or took from) the form. Guards the sync effect so a
  // value echoed back by the form never resets the caret mid-typing, and avoids
  // marking forms dirty on initial mount or programmatic setHtml hydration.
  const lastEmitted = useRef(safeValue);
  const onChangeRef = useRef(onChange);
  const disabledRef = useRef(disabled);
  const readyRef = useRef(false);
  const syncingRef = useRef(false);

  onChangeRef.current = onChange;
  disabledRef.current = disabled;

  useEffect(() => {
    const next = safeValue;
    if (next !== lastEmitted.current) {
      syncingRef.current = true;
      lastEmitted.current = next;
      setHtml(next);
      queueMicrotask(() => {
        syncingRef.current = false;
      });
    }
  }, [safeValue]);

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

  useEffect(() => {
    if (!editor) return;
    if (disabled) editor.disable();
    else editor.enable();
  }, [editor, disabled]);

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
            if (disabled) return;
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
    [placeholder, disabled]
  );

  const handleChange = (ed) => {
    if (disabledRef.current || !readyRef.current || syncingRef.current) return;
    const raw = sanitizeEditorHtml(ed.getHtml());
    // Keep the raw html for the editor itself, but report an empty string upwards
    // when there is nothing in it, so `required` rules behave as an operator expects.
    const nextEmitted = ed.isEmpty() ? '' : raw;
    if (nextEmitted === lastEmitted.current) return;
    lastEmitted.current = nextEmitted;
    setHtml(raw);
    onChangeRef.current?.(nextEmitted);
  };

  return (
    <div className="rte-wrapper">
      {!disabled && <Toolbar editor={editor} defaultConfig={toolbarConfig} mode="default" />}
      <Editor
        defaultConfig={editorConfig}
        value={html}
        onCreated={(ed) => {
          protectEditorHtmlInputs(ed);
          lastEmitted.current = ed.isEmpty() ? '' : sanitizeEditorHtml(ed.getHtml());
          setEditor(ed);
          queueMicrotask(() => {
            readyRef.current = true;
          });
        }}
        onChange={handleChange}
        mode="default"
        style={{ minHeight: height, height, overflowY: 'hidden' }}
      />
    </div>
  );
}
