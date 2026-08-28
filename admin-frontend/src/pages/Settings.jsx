import React, { useEffect, useState } from 'react';
import { ProForm, ProFormText, ProFormTextArea, ProFormDigit } from '@ant-design/pro-components';
import { Card, Tabs, Spin, message } from 'antd';
import { getSettings, updateSettings } from '../services/api';
import ImageUploader from '../components/ImageUploader';

export default function Settings() {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [initialValues, setInitialValues] = useState({});
  const [form] = ProForm.useForm();

  useEffect(() => {
    getSettings()
      .then((res) => {
        const data = res.data?.data || res.data;
        setInitialValues(data);
        form.setFieldsValue(data);
      })
      .catch(() => message.error('加载设置失败'))
      .finally(() => setLoading(false));
  }, [form]);

  const handleSave = async (values) => {
    setSaving(true);
    try {
      await updateSettings(values);
      message.success('保存成功');
    } catch (err) {
      message.error(err.response?.data?.message || '保存失败');
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div style={{ textAlign: 'center', padding: 100 }}>
        <Spin size="large" />
      </div>
    );
  }

  const tabItems = [
    {
      key: 'basic',
      label: '基本设置',
      children: (
        <>
          <ProFormText name="site_name" label="站点名称" />
          <ProFormTextArea name="site_description" label="站点描述" fieldProps={{ rows: 3 }} />
          <ProFormTextArea name="site_announcement" label="站点公告" fieldProps={{ rows: 3 }} />
          <ProFormText name="contact_text" label="联系方式文字" />
          <ProFormText name="contact_url" label="联系方式链接" />
          <ProForm.Item name="site_logo" label="站点 Logo">
            <ImageUploader />
          </ProForm.Item>
          <ProForm.Item name="contact_qr_image" label="联系二维码图片">
            <ImageUploader />
          </ProForm.Item>
        </>
      ),
    },
    {
      key: 'seo',
      label: 'SEO 设置',
      children: (
        <>
          <ProFormText name="seo_default_title" label="默认 SEO 标题" />
          <ProFormTextArea name="seo_default_description" label="默认 SEO 描述" fieldProps={{ rows: 3 }} />
          <ProFormText name="seo_default_keywords" label="默认 SEO 关键词" />
        </>
      ),
    },
    {
      key: 'epay',
      label: 'EPay 支付',
      children: (
        <>
          <ProFormText name="epay_api_url" label="EPay 网关地址" />
          <ProFormText name="epay_merchant_id" label="EPay 商户ID" />
          <ProFormText name="epay_merchant_key" label="EPay 商户密钥" />
        </>
      ),
    },
    {
      key: 'epusdt',
      label: 'EPUSDT 支付',
      children: (
        <>
          <ProFormText name="epusdt_api_url" label="EPUSDT 网关地址" />
          <ProFormText name="epusdt_api_token" label="EPUSDT Token" />
        </>
      ),
    },
    {
      key: 'security',
      label: '安全设置',
      children: (
        <>
          <ProFormText name="turnstile_site_key" label="Turnstile Site Key" />
          <ProFormText name="turnstile_secret_key" label="Turnstile Secret Key" />
        </>
      ),
    },
    {
      key: 'order',
      label: '订单设置',
      children: (
        <>
          <ProFormDigit name="order_expire_minutes" label="订单过期时间（分钟）" min={1} />
        </>
      ),
    },
  ];

  return (
    <Card title="系统设置">
      <ProForm
        form={form}
        // Without this, clearing the logo or the QR code submits no key at all and the
        // old image is kept. Fields on tabs the operator never opened stay unregistered
        // and so remain absent from the payload either way, and SettingController only
        // writes keys the request actually carries — so nothing else gets wiped.
        omitNil={false}
        initialValues={initialValues}
        onFinish={handleSave}
        submitter={{
          searchConfig: { submitText: '保存设置' },
          submitButtonProps: { loading: saving },
          resetButtonProps: false,
        }}
      >
        <Tabs items={tabItems} />
      </ProForm>
    </Card>
  );
}
