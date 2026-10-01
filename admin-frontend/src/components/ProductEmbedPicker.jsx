import React,{useState,useEffect} from 'react';
import {Select,Button,Space,message} from 'antd';
import api from '../services/api';
export default function ProductEmbedPicker({formRef}){
 const [term,setTerm]=useState('');const [options,setOptions]=useState([]);const [id,setId]=useState();
 useEffect(()=>{let active=true;const timer=setTimeout(()=>api.get('/articles/product-options',{params:{q:term}}).then(r=>active&&setOptions(r.data.data.map(p=>({value:p.id,label:p.name})))).catch(()=>{}),300);return()=>{active=false;clearTimeout(timer);};},[term]);
 return <Space wrap style={{marginBottom:12}}><Select aria-label="文章商品卡片" placeholder="搜索并选择商品" showSearch filterOption={false} onSearch={setTerm} options={options} value={id} onChange={setId} style={{width:280}}/>
 <Button disabled={!id} onClick={()=>{const current=formRef.current?.getFieldValue('content')||'';formRef.current?.setFieldsValue({content:`${current}<p>[[product:${id}]]</p>`});message.success('已插入商品引用，保存文章后生效');}}>插入商品卡片</Button></Space>;
}
