import React, { useRef } from 'react';
import { Tabs, Alert, Button, message, Empty } from 'antd';
import { ProTable } from '@ant-design/pro-components';
import { useOutletContext, useSearchParams } from 'react-router-dom';
import Link from '../components/PermissionLink';
import { allows, canCapability } from '../permissions';
import api from '../services/api';
import Notifications from './Notifications';

export default function TaskCenter() {
 const admin=useOutletContext();
 const [params,setParams]=useSearchParams();
 const notifications=allows(admin,'notifications');
 const content=allows(admin,'content'); const contentWrite=allows(admin,'content','write');
 const reconciliation=canCapability(admin,'reconciliation.read');
 const reconciliationWrite=canCapability(admin,'reconciliation.retry');
 const seoRef=useRef(); const reconciliationRef=useRef();
 const failure=e=>e.response?.data?.message||'请求失败，请检查连接后重试。';
 const run=async(action,success='操作完成')=>{try{await action();if(success)message.success(success);}catch(e){message.error(failure(e));}};
 const items=[
 {key:'notifications',label:'通知投递',children:<Notifications />},
 {key:'reconciliation',label:'付款对账任务',children:<>
 <Alert type="info" showIcon message="查询付款结果不会重新创建支付；重试失败任务前，请先修复网关配置并启用自动付款对账。" style={{marginBottom:16}}/>
 <ProTable key={params.get('status') || 'all'} form={{name:'reconciliation-search',initialValues:{status:['pending','processing','completed','failed'].includes(params.get('status'))?params.get('status'):undefined}}} actionRef={reconciliationRef} rowKey="id" pagination={{defaultPageSize:20,showSizeChanger:true,pageSizeOptions:[20,50,100,200]}}
 request={async p=>{try{const r=await api.get('/maintenance/reconciliation-jobs',{params:{page:p.current,per_page:p.pageSize,status:p.status}});return {data:r.data.data,total:r.data.total,success:true};}catch(e){message.error(failure(e));return {data:[],total:0,success:false};}}}
 columns={[{title:'任务',dataIndex:'id',search:false},{title:'订单',search:false,render:(_,r)=>r.order?<Link to={'/orders/'+r.order_id}>{r.order.order_no}</Link>:'订单不可用'},
 {title:'状态',dataIndex:'status',valueType:'select',valueEnum:{pending:'等待',processing:'查询中',completed:'核对完成',failed:'失败'}},{title:'尝试次数',dataIndex:'attempts',search:false},
 {title:'下次尝试',dataIndex:'available_at',search:false},{title:'完成时间',dataIndex:'finished_at',search:false},{title:'提示',dataIndex:'last_error',search:false},
 {title:'操作',search:false,render:(_,r)=>reconciliationWrite&&r.status==='failed'?<Button onClick={()=>run(async()=>{await api.post('/maintenance/reconciliation-jobs/'+r.id+'/retry');reconciliationRef.current?.reload();},'对账任务已重新入队')}>重试</Button>:null}]} /></>},
 {key:'seo',label:'搜索引擎推送',children:<ProTable key={params.get('status') || 'all'} form={{name:'seo-search',initialValues:{status:['pending','processing','sent','failed'].includes(params.get('status'))?params.get('status'):undefined}}} actionRef={seoRef} rowKey="id" pagination={{defaultPageSize:20,showSizeChanger:true,pageSizeOptions:[20,50,100,200]}} toolBarRender={()=>contentWrite?[<Button key="enqueue" onClick={()=>run(async()=>{const r=await api.post('/seo-deliveries/enqueue');message.info(r.data.message);seoRef.current?.reload();},'')}>提交现有页面</Button>]:[]} request={async p=>{try{const r=await api.get('/seo-deliveries',{params:{page:p.current,per_page:p.pageSize,status:p.status}});return {data:r.data.data,total:r.data.total,success:true};}catch(e){message.error(failure(e));return {data:[],total:0,success:false};}}}
 columns={[{title:'引擎',dataIndex:'provider',search:false},{title:'页面',dataIndex:'url',search:false},{title:'状态',dataIndex:'status',valueType:'select',valueEnum:{pending:'等待',processing:'发送中',sent:'已提交',failed:'失败'}},{title:'尝试次数',dataIndex:'attempts',search:false},{title:'提示',dataIndex:'last_error',search:false},{title:'告警确认',search:false,render:(_,r)=>r.health_acknowledged_at||'未确认'},{title:'操作',search:false,render:(_,r)=>contentWrite&&r.status==='failed'?<Button onClick={()=>run(async()=>{await api.post('/seo-deliveries/'+r.id+'/retry');seoRef.current?.reload();})}>重试</Button>:null}]} />},

 ].filter(tab=>tab.key==='notifications'?notifications:tab.key==='seo'?content:reconciliation);
 if(!items.length) return <Empty description="没有可查看的任务" />;
 const active=items.some(tab=>tab.key===params.get('tab'))?params.get('tab'):items[0].key;
 return <Tabs activeKey={active} destroyOnHidden onChange={key=>setParams({tab:key})} items={items} />;
}
