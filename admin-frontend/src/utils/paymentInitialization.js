export const initializationStates = {
  created: { text: '等待创建', color: 'default' },
  processing: { text: '正在创建', color: 'processing' },
  uncertain: { text: '创建结果待核对', color: 'warning' },
  succeeded: { text: '已创建收银台', color: 'success' },
  failed: { text: '明确创建失败', color: 'error' },
};

export const initializationErrors = {
  gateway_uncertain: '网关返回结果不完整或请求中断，尚不能确认交易是否创建。',
  worker_interrupted: '创建进程中断，尚不能确认网关是否收到创建请求。',
  gateway_rejected: '配置校验或网关明确拒绝了创建请求。',
};

export const initializationBlocksClosing = state => ['processing', 'uncertain'].includes(state);
