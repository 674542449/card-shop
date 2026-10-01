import React,{useState,useEffect,useRef} from 'react';
import {Tabs,Card,Alert,Button,Descriptions,message,Space,Popconfirm} from 'antd';
import {ProTable} from '@ant-design/pro-components';
import api from '../services/api';
import {useOutletContext} from 'react-router-dom';
import {allows} from '../permissions';
export default function Operations(){
 const admin=useOutletContext();const maintenance=allows(admin,'maintenance');const write=allows(admin,'maintenance','write');const content=allows(admin,'content');const contentWrite=allows(admin,'content','write');
 const [health,setHealth]=useState(null);const [busy,setBusy]=useState(false);const seoRef=useRef();const assetsRef=useRef();const backupRef=useRef();
 const load=()=>api.get('/maintenance/health').then(r=>setHealth(r.data)).catch(()=>{});useEffect(()=>{if(!maintenance)return;load();const t=setInterval(load,30000);return()=>clearInterval(t);},[maintenance]);
 const run=async fn=>{try{await fn();message.success('操作完成');}catch(e){message.error(e.response?.data?.message||'操作失败');}};
 return <Tabs items={[
 {key:'health',label:'运行健康',children:<Card title="后台任务与积压" extra={<Button onClick={load}>刷新</Button>}>
 {health&&<><Descriptions column={1}>{['notifications','scheduler','reconciliation'].map(k=><Descriptions.Item key={k} label={{notifications:'通知发送',scheduler:'定时调度',reconciliation:'付款对账'}[k]}>{k==='reconciliation'&&!health[k]?.enabled?'自动对账未启用':health[k]?.healthy?'运行正常':'未收到近期心跳'} · {health[k]?.last_seen_at||'尚未运行'}</Descriptions.Item>)}
 <Descriptions.Item label="等待通知">{health.notification_pending}</Descriptions.Item><Descriptions.Item label="最早等待">{health.oldest_notification_at||'无积压'}</Descriptions.Item><Descriptions.Item label="逾期未释放订单">{health.overdue_orders}</Descriptions.Item></Descriptions>
 {(!health.notifications?.healthy||!health.scheduler?.healthy||health.backlog_warning||health.overdue_orders>0||(health.reconciliation?.enabled&&!health.reconciliation?.healthy))&&<Alert type="warning" showIcon message="后台任务或积压异常，请检查工作进程。服务器可运行 php artisan shop:health 获取监控退出码。"/>}</>}
 </Card>},
 {key:'seo',label:'搜索引擎推送',children:<ProTable actionRef={seoRef} rowKey="id" toolBarRender={()=>contentWrite?[<Button key="enqueue" onClick={()=>run(async()=>{const r=await api.post('/seo-deliveries/enqueue');message.info(r.data.message);seoRef.current?.reload();})}>提交现有页面</Button>]:[]} request={async p=>{const r=await api.get('/seo-deliveries',{params:{page:p.current,status:p.status}});return {data:r.data.data,total:r.data.total,success:true};}}
 columns={[{title:'引擎',dataIndex:'provider',search:false},{title:'页面',dataIndex:'url',search:false},{title:'状态',dataIndex:'status',valueType:'select',valueEnum:{pending:'等待',processing:'发送中',sent:'已提交',failed:'失败'}},{title:'尝试次数',dataIndex:'attempts',search:false},{title:'提示',dataIndex:'last_error',search:false},{title:'操作',search:false,render:(_,r)=>contentWrite&&r.status==='failed'?<Button onClick={()=>run(async()=>{await api.post(`/seo-deliveries/${r.id}/retry`);seoRef.current?.reload();})}>重试</Button>:null}]} />},
 {key:'assets',label:'上传素材',children:<><Alert type="info" message="仅未被引用且上传超过 7 天的素材可以归档，归档后可恢复。" style={{marginBottom:16}}/>
 <ProTable actionRef={assetsRef} rowKey="path" search={false} pagination={{pageSize:20}} request={async()=>{const r=await api.get('/maintenance/assets');return {data:r.data.data,success:true};}}
 columns={[{title:'路径',dataIndex:'path'},{title:'大小',dataIndex:'size'},{title:'状态',render:(_,r)=>r.quarantined?'已归档':r.referenced?'使用中':'未引用'},
 {title:'操作',render:(_,r)=>!write?null:r.quarantined?<Button onClick={()=>run(async()=>{await api.post('/maintenance/assets',{path:r.path,action:'restore'});assetsRef.current?.reload();})}>恢复</Button>:!r.referenced?<Popconfirm title="归档这份未引用素材？之后可以恢复。" onConfirm={()=>run(async()=>{await api.post('/maintenance/assets',{path:r.path,action:'quarantine'});assetsRef.current?.reload();})}><Button>归档</Button></Popconfirm>:null}]} /></>},
 {key:'backups',label:'备份与恢复',children:<><Alert type="info" message="备份包含数据库、上传文件和配置，文件保存在服务器私有目录。恢复请按 DEPLOY.md 的目标库确认流程执行，建议先在独立库演练。" style={{marginBottom:16}}/>
 <ProTable actionRef={backupRef} rowKey="name" search={false} request={async()=>{const r=await api.get('/maintenance/backups');return {data:r.data.data,success:true};}}
 toolBarRender={()=>[<Button key="create" loading={busy} onClick={async()=>{setBusy(true);await run(async()=>{await api.post('/maintenance/backups',{}, {timeout:1800000});backupRef.current?.reload();});setBusy(false);}}>创建完整备份</Button>]}
 columns={[{title:'备份',dataIndex:'name'},{title:'大小',dataIndex:'size'},{title:'时间',dataIndex:'created_at'},
 {title:'操作',render:(_,r)=><Space><Button onClick={()=>run(async()=>{const x=await api.post(`/maintenance/backups/${r.name}/validate`);message.info(x.data.message);})}>校验</Button><Button onClick={()=>run(async()=>{const x=await api.get(`/maintenance/backups/${r.name}/download`,{responseType:'blob'});const a=document.createElement('a');const url=URL.createObjectURL(x.data);a.href=url;a.download=r.name;a.click();URL.revokeObjectURL(url);})}>下载</Button></Space>}]} /></>}
 ].filter(tab=>tab.key==='seo'?content:tab.key==='backups'?admin?.role==='owner':maintenance)}/>;
}
