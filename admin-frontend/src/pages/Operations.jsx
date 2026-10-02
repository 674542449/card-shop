import React,{useState,useEffect,useRef,useCallback} from 'react';
import {Tabs,Card,Alert,Button,Descriptions,message,Space,Popconfirm,Progress,Table,Typography} from 'antd';
import {ProTable} from '@ant-design/pro-components';
import api from '../services/api';
import {useOutletContext} from 'react-router-dom';
import {allows} from '../permissions';
const failure=e=>e.response?.data?.message||'请求失败，请检查连接后重试。';
const bytes=value=>value==null?'未知':(value/1024/1024).toFixed(1)+' MB';

export default function Operations(){
 const admin=useOutletContext();const maintenance=allows(admin,'maintenance');const write=allows(admin,'maintenance','write');const content=allows(admin,'content');const contentWrite=allows(admin,'content','write');const owner=admin?.role==='owner';
 const [health,setHealth]=useState(null);const [healthError,setHealthError]=useState('');const [busy,setBusy]=useState(false);const [runs,setRuns]=useState([]);const [backupHealth,setBackupHealth]=useState(null);const [backupError,setBackupError]=useState('');
 const seoRef=useRef();const assetsRef=useRef();const backupRef=useRef();const mounted=useRef(true);
 useEffect(()=>{mounted.current=true;return()=>{mounted.current=false;};},[]);
 const load=useCallback(async()=>{try{const r=await api.get('/maintenance/health');if(mounted.current){setHealth(r.data);setHealthError('');}}catch(e){if(mounted.current)setHealthError(failure(e));}},[]);
 useEffect(()=>{if(!maintenance)return;load();const timer=setInterval(load,30000);return()=>clearInterval(timer);},[maintenance,load]);
 const active=runs.some(r=>['pending','running'].includes(r.status));
 useEffect(()=>{if(!owner||!active)return;const timer=setInterval(()=>backupRef.current?.reload(),5000);return()=>clearInterval(timer);},[owner,active]);
 const run=async(action,success='操作完成')=>{try{await action();if(success)message.success(success);}catch(e){message.error(failure(e));}};
 const ack=type=>run(async()=>{await api.post('/maintenance/health/acknowledge',{type,observed_at:health.observed_at,through_id:type==='notifications'?health.notification_failure_through_id:health.seo_failure_through_id,failure_version:health.reconciliation?.failure_version});await load();},'当前失败已确认，后续新失败继续告警。');
 const submit=async()=>{setBusy(true);await run(async()=>{const r=await api.post('/maintenance/backups');if(mounted.current)setRuns(v=>[r.data.run,...v.filter(x=>x.id!==r.data.run.id)]);backupRef.current?.reload();},'备份任务已提交，请查看任务进度。');if(mounted.current)setBusy(false);};
 const ackButton=(type,count)=>write&&(type!=='notifications'||allows(admin,'notifications','write'))&&(type!=='seo'||contentWrite)&&count>0?<Popconfirm title="确认当前失败？失败记录保留，新失败仍会告警，可在任务页面重试。" onConfirm={()=>ack(type)}><Button size="small">确认当前失败</Button></Popconfirm>:null;
 return <Tabs items={[
 {key:'health',label:'运行健康',children:<Card title="后台任务与积压" extra={<Button onClick={load}>刷新</Button>}>
 {healthError&&<Alert type="error" showIcon message={healthError}/>}
 {health&&<><Descriptions column={1}>
 {['notifications','scheduler','reconciliation'].map(k=><Descriptions.Item key={k} label={{notifications:'通知发送',scheduler:'定时调度',reconciliation:'付款对账'}[k]}>{k==='reconciliation'&&!health[k]?.enabled?'自动对账未启用':health[k]?.healthy?'近期收到心跳':'未收到近期心跳'} · {health[k]?.last_seen_at||'尚未运行'}</Descriptions.Item>)}
 <Descriptions.Item label="等待通知">{health.notification_pending} · 最早：{health.oldest_notification_at||'无积压'}</Descriptions.Item>
 <Descriptions.Item label="待处理通知失败"><Space>{health.notification_failed}{ackButton('notifications',health.notification_failed)}</Space></Descriptions.Item>
 <Descriptions.Item label="等待搜索推送">{health.seo_pending} · 最早：{health.oldest_seo_at||'无积压'}</Descriptions.Item>
 <Descriptions.Item label="待处理推送失败"><Space>{health.seo_failed}{ackButton('seo',health.seo_failed)}</Space></Descriptions.Item>
 <Descriptions.Item label="对账连续失败"><Space>{health.reconciliation?.consecutive_failures||0}{ackButton('reconciliation',health.reconciliation?.failure_warning?1:0)}</Space></Descriptions.Item>
 <Descriptions.Item label="逾期未释放订单">{health.overdue_orders}</Descriptions.Item>
 <Descriptions.Item label="完整备份">{health.backups?.healthy?'正常':'需要处理'} · 最近成功：{health.backups?.last_success_at||'尚无备份'} · 磁盘可用：{bytes(health.backups?.free_bytes)}</Descriptions.Item>
 </Descriptions>{!health.healthy&&<Alert type="warning" showIcon message="后台任务或备份异常，请检查失败任务、配置和进程。服务器可运行 php artisan shop:health --alert 获取监控结果。"/>}</>}
 </Card>},
 {key:'seo',label:'搜索引擎推送',children:<ProTable actionRef={seoRef} rowKey="id" pagination={{defaultPageSize:20,showSizeChanger:true,pageSizeOptions:[20,50,100,200]}} toolBarRender={()=>contentWrite?[<Button key="enqueue" onClick={()=>run(async()=>{const r=await api.post('/seo-deliveries/enqueue');message.info(r.data.message);seoRef.current?.reload();},'')}>提交现有页面</Button>]:[]} request={async p=>{try{const r=await api.get('/seo-deliveries',{params:{page:p.current,per_page:p.pageSize,status:p.status}});return {data:r.data.data,total:r.data.total,success:true};}catch(e){message.error(failure(e));return {data:[],total:0,success:false};}}}
 columns={[{title:'引擎',dataIndex:'provider',search:false},{title:'页面',dataIndex:'url',search:false},{title:'状态',dataIndex:'status',valueType:'select',valueEnum:{pending:'等待',processing:'发送中',sent:'已提交',failed:'失败'}},{title:'尝试次数',dataIndex:'attempts',search:false},{title:'提示',dataIndex:'last_error',search:false},{title:'告警确认',search:false,render:(_,r)=>r.health_acknowledged_at||'未确认'},{title:'操作',search:false,render:(_,r)=>contentWrite&&r.status==='failed'?<Button onClick={()=>run(async()=>{await api.post('/seo-deliveries/'+r.id+'/retry');seoRef.current?.reload();if(maintenance)load();})}>重试</Button>:null}]} />},
 {key:'assets',label:'上传素材',children:<><Alert type="info" message="仅未被引用且上传超过 7 天的素材可以归档，归档后可恢复。" style={{marginBottom:16}}/>
 <ProTable actionRef={assetsRef} rowKey="path" search={false} pagination={{pageSize:20}} request={async()=>{try{const r=await api.get('/maintenance/assets');return {data:r.data.data,success:true};}catch(e){message.error(failure(e));return {data:[],success:false};}}}
 columns={[{title:'路径',dataIndex:'path'},{title:'大小',render:(_,r)=>bytes(r.size)},{title:'状态',render:(_,r)=>r.quarantined?'已归档':r.referenced?'使用中':'未引用'},{title:'操作',render:(_,r)=>!write?null:r.quarantined?<Button onClick={()=>run(async()=>{await api.post('/maintenance/assets',{path:r.path,action:'restore'});assetsRef.current?.reload();})}>恢复</Button>:!r.referenced?<Popconfirm title="归档这份未引用素材？之后可以恢复。" onConfirm={()=>run(async()=>{await api.post('/maintenance/assets',{path:r.path,action:'quarantine'});assetsRef.current?.reload();})}><Button>归档</Button></Popconfirm>:null}]} /></>},
 {key:'backups',label:'备份与恢复',children:<>
 <Alert type="info" message="备份由独立进程执行，包含数据库、上传文件和配置。在系统设置可启用每日备份和保留策略；异机副本只写入店主明确指定的挂载目录。恢复请按 DEPLOY.md 先在独立库演练。" style={{marginBottom:16}}/>
 {backupError&&<Alert type="error" showIcon message={backupError}/>}
 {backupHealth&&<Descriptions column={2}><Descriptions.Item label="自动备份">{backupHealth.enabled?'已启用':'未启用'}</Descriptions.Item><Descriptions.Item label="备份进程">{backupHealth.worker_required?backupHealth.worker_healthy?'运行正常':'未收到近期心跳':'暂无等待任务'}</Descriptions.Item><Descriptions.Item label="最近成功">{backupHealth.last_success_at||'尚无备份'}</Descriptions.Item><Descriptions.Item label="磁盘可用">{bytes(backupHealth.free_bytes)}</Descriptions.Item></Descriptions>}
 {backupHealth&&!backupHealth.healthy&&<Alert type="warning" showIcon message={[backupHealth.stale_warning&&'完整备份已过期',backupHealth.space_warning&&'备份磁盘空间不足或不可读取',backupHealth.failed>0&&'有未确认的备份或同步失败',!backupHealth.worker_healthy&&'备份进程无近期心跳',backupHealth.backlog_warning&&'任务等待超过15分钟'].filter(Boolean).join('；')} style={{marginBottom:16}}/>}
 <Typography.Title level={5}>备份任务</Typography.Title>
 <Table rowKey="id" size="small" dataSource={runs} pagination={false} scroll={{x:850}} columns={[
 {title:'任务',dataIndex:'id'},{title:'来源',dataIndex:'source',render:v=>({manual:'后台提交',scheduled:'每日自动',cli:'终端提交'}[v]||v)},
 {title:'状态',dataIndex:'status',render:v=>({pending:'等待',running:'执行中',completed:'完成',failed:'失败'}[v]||v)},
 {title:'进度',render:(_,r)=><><Progress percent={r.progress} size="small" status={r.status==='failed'?'exception':undefined}/>{r.phase}</>},
 {title:'挂载目录同步',dataIndex:'sync_status',render:v=>({disabled:'未启用',pending:'等待',synced:'已校验复制',failed:'失败'}[v]||v)},
 {title:'提示',render:(_,r)=>r.last_error||r.filename||''},
 {title:'操作',render:(_,r)=><Space>{(r.status==='failed'||r.last_error)&&<Button size="small" onClick={()=>run(async()=>{await api.post('/maintenance/backup-runs/'+r.id+'/retry');backupRef.current?.reload();},'已重新提交备份任务')}>{r.status==='completed'?'重新备份并同步':'重试'}</Button>}{!r.health_acknowledged_at&&(r.status==='failed'||r.last_error)&&<Popconfirm title="确认本次失败？记录保留，新失败仍会告警。" onConfirm={()=>run(async()=>{await api.post('/maintenance/backup-runs/'+r.id+'/acknowledge');backupRef.current?.reload();})}><Button size="small">确认失败</Button></Popconfirm>}</Space>},
 ]}/>
 <Typography.Title level={5}>完整备份文件</Typography.Title>
 <ProTable actionRef={backupRef} rowKey="name" search={false} request={async()=>{try{const r=await api.get('/maintenance/backups');if(mounted.current){setRuns(r.data.runs||[]);setBackupHealth(r.data.health);setBackupError('');}return {data:r.data.data,success:true};}catch(e){if(mounted.current)setBackupError(failure(e));return {data:[],success:false};}}}
 toolBarRender={()=>[<Button key="create" loading={busy} disabled={active} onClick={submit}>{active?'备份任务正在执行或等待':'创建完整备份'}</Button>]}
 columns={[{title:'备份',dataIndex:'name'},{title:'大小',render:(_,r)=>bytes(r.size)},{title:'时间',dataIndex:'created_at'},{title:'操作',render:(_,r)=><Space><Button onClick={()=>run(async()=>{const x=await api.post('/maintenance/backups/'+r.name+'/validate');message.info(x.data.message);},'')}>校验</Button><Button onClick={()=>run(async()=>{const x=await api.get('/maintenance/backups/'+r.name+'/download',{responseType:'blob'});const a=document.createElement('a');const url=URL.createObjectURL(x.data);a.href=url;a.download=r.name;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);},'')}>下载</Button></Space>}]} /></>}
 ].filter(tab=>tab.key==='seo'?content:tab.key==='backups'?owner:maintenance)}/>;
}
