// One writer survives route changes. All flushes, including unmount and test-mail
// saves, share the same promise so an older request cannot overwrite newer values.
export function createSerialSaveQueue(save, delay = 800) {
  let queued = {};
  let active = {};
  let running = null;
  let timer;
  let state = { status: 'idle', error: '' };
  const listeners = new Set();
  const pending = () => Boolean(running) || Object.keys(queued).length > 0;
  const emit = (next) => { state = next; listeners.forEach((listener) => listener(next)); };

  function flush() {
    clearTimeout(timer);
    if (running) return running;
    if (!Object.keys(queued).length) return Promise.resolve();
    emit({ status: 'saving', error: '' });
    // Start on a microtask, after assigning running, even if save throws immediately.
    running = Promise.resolve().then(async () => {
      while (Object.keys(queued).length) {
        active = queued;
        queued = {};
        try { await save(active); }
        catch (error) {
          queued = { ...active, ...queued };
          emit({ status: 'error', error: error.response?.data?.message || '保存失败，请检查网络或重新登录' });
          throw error;
        } finally { active = {}; }
      }
      emit({ status: 'saved', error: '' });
    }).finally(() => { running = null; });
    return running;
  }

  return {
    enqueue(changes, schedule = true) {
      queued = { ...queued, ...changes };
      emit({ status: 'saving', error: '' });
      clearTimeout(timer);
      if (schedule) timer = setTimeout(() => flush().catch(() => {}), delay);
    },
    flush, pending,
    unsaved: () => ({ ...active, ...queued }),
    subscribe(listener) { listeners.add(listener); listener(state); return () => listeners.delete(listener); },
  };
}
