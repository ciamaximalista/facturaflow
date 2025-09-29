/**
 * FacturaFlow AutoFirma Integration
 * 
 * Contiene la lógica para interactuar con la librería AutoFirma.js
 * y el backend de FacturaFlow.
 */

window.FacturaFlowSigner = {

    _waitForAutoScript: function(callback) {
        if (typeof AutoScript !== 'undefined') {
            callback();
        } else {
            setTimeout(() => this._waitForAutoScript(callback), 100);
        }
    },

    /**
     * Comprueba si AutoFirma está instalado y operativo.
     * @param {function} onSuccess Callback si AutoFirma está listo.
     * @param {function} onError Callback si hay un error.
     */
    check: function(onSuccess, onError) {
        this._waitForAutoScript(() => {
            AutoScript.check(onSuccess, onError);
        });
    },

    /**
     * Firma una factura.
     * @param {string} invoiceId El ID de la factura a firmar.
     * @param {function} onSuccess Callback con la respuesta del servidor tras enviar la firma.
     * @param {function} onError Callback con el mensaje de error.
     */
    signInvoice: function(invoiceId, onSuccess, onError) {
        console.log(`Iniciando firma para factura: ${invoiceId}`);

        // 1. Obtener el XML sin firmar del backend
        this.getUnsignedXml(invoiceId, (unsignedXml) => {
            
            // 2. Llamar a AutoFirma para firmar el XML
            this.sign(unsignedXml, 
                (signedXmlB64) => {
                    // 3. Enviar el XML firmado de vuelta al backend
                    console.log('Firma completada, enviando al servidor...');
                    this.saveSignedXml(invoiceId, signedXmlB64, onSuccess, onError);
                },
                onError
            );

        }, onError);
    },

    /**
     * Firma datos genéricos usando AutoFirma.
     * @param {string} dataToSign Datos a firmar (XML, texto, etc.).
     * @param {function} onSuccess Callback con los datos firmados en Base64.
     * @param {function} onError Callback con el mensaje de error.
     */
    sign: function(dataToSign, onSuccess, onError) {
        this._waitForAutoScript(() => {
            console.log('Iniciando firma genérica con AutoFirma.');
            AutoScript.sign(
                btoa(dataToSign),
                'SHA256withRSA',
                // AutoFirma espera formato 'Facturae' para firmar Facturae (SAF_06 si no).
                'Facturae',
                null,
                (signedB64) => {
                    console.log('Firma genérica completada.');
                    if (onSuccess) onSuccess(signedB64);
                },
                (errorType, errorMessage) => {
                    console.error(`Error de AutoFirma: ${errorType} - ${errorMessage}`);
                    if (onError) onError(`Error de AutoFirma: ${errorMessage}`);
                }
            );
        });
    },

    /**
     * Obtiene el XML de la factura sin firmar desde el backend.
     * @private
     */
    getUnsignedXml: function(invoiceId, onSuccess, onError) {
        const formData = new FormData();
        formData.append('action', 'get_unsigned_facturae');
        formData.append('id', invoiceId);

        fetch('index.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Error de red o del servidor.');
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.xml) {
                onSuccess(data.xml);
            } else {
                if (onError) onError(data.message || 'No se pudo obtener el XML sin firmar.');
            }
        })
        .catch(error => {
            console.error('Error fetching unsigned XML:', error);
            if (onError) onError('No se pudo comunicar con el servidor para obtener la factura.');
        });
    },

    /**
     * Guarda el XML firmado en el servidor.
     * @private
     */
    saveSignedXml: function(invoiceId, signedXmlB64, onSuccess, onError) {
        const formData = new FormData();
        formData.append('action', 'save_signed_facturae');
        formData.append('id', invoiceId);
        formData.append('signedXml', signedXmlB64);

        fetch('index.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (onSuccess) onSuccess(data);
            } else {
                if (onError) onError(data.message || 'No se pudo guardar la factura firmada.');
            }
        })
        .catch(error => {
            console.error('Error saving signed XML:', error);
            if (onError) onError('No se pudo comunicar con el servidor para guardar la factura firmada.');
        });
    }
};
