(function (global) {
  const SIGN_ALGORITHM = 'SHA256withRSA';
  // AutoFirma exige el formato 'Facturae' para evitar SAF_06 (formato no soportado).
  const SIGNATURE_FORMAT = 'Facturae';
  const DETECT_TIMEOUT = 10000;
  const SIGN_TIMEOUT = 90000;
  const AUTO_SCRIPT_SRC = 'public/vendor/clienteafirma/afirma.js';
  let autoScriptLoadPromise = null;
  const HOST_CANDIDATES = Array.isArray(global.AutofirmaHostCandidates) && global.AutofirmaHostCandidates.length
    ? global.AutofirmaHostCandidates.slice()
    : [];
  const primaryHost = typeof global.AutofirmaHostOverride === 'string' && global.AutofirmaHostOverride.trim()
    ? global.AutofirmaHostOverride.trim()
    : '127.0.0.1';
  if (!HOST_CANDIDATES.length) {
    HOST_CANDIDATES.push(primaryHost);
    if (primaryHost !== 'localhost') {
      HOST_CANDIDATES.push('localhost');
    }
  } else if (!HOST_CANDIDATES.includes(primaryHost)) {
    HOST_CANDIDATES.unshift(primaryHost);
  }
  if (!HOST_CANDIDATES.includes('127.0.0.1')) HOST_CANDIDATES.push('127.0.0.1');
  if (!HOST_CANDIDATES.includes('localhost')) HOST_CANDIDATES.push('localhost');
  global.AutofirmaHostCandidates = HOST_CANDIDATES;
  global.AutofirmaHostOverride = primaryHost;
  const servletBase = (function computeServletBase() {
    if (typeof global.AutofirmaServletBase === 'string' && global.AutofirmaServletBase.trim()) {
      return global.AutofirmaServletBase.trim().replace(/\/$/, '');
    }
    try {
      const scriptBase = new URL(AUTO_SCRIPT_SRC, window.location.href);
      const publicDir = new URL('../../', scriptBase);
      return publicDir.toString().replace(/\/$/, '');
    } catch (err) {
      console.warn('[AutofirmaClient] No se pudo deducir la ruta base, se usa el origen', err);
      return window.location.origin ? window.location.origin.replace(/\/$/, '') : '';
    }
  })();
  const storageServletUrl = (function computeStorageUrl() {
    if (typeof global.AutofirmaStorageServlet === 'string' && global.AutofirmaStorageServlet.trim()) {
      return global.AutofirmaStorageServlet.trim();
    }
    return servletBase ? `${servletBase}/afirma-signature-storage/StorageService.php` : null;
  })();
  const retrieverServletUrl = (function computeRetrieverUrl() {
    if (typeof global.AutofirmaRetrieverServlet === 'string' && global.AutofirmaRetrieverServlet.trim()) {
      return global.AutofirmaRetrieverServlet.trim();
    }
    return servletBase ? `${servletBase}/afirma-signature-retriever/RetrieveService.php` : null;
  })();
  console.info('[AutofirmaClient] Host candidates', HOST_CANDIDATES);
  console.info('[AutofirmaClient] Servlet base', servletBase);
  console.info('[AutofirmaClient] Storage servlet', storageServletUrl);
  console.info('[AutofirmaClient] Retriever servlet', retrieverServletUrl);
  if (storageServletUrl) {
    global.AutofirmaStoragePostOverride = storageServletUrl;
    global.AutofirmaStoragePutOverride = storageServletUrl;
    global.AutofirmaStoragePutMainOverride = storageServletUrl;
  }
  if (retrieverServletUrl) {
    global.AutofirmaRetrieverPutOverride = retrieverServletUrl;
  }
  let autoScriptClientReady = false;

  const ERROR_MESSAGES = {
    'autoscript-missing': 'No se pudo cargar el módulo oficial de AutoFirma. Recarga la página.',
    'protocol-unavailable': 'Este navegador no dispone del protocolo afirma://. Abre AutoFirma manualmente o instala el configurador.',
    cancelled: 'Firma cancelada por el usuario.',
    timeout: 'AutoFirma no respondió a tiempo. Comprueba que la aplicación está abierta.',
    'afirma-error': 'AutoFirma devolvió un error inesperado durante la firma.',
    'server-error': 'El servidor no pudo guardar la factura firmada.',
    unknown: 'No se pudo completar la operación con AutoFirma.'
  };

  function messageFor(reason, fallback) {
    if (reason === 'server-error' && fallback) {
      return fallback;
    }
    return ERROR_MESSAGES[reason] || fallback || ERROR_MESSAGES.unknown;
  }

  function ensureXmlString(input) {
    if (typeof input === 'string') {
      return Promise.resolve(input);
    }
    if (input instanceof Blob) {
      return input.text();
    }
    throw new Error('El XML debe ser una cadena o un Blob.');
  }

  function toBase64(str) {
    if (typeof TextEncoder !== 'undefined') {
      const bytes = new TextEncoder().encode(str);
      let binary = '';
      for (let i = 0; i < bytes.length; i++) {
        binary += String.fromCharCode(bytes[i]);
      }
      return btoa(binary);
    }
    return btoa(unescape(encodeURIComponent(str)));
  }

  function pickAppName(options) {
    if (options && options.appName) return options.appName;
    try {
      return window.location && window.location.hostname ? window.location.hostname : null;
    } catch (_) {
      return null;
    }
  }

  function loadAutoScript() {
    if (typeof global.AutoScript !== 'undefined') {
      return Promise.resolve();
    }
    if (!autoScriptLoadPromise) {
      autoScriptLoadPromise = new Promise((resolve, reject) => {
        let resolved = false;
        const script = document.createElement('script');
        script.src = AUTO_SCRIPT_SRC;
        script.async = false;
        script.onload = () => {
          resolved = true;
          if (typeof global.AutoScript === 'undefined') {
            console.warn('[AutofirmaClient] AutoScript cargado pero aún no expuesto. Se reintenta durante el timeout.');
          }
          resolve();
        };
        script.onerror = (event) => {
          const err = new Error(ERROR_MESSAGES['autoscript-missing'] + `. No se pudo cargar ${AUTO_SCRIPT_SRC}.`);
          err.reason = 'autoscript-missing';
          err.detail = 'load-error';
          err.event = event;
          console.error('[AutofirmaClient] Error cargando AutoScript desde', AUTO_SCRIPT_SRC, event);
          reject(err);
        };
        (document.head || document.documentElement).appendChild(script);
        setTimeout(() => {
          if (resolved || typeof global.AutoScript !== 'undefined') {
            return;
          }
          const err = new Error(ERROR_MESSAGES['autoscript-missing'] + ' (timeout cargando script)');
          err.reason = 'autoscript-missing';
          err.detail = 'load-timeout';
          console.error('[AutofirmaClient] Timeout cargando AutoScript. Verifica accesibilidad de', AUTO_SCRIPT_SRC);
          reject(err);
        }, DETECT_TIMEOUT);
      });
    }
    return autoScriptLoadPromise;
  }

  async function waitForAutoScript(timeout) {
    const limit = typeof timeout === 'number' ? timeout : DETECT_TIMEOUT;
    if (typeof global.AutoScript === 'undefined') {
      try {
        await loadAutoScript();
      } catch (err) {
        throw err;
      }
    }
    if (typeof global.AutoScript !== 'undefined') {
      return;
    }
    await new Promise((resolve, reject) => {
      const start = Date.now();
      (function poll() {
        if (typeof global.AutoScript !== 'undefined') {
          resolve();
          return;
        }
        if (Date.now() - start > limit) {
            const err = new Error(ERROR_MESSAGES['autoscript-missing'] + ' (timeout cargando script)');
            err.reason = 'autoscript-missing';
            err.detail = 'load-timeout';
            console.error('[AutofirmaClient] Timeout cargando AutoScript. Verifica accesibilidad de', AUTO_SCRIPT_SRC);
            reject(err);
          }
        setTimeout(poll, 50);
      })();
    });
  }

  function configureProtocol() {
    if (typeof global.AutoScript === 'undefined') {
      return;
    }
    if (typeof global.AutoScript.setForceWSMode === 'function') {
      try { global.AutoScript.setForceWSMode(true); } catch (err) {
        console.warn('[AutofirmaClient] No se pudo activar el modo servidor', err);
      }
    }
    if (typeof global.AutoScript.setServlets === 'function' && storageServletUrl && retrieverServletUrl) {
      try { global.AutoScript.setServlets(storageServletUrl, retrieverServletUrl); } catch (err) {
        console.warn('[AutofirmaClient] No se pudo configurar los servlets de AutoFirma', err);
      }
    }
    if (typeof global.AutoScript.setForceAFirma === 'function') {
      try { global.AutoScript.setForceAFirma(true); } catch (_) { /* ignore */ }
    }
  }

  async function ensureClientReady(options) {
    await waitForAutoScript(options && options.timeout);

    if (typeof global.AutoScript === 'undefined') {
      const err = new Error(ERROR_MESSAGES['autoscript-missing']);
      err.reason = 'autoscript-missing';
      throw err;
    }

    // Es imprescindible forzar el modo servidor antes de inicializar el cliente.
    configureProtocol();

    if (autoScriptClientReady) {
      return;
    }

    const initClient = global.AutoScript.cargarAppAfirma || global.AutoScript.cargarMiniApplet;

    if (typeof initClient === 'function') {
      try {
        const clientAddress = options && options.clientAddress ? options.clientAddress : null;
        const keystore = options && options.keystore ? options.keystore : null;
        initClient.call(global.AutoScript, clientAddress, keystore);
        const appName = pickAppName(options);
        if (appName && typeof global.AutoScript.setAppName === 'function') {
          try { global.AutoScript.setAppName(appName); } catch (setErr) {
            console.warn('[AutofirmaClient] No se pudo establecer el nombre de la aplicación', setErr);
          }
        }
        autoScriptClientReady = true;
      } catch (err) {
        console.error('[AutofirmaClient] Error inicializando AutoScript', err);
        const initError = new Error(ERROR_MESSAGES['autoscript-missing'] + ' (no se pudo inicializar AutoScript)');
        initError.reason = 'autoscript-init';
        initError.cause = err;
        throw initError;
      }
    } else {
      autoScriptClientReady = true;
    }
  }

  function buildExtraParams(filename) {
    const params = [
      'mode=implicit',
      'ignoreSignersCertificateChainValidation=true',
      'signingPurpose=facturae'
    ];
    return params.join('\n');
  }

  function normalizeMessage(msg) {
    if (!msg) return null;
    const trimmed = String(msg).trim();
    return trimmed.length ? trimmed : null;
  }

  function mapSignError(errorType, errorMessage) {
    const lowerType = String(errorType || '').toLowerCase();
    const lowerMessage = String(errorMessage || '').toLowerCase();
    const normalizedMessage = normalizeMessage(errorMessage);

    console.error('[AutofirmaClient] Error devuelto por AutoFirma', {
      errorType,
      errorMessage: normalizedMessage || errorMessage || null
    });

    if (lowerType.includes('applicationnotfound') || lowerType.includes('afirmaeasyprocessnotfound') || lowerMessage.includes('protocolo')) {
      return { reason: 'protocol-unavailable', message: ERROR_MESSAGES['protocol-unavailable'], rawType: errorType, rawMessage: normalizedMessage };
    }
    if (lowerType.includes('cancel') || lowerMessage.includes('cancel')) {
      return { reason: 'cancelled', message: normalizedMessage || ERROR_MESSAGES.cancelled, rawType: errorType, rawMessage: normalizedMessage };
    }
    if (lowerType.includes('socket') || lowerMessage.includes('socket')) {
      return { reason: 'protocol-unavailable', message: ERROR_MESSAGES['protocol-unavailable'], rawType: errorType, rawMessage: normalizedMessage };
    }

    let message = normalizedMessage || ERROR_MESSAGES['afirma-error'];
    if (!normalizedMessage && normalizeMessage(errorType)) {
      message += ` (código: ${errorType})`;
    }

    return { reason: 'afirma-error', message, rawType: errorType, rawMessage: normalizedMessage };
  }

  async function saveSignedFactura(invoiceId, signedB64) {
    if (!invoiceId) {
      throw Object.assign(new Error('No se ha proporcionado el identificador de la factura.'), { reason: 'server-error' });
    }
    const formData = new FormData();
    formData.set('action', 'save_signed_facturae');
    formData.set('id', invoiceId);
    formData.set('signedXml', signedB64);

    const response = await fetch('index.php', {
      method: 'POST',
      body: formData,
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin'
    });

    let data;
    try {
      data = await response.json();
    } catch (err) {
      throw Object.assign(new Error('La respuesta del servidor no es válida tras guardar la firma.'), { reason: 'server-error' });
    }

    if (!response.ok || !data || data.success !== true) {
      throw Object.assign(new Error((data && data.message) || ERROR_MESSAGES['server-error']), { reason: 'server-error' });
    }

    return data;
  }

  async function detect(options) {
    const detectOptions = Object.assign({}, options);
    if (!detectOptions.clientAddress && servletBase) {
      detectOptions.clientAddress = servletBase;
    }
    try {
      await ensureClientReady(detectOptions);
    } catch (err) {
      console.error('[AutofirmaClient] detect -> ensureClientReady error', err);
      return { ok: false, mode: 'protocol', reason: err.reason || 'autoscript-missing', message: err.message };
    }

    configureProtocol();
    const result = { ok: true, mode: 'server', storage: storageServletUrl, retriever: retrieverServletUrl };
    console.info('[AutofirmaClient] detect ->', result);
    return result;
  }

  async function signFacturaeXml(xmlInput, filenameOrOptions, maybeOptions) {
    const xmlString = await ensureXmlString(xmlInput);
    const options = typeof maybeOptions === 'object' && maybeOptions !== null ? maybeOptions : {};
    let filename = null;
    if (typeof filenameOrOptions === 'string') {
      filename = filenameOrOptions;
    } else if (filenameOrOptions && typeof filenameOrOptions === 'object') {
      Object.assign(options, filenameOrOptions);
      filename = options.filename || null;
    }

    const invoiceId = options.invoiceId || (filename ? filename.replace(/\.xsig$/i, '') : null);

    const availability = await detect({
      timeout: options.timeout || DETECT_TIMEOUT,
      clientAddress: options.clientAddress,
      keystore: options.keystore,
      appName: options.appName
    });
    if (!availability.ok) {
      const err = new Error(availability.message || ERROR_MESSAGES['protocol-unavailable']);
      err.reason = availability.reason || 'protocol-unavailable';
      throw err;
    }

    await ensureClientReady(options);
    configureProtocol();

    return new Promise((resolve, reject) => {
      let settled = false;
      const timer = setTimeout(() => {
        if (!settled) {
          settled = true;
          const err = new Error(ERROR_MESSAGES.timeout);
          err.reason = 'timeout';
          reject(err);
        }
      }, SIGN_TIMEOUT);

      const onSuccess = async (signedB64) => {
        console.info('[AutofirmaClient] Firma recibida', {
          length: signedB64 ? signedB64.length : 0,
          suffix: signedB64 ? signedB64.slice(-32) : null
        });
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        try {
          const saveResponse = await saveSignedFactura(invoiceId, signedB64);
          resolve({ signedB64, saveResponse });
        } catch (err) {
          reject(err);
        }
      };

      const onError = (errorType, errorMessage) => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        const mapped = mapSignError(errorType, errorMessage);
        const err = new Error(mapped.message);
        err.reason = mapped.reason;
        if (mapped.rawType) err.afirmaType = mapped.rawType;
        if (mapped.rawMessage) err.afirmaMessage = mapped.rawMessage;
        reject(err);
      };

      try {
        const extraParams = buildExtraParams(filename);
        global.AutoScript.sign(
          toBase64(xmlString),
          SIGN_ALGORITHM,
          SIGNATURE_FORMAT,
          extraParams,
          onSuccess,
          onError
        );
      } catch (err) {
        console.error('[AutofirmaClient] Excepción directa al invocar AutoScript.sign', err);
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        err.reason = err.reason || 'afirma-error';
        reject(err);
      }
    });
  }

  const AutofirmaClient = {
    async detect(options) {
      return detect(options);
    },
    async signFacturaeXml(xmlInput, filename, options) {
      return signFacturaeXml(xmlInput, filename, options);
    },
    messageFor,
    humanHelp() {
      return [
        'Asegúrate de que AutoFirma está instalada y abierta.',
        'Si es la primera vez, autoriza este dominio cuando AutoFirma lo solicite.',
        'Solo instala el configurador/extensión si el protocolo afirma:// no se abre.'
      ];
    }
  };

  global.AutofirmaClient = AutofirmaClient;
})(window);
