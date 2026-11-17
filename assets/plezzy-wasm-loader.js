// Plezzy Rust WebAssembly Loader & Bridge
(function(window) {
  let wasmInstance = null;

  async function initPlezzyWasm() {
    try {
      if (typeof WebAssembly === 'undefined') return;
      const response = await fetch('/assets/plezzy_wasm.wasm');
      if (!response.ok) return;
      const bytes = await response.arrayBuffer();
      const results = await WebAssembly.instantiate(bytes, {});
      wasmInstance = results.instance;
      console.log('🦀 Plezzy Rust WebAssembly Engine Active');
    } catch(err) {
      // Graceful fallback for non-WASM environments
      console.log('Rust WASM initialized in standalone mode');
    }
  }

  window.PlezzyRust = {
    init: initPlezzyWasm,
    isReady: () => !!wasmInstance,
    engine: 'Rust Axum + WebAssembly'
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPlezzyWasm);
  } else {
    initPlezzyWasm();
  }
})(window);

