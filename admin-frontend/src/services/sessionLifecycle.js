// Events contain no credentials. Cookies are shared by same-origin documents,
// while each document otherwise keeps its own pending edits and role snapshot.
export function adminContextChanged(response, expectedContext = '') {
  if (response?.status !== 409) return false;
  // CSV downloads receive JSON errors as Blobs. The server also supplies the
  // current context in a header, so stale downloads can clear the old page too.
  const current = response.headers?.['x-admin-context'];
  return response.data?.code === 'admin_context_changed' ||
    Boolean(expectedContext && current && current !== expectedContext);
}

export function createAdminSessionLifecycle(target = globalThis.window) {
  let epoch = 0;
  const cleanups = new Set();
  const received = new Set();
  const key = 'cardshop-admin-session-change';
  const channel = target?.BroadcastChannel ? new target.BroadcastChannel(key) : null;
  const notify = remote => {
    epoch += 1;
    cleanups.forEach(cleanup => cleanup({ remote }));
  };
  const receive = message => {
    if (!message || typeof message.nonce !== 'string' || received.has(message.nonce)) return;
    received.add(message.nonce);
    if (received.size > 40) received.delete(received.values().next().value);
    notify(true);
  };
  const onStorage = event => {
    if (event.key !== key || !event.newValue) return;
    try { receive(JSON.parse(event.newValue)); } catch {}
  };
  const onFocus = () => {
    try { const saved = target.localStorage.getItem(key); if (saved) receive(JSON.parse(saved)); } catch {}
  };
  if (channel) channel.onmessage = event => receive(event.data);
  target?.addEventListener('storage', onStorage);
  target?.addEventListener('focus', onFocus);
  try { const saved = JSON.parse(target?.localStorage.getItem(key) || 'null'); if (saved?.nonce) received.add(saved.nonce); } catch {}
  return {
    epoch: () => epoch,
    subscribe(cleanup) { cleanups.add(cleanup); return () => cleanups.delete(cleanup); },
    reset({ broadcast = true } = {}) {
      notify(false);
      if (!broadcast) return;
      const message = { nonce: target?.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}` };
      received.add(message.nonce);
      try { target?.localStorage.setItem(key, JSON.stringify(message)); } catch {}
      channel?.postMessage(message);
    },
    dispose() {
      channel?.close();
      target?.removeEventListener('storage', onStorage);
      target?.removeEventListener('focus', onFocus);
      cleanups.clear();
    },
  };
}

const lifecycle = createAdminSessionLifecycle();
export const adminSessionEpoch = lifecycle.epoch;
export const onAdminSessionChange = lifecycle.subscribe;
export const resetAdminSession = lifecycle.reset;
