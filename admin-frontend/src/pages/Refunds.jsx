import React,{useRef,useState} from 'react';
import {ProTable,ModalForm,ProFormSelect,ProFormText,ProFormTextArea} from '@ant-design/pro-components';
import {Alert,Button,message} from 'antd';
import api from '../services/api';
import {useOutletContext,useSearchParams} from 'react-router-dom';
import Link from '../components/PermissionLink';
import {allows} from '../permissions';
const statuses={requested:'待审核',approved:'已批准，待退款',completed:'已退款',rejected:'已拒绝'};
export default function Refunds(){
 const admin=useOutletContext();const write=allows(admin,'refunds','write');
 const [params]=useSearchParams();const initialStatus=statuses[params.get('status')]?params.get('status'):undefined;
 const ref=useRef();const [editing,setEditing]=useState(null);
 return <><Alert type="info" showIcon message="此页管理退款审核与实际退款记录。批准申请后，请在原支付渠道完成退款，再登记流水凭证；不会把已交付卡密重新入库。" style={{marginBottom:16}} />
 <ProTable key={initialStatus||'all'} form={{name:'refunds-search',initialValues:{status:initialStatus}}} actionRef={ref} rowKey="id" request={async p=>{const r=await api.get('/refunds',{params:{page:p.current,per_page:p.pageSize,status:p.status}});return {data:r.data.data,total:r.data.total,success:true};}}
 columns={[{title:'编号',dataIndex:'id',search:false},{title:'订单',render:(_,r)=>r.order?<Link to={`/orders/${r.order.id}`}>{r.order.order_no}</Link>:'-',search:false},{title:'金额（人民币）',dataIndex:'amount',search:false},
 {title:'状态',dataIndex:'status',valueType:'select',valueEnum:Object.fromEntries(Object.entries(statuses).map(([k,v])=>[k,{text:v}]))},
 {title:'申请原因',dataIndex:'reason',search:false},{title:'退款凭证',dataIndex:'reference',search:false},{title:'内部备注',dataIndex:'note',search:false},{title:'买家处理说明',dataIndex:'customer_note',search:false},
 {title:'操作',search:false,render:(_,r)=>write&&['requested','approved'].includes(r.status)?<Button onClick={()=>setEditing(r)}>处理</Button>:null}]} />
 <ModalForm name="refund-review" key={editing?.id} title="处理退款" initialValues={{reference:editing?.reference,note:editing?.note,customer_note:editing?.customer_note}} open={!!editing} onOpenChange={v=>!v&&setEditing(null)} onFinish={async v=>{try{await api.put(`/refunds/${editing.id}`,v);message.success('已保存处理结果');ref.current?.reload();return true;}catch(e){message.error(e.response?.data?.message||'处理失败');return false;}}}>
 <ProFormSelect name="status" label="处理结果" rules={[{required:true}]} options={(editing?.status==='requested'?['approved','rejected']:['completed','rejected']).map(v=>({value:v,label:statuses[v]}))}/>
 <ProFormText name="reference" label="实际退款流水 / 凭证编号（完成退款时必填）" />
 <ProFormTextArea name="note" label="内部备注（买家不可见）" fieldProps={{maxLength:2000}} />
 <ProFormTextArea name="customer_note" label="买家可见处理说明" fieldProps={{maxLength:2000}} extra="用于向买家说明审核或退款结果，请勿填写内部信息或支付密钥。" />
 </ModalForm></>;
}
