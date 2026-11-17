import React, { useState } from 'react';
import { Upload, Button, Space, Spin, message } from 'antd';
import { PlusOutlined, LoadingOutlined, DeleteOutlined } from '@ant-design/icons';
import { uploadImage } from '../services/api';

// .ico is here because the server accepts it and the site favicon field uses this
// same uploader — without it the browser file picker greyed out the operator's .ico
// and there was no way to set a favicon at all.
const ACCEPT = '.jpg,.jpeg,.png,.gif,.webp,.ico';
const ALLOWED_TYPES = [
  'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
  // Browsers disagree on the .ico type: Chrome reports image/x-icon, Firefox
  // image/vnd.microsoft.icon, and some report nothing at all (hence the extOk path).
  'image/x-icon', 'image/vnd.microsoft.icon',
];
const MAX_SIZE = 2 * 1024 * 1024;

// Mirrors the server rule (image, mimes:jpg,jpeg,png,gif,webp,ico, max:2048) so the
// operator gets an immediate answer instead of waiting on a request that 422s.
function validateFile(file) {
  const type = (file.type || '').toLowerCase();
  const name = (file.name || '').toLowerCase();
  const extOk = /\.(jpe?g|png|gif|webp|ico)$/.test(name);

  if (!ALLOWED_TYPES.includes(type) && !extOk) {
    message.error('只支持 JPG / PNG / GIF / WEBP / ICO 格式的图片');
    return false;
  }
  if (file.size > MAX_SIZE) {
    message.error('图片大小不能超过 2MB');
    return false;
  }
  return true;
}

/**
 * Controlled image field. `value` is the public URL string returned by
 * POST /api/admin/upload (or null), never an antd fileList, so it drops straight
 * into a ProForm.Item / Form.Item without a getValueFromEvent shim.
 */
export default function ImageUploader({ value, onChange, disabled }) {
  const [uploading, setUploading] = useState(false);

  const handleUpload = async ({ file, onSuccess, onError }) => {
    setUploading(true);
    try {
      const res = await uploadImage(file);
      const url = res.data?.url;
      if (!url) {
        throw new Error('上传失败');
      }
      onChange?.(url);
      message.success('上传成功');
      onSuccess?.(res.data);
    } catch (err) {
      message.error(err.response?.data?.message || '图片上传失败');
      onError?.(err);
    } finally {
      setUploading(false);
    }
  };

  return (
    <Space direction="vertical" size={4}>
      <Upload
        name="file"
        accept={ACCEPT}
        listType="picture-card"
        showUploadList={false}
        disabled={disabled || uploading}
        beforeUpload={(file) => (validateFile(file) ? true : Upload.LIST_IGNORE)}
        customRequest={handleUpload}
      >
        {uploading ? (
          <Spin indicator={<LoadingOutlined spin />} />
        ) : value ? (
          <img
            src={value}
            alt="已上传图片"
            style={{ width: '100%', height: '100%', objectFit: 'cover', borderRadius: 4 }}
          />
        ) : (
          <div style={{ color: '#666' }}>
            <PlusOutlined />
            <div style={{ marginTop: 6, fontSize: 12 }}>上传图片</div>
          </div>
        )}
      </Upload>
      {value && !uploading ? (
        <Button
          type="link"
          size="small"
          danger
          icon={<DeleteOutlined />}
          disabled={disabled}
          onClick={() => onChange?.(null)}
          style={{ paddingLeft: 0 }}
        >
          移除图片
        </Button>
      ) : null}
    </Space>
  );
}
