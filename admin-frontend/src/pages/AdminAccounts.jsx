import React, { useRef, useState } from 'react';
import { ProTable, ModalForm, ProFormText, ProFormSelect, ProFormSwitch } from '@ant-design/pro-components';
import { Button, message, Tag } from 'antd';
import api from '../services/api';
const areas = { overview:'概览', catalog:'商品信息', cards:'卡密（敏感内容）', payments:'人工收款确认', orders:'订单', refunds:'退款', content:'内容与推送', coupons:'优惠券', blacklists:'黑名单', logs:'审计日志', settings:'系统设置', tokens:'API 令牌', notifications:'通知', maintenance:'维护' };
export default function AdminAccounts() {
  const ref = useRef(); const [editing,setEditing]=useState(null); const [open,setOpen]=useState(false);
  return <><ProTable actionRef={ref} rowKey="id" search={false} request={async()=>{const r=await api.get('/admins');return {data:r.data.data,success:true};}}
    toolBarRender={()=>[<Button key="new" onClick={()=>{setEditing(null);setOpen(true);}}>新增管理员</Button>]}
    columns={[{title:'账户',dataIndex:'username'},{title:'角色',dataIndex:'role',render:(_,r)=>r.role==='owner'?'店主':'自定义权限'},
      {title:'状态',render:(_,r)=><Tag color={r.is_active?'green':'default'}>{r.is_active?'启用':'停用'}</Tag>},
      {title:'最近登录',dataIndex:'last_login_at'},{title:'操作',render:(_,r)=><Button onClick={()=>{setEditing(r);setOpen(true);}}>编辑</Button>}]} />
    <ModalForm name="admin-account-editor" key={editing?.id||'new'} title={editing?'编辑管理员':'新增管理员'} open={open} onOpenChange={setOpen}
      initialValues={editing||{role:'staff',is_active:true,permissions:['overview:read','catalog:read','orders:read']}}
      onFinish={async v=>{try{if(editing)await api.put(`/admins/${editing.id}`,v);else await api.post('/admins',v);message.success('已保存');ref.current?.reload();return true;}catch(e){message.error(e.response?.data?.message||'保存失败');return false;}}}>
      <ProFormText name="username" label="账户名" rules={[{required:true}]} />
      <ProFormText.Password name="password" label={editing?'新密码（留空保留）':'密码'} rules={[{required:!editing},{min:12}]} />
      <ProFormSelect name="role" label="角色" options={[{label:'自定义权限',value:'staff'},{label:'店主（全部权限）',value:'owner'}]} rules={[{required:true}]} />
      <ProFormSelect name="permissions" label="权限" mode="multiple" extra="商品、订单查看不包含卡密；查看或导入卡密请单独授权，并配合商品查看使用。人工发货同时需要订单修改和人工收款确认修改权限，仅授予可信财务账户。支付、邮件、Telegram 配置仅店主可修改。" options={Object.entries(areas).flatMap(([k,v])=>[{value:`${k}:read`,label:`${v}·查看`},{value:`${k}:write`,label:`${v}·修改`}])} />
      <ProFormSwitch name="is_active" label="启用账户" />
    </ModalForm></>;
}
