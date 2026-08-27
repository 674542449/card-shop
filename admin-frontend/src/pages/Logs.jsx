import React, { useRef } from 'react';
import { ProTable } from '@ant-design/pro-components';
import { getLogs } from '../services/api';

export default function Logs() {
  const actionRef = useRef();

  const columns = [
    { title: 'ID', dataIndex: 'id', width: 60, search: false },
    {
      title: '管理员',
      dataIndex: 'admin',
      render: (_, record) => record.admin?.username || '-',
    },
    { title: '操作', dataIndex: 'action', width: 100 },
    { title: '资源类型', dataIndex: 'target_type', width: 120 },
    { title: '资源ID', dataIndex: 'target_id', width: 80, search: false },
    { title: '描述', dataIndex: 'detail', ellipsis: true, search: false },
    { title: 'IP', dataIndex: 'ip', width: 140, search: false },
    {
      title: '创建时间',
      dataIndex: 'created_at',
      width: 180,
      valueType: 'dateRange',
      render: (_, record) => record.created_at,
      search: {
        transform: (value) => ({
          start_date: value[0],
          end_date: value[1],
        }),
      },
    },
  ];

  return (
    <ProTable
      headerTitle="操作日志"
      actionRef={actionRef}
      rowKey="id"
      columns={columns}
      search={{ labelWidth: 'auto' }}
      request={async (params) => {
        const { current, pageSize, ...rest } = params;
        const res = await getLogs({ page: current, per_page: pageSize, ...rest });
        const d = res.data?.data || res.data;
        return {
          data: d.data || d,
          total: d.total || d.length,
          success: true,
        };
      }}
    />
  );
}
