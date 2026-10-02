import React, { useState } from 'react';
import { Alert, Button, Card, Form, Input, QRCode, Space, Typography, message } from 'antd';
import { setupTwoFactor, confirmTwoFactor, disableTwoFactor } from '../services/api';

export default function TwoFactorSettings({ admin, onChange }) {
  const [setup, setSetup] = useState(null);
  const [codes, setCodes] = useState(null);
  const [busy, setBusy] = useState(false);
  const [form] = Form.useForm();
  const finish = async (values) => {
    setBusy(true);
    try {
      if (admin.two_factor_enabled) {
        await disableTwoFactor(values);
        message.success('双重验证已停用');
      } else if (setup) {
        const res = await confirmTwoFactor(values.code);
        setCodes(res.data.recovery_codes);
        setSetup(null);
        message.success('双重验证已启用，请保存恢复码');
      } else {
        const res = await setupTwoFactor(values.current_password);
        setSetup(res.data);
        form.resetFields();
        return;
      }
      form.resetFields();
      await onChange();
    } catch (err) { message.error(err.response?.data?.message || '操作失败，请重试'); }
    finally { setBusy(false); }
  };
  return <Card title="登录双重验证" style={{ marginTop: 24 }}>
    <Typography.Paragraph type="secondary">使用验证器应用生成验证码。启用或停用后，其他登录会话将失效；同一验证码只能使用一次。丢失设备时可以使用恢复码或由服务器管理员重置。</Typography.Paragraph>
    <Alert type={admin.two_factor_enabled ? 'success' : 'info'} showIcon message={admin.two_factor_enabled ? `已启用 · 剩余 ${admin.recovery_codes_remaining} 个恢复码` : '尚未启用'} style={{ marginBottom: 16 }} />
    {codes && <Alert type="warning" showIcon message="恢复码只在本次展示，请离线保存，每个码只能使用一次" description={<><Typography.Paragraph copyable={{ text: codes.join('\n') }}><pre>{codes.join('\n')}</pre></Typography.Paragraph><Button onClick={() => setCodes(null)}>已妥善保存，隐藏恢复码</Button></>} style={{ marginBottom: 16 }} />}
    {setup && <Space direction="vertical" style={{ marginBottom: 16 }}>
      <Typography.Text>用验证器扫描二维码，或手动输入密钥，然后输入当前验证码完成绑定（五分钟内）。</Typography.Text>
      <QRCode value={setup.uri} />
      <Typography.Text code copyable>{setup.secret}</Typography.Text>
    </Space>}
    <Form form={form} layout="vertical" onFinish={finish} disabled={busy}>
      {!setup && <Form.Item name="current_password" label="当前密码" rules={[{ required: true, message: '请输入当前密码' }]}><Input.Password autoComplete="current-password" /></Form.Item>}
      {(setup || admin.two_factor_enabled) && <Form.Item name="code" label={setup ? '六位验证码' : '验证码或恢复码'} rules={[{ required: true, message: '请输入验证码' }]}><Input autoComplete="one-time-code" maxLength={32} /></Form.Item>}
      <Space><Button type="primary" danger={admin.two_factor_enabled} htmlType="submit" loading={busy}>{admin.two_factor_enabled ? '停用双重验证' : setup ? '验证并启用' : '开始绑定验证器'}</Button>
        {setup && <Button onClick={() => { setSetup(null); form.resetFields(); }}>取消绑定</Button>}
      </Space>
    </Form>
  </Card>;
}
