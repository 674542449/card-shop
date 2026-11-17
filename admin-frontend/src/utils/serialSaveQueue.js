// One writer survives route changes. All flushes, including unmount and test-mail
// saves, share the same promise so an older request cannot overwrite newer values.
export function createSerialSaveQueue(save, delay = 800) {
  let queued = {};
  let active = {};
  let running = null;
  let timer;
  let generation = 0;
  let controller;
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
    const startedGeneration = generation;
    const task = Promise.resolve().then(async () => {
      while (startedGeneration === generation && Object.keys(queued).length) {
        active = queued;
        queued = {};
        controller = new AbortController();
        try { await save(active, { signal: controller.signal }); }
        catch (error) {
          // A logout/login boundary must not put an old account's credentials
          // back into the new account's queue, even if cancellation arrived late.
          if (startedGeneration !== generation) throw error;
          queued = { ...active, ...queued };
          emit({ status: 'error', error: error.response?.data?.message || '保存失败，请检查网络或重新登录' });
          throw error;
        } finally {
          if (startedGeneration === generation) { active = {}; controller = null; }
        }
      }
      if (startedGeneration === generation) emit({ status: 'saved', error: '' });
    }).finally(() => { if (running === task) running = null; });
    running = task;
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
    reset() {
      generation += 1;
      clearTimeout(timer);
      controller?.abort();
      controller = null;
      queued = {};
      active = {};
      running = null;
      emit({ status: 'idle', error: '' });
    },
    unsaved: () => ({ ...active, ...queued }),
    subscribe(listener) { listeners.add(listener); listener(state); return () => listeners.delete(listener); },
  };
}
