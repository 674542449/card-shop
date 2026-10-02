import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { UserOutlined, LockOutlined, ArrowRightOutlined, ExportOutlined } from '@ant-design/icons';
import { Button, Form, Input, message } from 'antd';
import { login, loginChallenge } from '../services/api';

export default function LoginPage() {
  const navigate = useNavigate();
  const [loading, setLoading] = useState(false);
  const [challenge, setChallenge] = useState(false);
  const [form] = Form.useForm();

  const handleSubmit = async (values) => {
    setLoading(true);
    try {
      if (challenge) await loginChallenge(values.code);
      else {
        const res = await login(values.username, values.password);
        if (res.data?.two_factor_required) { form.resetFields(); setChallenge(true); return; }
      }
      message.success('登录成功');
      navigate('/', { replace: true });
    } catch (err) {
      if (challenge && err.response?.status === 401) { setChallenge(false); form.resetFields(); }
      message.error(err.response?.data?.message || '登录失败，请检查用户名和密码');
    } finally {
      setLoading(false);
    }
  };

  return (
    <main className="admin-login-shell">
      <a className="admin-login-brand" href="/" aria-label="CardShop 商城首页">
        <svg width="28" height="32" viewBox="0 0 28 32" fill="none" aria-hidden="true">
          <path d="M6 2.5h12l5.5 5.5v20A1.5 1.5 0 0 1 22 29.5H6A1.5 1.5 0 0 1 4.5 28V4A1.5 1.5 0 0 1 6 2.5Z" stroke="currentColor" strokeWidth="1.6" />
          <path d="M17.5 3v6H23M9 15h10M9 20h7" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
        <span>CardShop</span>
      </a>
      <div className="admin-login-grid">
        <section className="admin-login-intro">
          <span className="admin-eyebrow">你的商城工作台</span>
          <h1>好生意，<br /><em>从容打理。</em></h1>
          <p>把商品整理妥当，让订单顺畅交付。<br />从这里开始，照顾好你的每一位顾客。</p>
          <div className="admin-login-art" aria-hidden="true">
            <div className="admin-login-sheet">
              <div className="admin-login-sheet-title">今日经营手记 <span>01</span></div>
              <div className="admin-login-sheet-line"><span className="admin-login-check">✓</span> 一切准备就绪</div>
              <div className="admin-login-sheet-line"><span className="admin-login-check">✓</span> 商品有序陈列</div>
              <div className="admin-login-sheet-line"><span className="admin-login-check">✓</span> 每一份交付，都值得认真</div>
              <div className="admin-login-sheet-rule" />
              <span className="admin-login-handwriting">Make good things happen.</span>
            </div>
            <span className="admin-login-art-star">✳</span>
          </div>
        </section>
        <section className="admin-login-panel" aria-labelledby="admin-login-title">
          <span className="admin-eyebrow">欢迎回来</span>
          <h2 id="admin-login-title">登录管理后台</h2>
          <p className="admin-login-description">{challenge ? '输入验证器的六位验证码，或一个未使用的恢复码。验证有效期为五分钟。' : '使用管理员账户，继续打理你的商城。'}</p>
          <Form form={form} name="admin-login" layout="vertical" onFinish={handleSubmit} requiredMark={false} disabled={loading} size="large">
            {challenge ? <Form.Item name="code" label="验证码或恢复码" rules={[{ required: true, message: '请输入验证码或恢复码' }]}>
              <Input autoComplete="one-time-code" autoFocus maxLength={32} placeholder="六位验证码 / 恢复码" />
            </Form.Item> : <>
            <Form.Item name="username" label="管理员账号" rules={[{ required: true, message: '请输入用户名' }]}>
              <Input prefix={<UserOutlined />} placeholder="输入管理员账号" autoComplete="username" />
            </Form.Item>
            <Form.Item name="password" label="登录密码" rules={[{ required: true, message: '请输入密码' }]}>
              <Input.Password prefix={<LockOutlined />} placeholder="输入登录密码" autoComplete="current-password" />
            </Form.Item>
            </>}
            <Button className="admin-login-submit" type="primary" htmlType="submit" loading={loading} block icon={<ArrowRightOutlined />}>
              进入工作台
            </Button>
            {challenge && <Button type="link" onClick={() => { setChallenge(false); form.resetFields(); }}>重新输入账号密码</Button>}
          </Form>
          <div className="admin-login-panel-footer"><LockOutlined /><span>管理员专属入口</span></div>
        </section>
      </div>
      <footer className="admin-login-footer"><span>专注经营，安心交付。</span><a href="/">返回商城 <ExportOutlined /></a></footer>
    </main>
  );
}
