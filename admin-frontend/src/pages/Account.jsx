import React, { useCallback, useEffect, useState } from 'react';
import { ProForm, ProFormText } from '@ant-design/pro-components';
import { Alert, Button, Card, Descriptions, message, Spin, Typography } from 'antd';
import dayjs from 'dayjs';
import { getMe, changePassword } from '../services/api';

export default function Account() {
  const [admin, setAdmin] = useState(null);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [form] = ProForm.useForm();

  const load = useCallback(async () => {
    setLoading(true);
    setFailed(false);
    try {
      const res = await getMe();
      setAdmin(res.data);
    } catch {
      setAdmin(null);
      setFailed(true);
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => { load(); }, [load]);

  if (loading) return <div style={{ padding: 80, textAlign: 'center' }}><Spin size="large" /></div>;
  if (failed) return <Alert type="error" showIcon message="账户信息加载失败" description="没能获取当前管理员信息，请检查网络或服务器后重试。" action={<Button size="small" onClick={load}>重试</Button>} />;

  return (
    <div className="admin-account" style={{ maxWidth: 860 }}>
      <Card title="账户信息" style={{ marginBottom: 24 }}>
        <Descriptions column={{ xs: 1, sm: 2 }}>
          <Descriptions.Item label="管理员">{admin?.username || '—'}</Descriptions.Item>
          <Descriptions.Item label="最近登录 IP">{admin?.last_login_ip || '—'}</Descriptions.Item>
          <Descriptions.Item label="最近登录时间">{admin?.last_login_at ? dayjs(admin.last_login_at).format('YYYY-MM-DD HH:mm:ss') : '—'}</Descriptions.Item>
        </Descriptions>
      </Card>
      <Card title="修改密码">
        <Typography.Paragraph type="secondary">使用至少 12 个字符的新密码。修改后当前登录会继续有效，其他使用旧密码登录的会话会失效。</Typography.Paragraph>
        <ProForm
          name="account-password"
          form={form}
          autoFocusFirstInput={false}
          submitter={{ searchConfig: { submitText: '更新密码' }, resetButtonProps: false }}
          onFinish={async (values) => {
            try {
              await changePassword(values);
              form.resetFields();
              message.success('密码已更新');
              return true;
            } catch (err) {
              message.error(err.response?.data?.message || '密码修改失败');
              return false;
            }
          }}
        >
          <ProFormText.Password name="current_password" label="当前密码" fieldProps={{ autoComplete: 'current-password' }} rules={[{ required: true, message: '请输入当前密码' }]} />
          <ProFormText.Password name="new_password" label="新密码" fieldProps={{ autoComplete: 'new-password' }} rules={[{ required: true, message: '请输入新密码' }, { min: 12, message: '新密码至少 12 个字符' }]} />
          <ProFormText.Password
            name="new_password_confirmation" label="确认新密码" dependencies={['new_password']} fieldProps={{ autoComplete: 'new-password' }}
            rules={[
              { required: true, message: '请再次输入新密码' },
              ({ getFieldValue }) => ({ validator: (_, value) => !value || getFieldValue('new_password') === value ? Promise.resolve() : Promise.reject(new Error('两次输入的密码不一致')) }),
            ]}
          />
        </ProForm>
      </Card>
    </div>
  );
}
