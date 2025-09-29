# FacturaFlow
  
  Para usar FacturaFlow es necesario:
  
  - Estar registrado en FACeB2B como “cliente” (unidad receptora) y como ESF (Empresa de Servicios de Facturación) para enviar por FACeB2B.
  - Estar registrado en FACe (FACe “proveedor”) si vas a enviar Facturae a Administraciones vía FACe.
  
  FacturaFlow firma las Facturae con el certificado (P12/PFX) del usuario (emisor) y, para el envío a FACeB2B, utiliza además un certificado de plataforma (P12) de la ESF para la
  autenticación WS‑Security.
  
  ## Requisitos
  
  - PHP 8.1+ con extensiones: openssl, curl, soap, json, dom, libxml, mbstring, zip
  - Composer
  - Servidor web (Apache/Nginx) ejecutando como www-data (u otro usuario conocido)
  - Git
  
  ## Instalación (plataforma/ESF)
  
  1. Crear la carpeta “cifra” (plataforma) fuera del alcance del servidor web
  
  Por seguridad, la carpeta de plataforma debe quedar fuera del DocumentRoot. Un ejemplo:
  
  - Carpeta plataforma (fuera de web): /var/www/cifra
  - Sitio(s) web: /var/www/html/...
  
  Crea y prepara la carpeta:
  sudo mkdir -p /var/www/cifra
  sudo chown -R root:www-data /var/www/cifra
  sudo find /var/www/cifra -type d -exec chmod 750 {} \;
  sudo find /var/www/cifra -type f -exec chmod 640 {} \;
  2. Coloca el P12 de la plataforma (ESF) y la clave
  
  - Copia el certificado P12 de plataforma (ESF) a /var/www/cifra/max.p12
  - Si tu contraseña está “en claro”, puedes colocarla en uno de estos ficheros (se leerá por orden):
  /var/www/cifra/max.pass, /var/www/cifra/faceb2b.pass, /var/www/cifra/p12.pass
  (Formato: texto simple, solo la contraseña)
  - Si tu contraseña está cifrada, no uses .pass; en su lugar usa p12_pass en faceb2b.json (ver abajo) con formato enc:v1:... y pasa al punto 3.
  
  3. Genera la clave de cifrado de plataforma (si usarás enc:v1:)
  
  Si vas a almacenar la contraseña cifrada en JSON (recomendado), necesitas una clave simétrica en la carpeta de plataforma:
  # Genera una clave (32–64 bytes). Puedes usar openssl o dd.
  sudo bash -c 'openssl rand -base64 48 > /var/www/cifra/secret.key'
  sudo chown root:www-data /var/www/cifra/secret.key
  sudo chmod 640 /var/www/cifra/secret.key
  Notas:
  
  - El backend descifra automáticamente enc:v1:... usando /var/www/cifra/secret.key.
  - Para cifrar tu pass con ese esquema, puedes usar una utilidad temporal o pedir a FacturaFlow que la guarde cifrada desde el área de configuración.
  
  4. (Opcional) Crea el JSON de configuración de plataforma REST/SOAP
  
  Archivo /var/www/cifra/faceb2b.json (ejemplo mínimo):
  {
    "p12_path": "/var/www/cifra/max.p12",
    "p12_pass": "enc:v1:...clave_cifrada...",
    "rest_base": "https://api.face.gob.es/faceb2b",
    "timeout": 30
  }
  Si usas p12_pass en claro (no recomendado) puedes ponerla como texto. Si no hay p12_pass aquí, y no hay .pass en la carpeta, el envío SOAP fallará.
  
  ## Instalación (instancias de usuario)
  
  La app está pensada para ser multiusuario a nivel de instancias: basta una carpeta por usuario bajo la raíz web, cada una clonando el repositorio. La “plataforma” (cifra) es común.
  
  1. Clonar el repositorio por usuario
  
  Ejemplo de usuario acme:
  sudo mkdir -p /var/www/html/users/acme
  cd /var/www/html/users/acme
  sudo git clone https://github.com/ciamaximalista/facturaflow .    # clona aquí
  sudo chown -R www-data:www-data /var/www/html/users/acme
  2. Instalar dependencias con Composer:
  cd /var/www/html/users/acme
  sudo -u www-data composer install --no-dev --prefer-dist
  3. Configurar la ruta “cifra” en cada instancia
  
  Crea config_plataforma.json junto a index.php de la instancia. Este archivo apunta a la carpeta “cifra” (plataforma) con una ruta relativa (o absoluta). Por ejemplo, si:
  
  - cifra = /var/www/cifra
  - instancia = /var/www/html/users/acme
  
  Entonces, desde /var/www/html/users/acme a /var/www/cifra la ruta relativa es ../../cifra:
  {
    "cifra_dir": "../../cifra"
  }
  En el caso de tu estructura, si la app está en /var/www/html/ruralnext/facturaflow/repoblacion y la “cifra” en /var/www/cifra, la ruta relativa sería:
  {
    "cifra_dir": "../../../../cifra"
  }
  4. Preparar permisos en carpeta data/ de cada instancia
  cd /var/www/html/users/acme
  sudo mkdir -p data
  sudo chown -R www-data:www-data data
  sudo find data -type d -exec chmod 750 {} \;
  sudo find data -type f -exec chmod 640 {} \;
  5. Configurar virtual host (Apache/Nginx)
  
  - Apunta el DocumentRoot a la carpeta de la instancia (p. ej. /var/www/html/users/acme).
  - Habilita PHP y las extensiones necesarias.
  - Reinicia/reload del servidor.
  
  6. Registro y alta del usuario emisor
  
  - Accede con el navegador a la URL de la instancia (p. ej. https://tu.dominio/users/acme).
  - La primera vez, el sistema te guía por el registro del usuario emisor (datos fiscales, P12 del usuario y su contraseña).
  - El certificado del usuario firmará las Facturae y el QR Veri*Factu.
  
  ## Resumen de funcionalidades
  
  - Emisión de facturas:
      - Alta de clientes y productos
      - Creación de facturas (con IRPF/suplidos si aplica)
      - Rectificación (automática de importes)
      - Generación PDF/Impresión
      - Exportación Facturae (.xsig) firmada con el P12 del usuario
  - FACeB2B:
      - Generación Facturae y envío (WS-Security) usando certificado de plataforma (ESF)
      - Consulta de estados/actualización
  - FACe (opcional):
      - Integración para envío a FACe con certificado del emisor (si se usa ese canal)
  - Recibidas:
      - Sincronización desde FACeB2B (descarga de Facturae)
      - Índice local y visor (concepto, totales, QR, etc.)
      - Marcado de estados (aceptada, rechazada, pagada)
  - Veri*Factu (AEAT):
      - Envío de registro (si habilitado)
      - Cadena hash y QR integrado
  - Panel/Dashboard:
      - Filtros por periodo, cliente, producto (emitidas)
      - Filtros por periodo y proveedor (recibidas)
      - Totales y exportación de Facturae desde la vista de factura
  - Mis Datos:
      - Ajustes del emisor (certificado del usuario incluido)
      - Configuración FACeB2B (certificado de plataforma, tokens REST, etc.)
  
  ## Guía de uso rápida
  
  1. Preparar la plataforma (ESF)
  
  - Crea /var/www/cifra
  - Coloca max.p12 y secret.key (y opcionalmente faceb2b.json con p12_pass cifrada o .pass en texto).
  - Asegura permisos para www-data:
    sudo chown -R root:www-data /var/www/cifra
    sudo find /var/www/cifra -type d -exec chmod 750 {} \;
    sudo find /var/www/cifra -type f -exec chmod 640 {} \;
  2. Desplegar instancias de usuario
  
  - Clona el repo en /var/www/html/users/<usuario>, corre composer install.
  - Crea config_plataforma.json con la ruta a “cifra”.
  - Abre la URL de esa instancia y completa el registro del emisor (sube el P12 del usuario y su contraseña).
  
  3. Emitir y enviar una factura

  - Crea clientes y productos (si no los tienes).
  - Crea una nueva factura (puedes añadir IRPF y suplidos).
  - Exporta Facturae (.xsig) para comprobar firma del usuario (opcional).
  - Envía a FACeB2B (requiere tener configurada la plataforma y permisos sobre /var/www/cifra).

## Firma distribuida con AutoFirma

```
┌────────────┐       1. Solicita XML en claro         ┌────────────────────┐
│  Navegador │ ─────────────────────────────────────▶ │  FacturaFlow (PHP) │
└─────┬──────┘                                         └─────────┬──────────┘
      │ 2. XML Facturae (JSON + Base64)                           │
      │ ◀─────────────────────────────────────────────────────────┘
      │
      │ 3. Firma local con AutoFirma (XAdES) usando el cert del usuario
      ▼
┌────────────────────┐
│ AutoFirma (cliente)│
└─────┬──────────────┘
      │ 4. Facturae firmada (Base64)
      ▼
┌────────────────────┐       5. Guarda .xsig, registra metadatos      ┌────────────────────┐
│  Navegador         │ ──────────────────────────────────────────────▶│  FacturaFlow (PHP) │
└────────────────────┘                                                └───┬────────────────┘
                                                                          │
 6. Envíos FACe/FACeB2B/AEAT usan siempre el certificado de plataforma ───┘
```

### Flujo paso a paso

1. El usuario pulsa **Firmar con AutoFirma** desde la ficha de la factura.
2. El navegador llama a `POST index.php?action=get_unsigned_facturae`, que genera un Facturae sin firma usando los datos persistidos.
3. El XML se pasa a AutoFirma (cliente @firma) en el equipo del usuario; se firma con su certificado personal.
4. El navegador envía el XML firmado (`save_signed_facturae`) y el servidor lo guarda en `data/facturae_exports/` y lo asocia a la factura.
5. Las integraciones con FACeB2B, FACe y AEAT reutilizan ese `.xsig` firmado por el usuario, mientras que el canal se autentica con el certificado de plataforma (`/var/www/cifra/max.p12`).

### Separación de certificados

- **Firma de la factura (.xsig)**: siempre se realiza en el equipo del usuario con AutoFirma y su certificado personal. El servidor nunca recibe el P12 ni la contraseña.
- **Firma/autenticación del canal (FACeB2B, FACe, AEAT)**: se ejecuta en el servidor con el certificado de la plataforma (`max.p12`), descifrado con `secret.key` bajo `/var/www/cifra`.

### Puntos de seguridad

- Eliminada la subida de certificados P12 de los usuarios; cualquier configuración previa se ignora.
- Los `.xsig` firmados se guardan con metadatos (hash SHA-256 y fecha) para trazabilidad.
- Se mantienen controles CSRF (formularios con `action`) y sanitización de entradas al generar el XML.
- AutoFirma se detecta desde el navegador; el botón de envío queda deshabilitado si la factura no está firmada.
- Las rutas sensibles (plataforma) se resuelven mediante `config_plataforma.json`, sin exponer credenciales en el `DocumentRoot`.
- FACe/FACeB2B/AEAT jamás vuelven a cargar un XML si no contiene `<Signature>` válida.

<a id="guia-integracion-autofirma"></a>
### Guía de integración y solución de problemas (AutoFirma)

FacturaFlow integra AutoFirma utilizando la librería oficial (`public/vendor/clienteafirma/afirma.js`) y el protocolo `afirma://`. No se realizan peticiones REST a `127.0.0.1`; la comunicación se produce directamente con la aplicación AutoFirma instalada.

**Preparación por sistema operativo**
- **Windows**: instala AutoFirma desde la web del Ministerio, ábrela al menos una vez y autoriza la aplicación en el firewall cuando aparezca el aviso.
- **macOS**: instala AutoFirma, ábrela desde Aplicaciones (puede pedir permiso en “Seguridad y privacidad”), y mantiene la app en ejecución durante la firma.
- **Linux**: instala AutoFirma (`.deb`/`.rpm` o paquete oficial), ejecuta `AutoFirma` o `/usr/lib/autofirma/AutoFirma` y revisa que Java esté presente.

**Si el navegador no reconoce el protocolo**
1. Abre AutoFirma manualmente y vuelve a pulsar **Firmar con AutoFirma**.
2. Si persiste, instala el **Configurador AutoFirma** desde la web oficial (<https://firmaelectronica.gob.es/Home/Descargas.html>) y reinicia el navegador.
3. Tras instalarlo, autoriza el dominio de tu instancia cuando AutoFirma lo solicite y deja la aplicación abierta en segundo plano.

**Comprobaciones rápidas cuando algo falla**
- ¿AutoFirma está en ejecución? Si no, ábrela manualmente y vuelve a intentar.
- ¿Aparece un diálogo de autorización de dominio? Acepta y marca “recordar” si está disponible.
- ¿El navegador solicita instalar el configurador/extensión? Instálalo solo si el protocolo no se abre tras iniciar AutoFirma.
- ¿Se cancela la firma? Repite el proceso y comprueba que AutoFirma no muestre avisos bloqueantes.

**Diagnóstico adicional**
- Si AutoFirma no arranca tras pulsar firmar, abre la aplicación de forma manual y repite. En Linux puedes revisar `~/.autofirma/AutoFirma.log`.
- Si usas proxys o productos de seguridad corporativos, asegúrate de que no bloqueen el protocolo `afirma://` ni la aplicación AutoFirma.
- Para reinicializar permisos, cierra AutoFirma, bórrala de procesos residuales y vuelve a autorizar el dominio cuando se solicite.

**Separación de certificados garantizada**
- La factura (`.xsig`) se firma siempre en el equipo del usuario con su certificado personal mediante AutoFirma.
- El “sobre” de comunicación con FACe, FACeB2B y AEAT continúa firmándose en el servidor con el certificado de plataforma ubicado en `/var/www/cifra`.

  4. Recibir y gestionar
  
  - Sincroniza FACeB2B para traer nuevas facturas recibidas.
  - Gestiona estados (aceptada/rechazada/pagada), imprime, etc.
  
  ## Seguridad y buenas prácticas
  
  - Mantén /var/www/cifra fuera del DocumentRoot.
  - Asigna permisos mínimos necesarios, y propietario root:www-data.
  - Usa enc:v1: para p12_pass de plataforma cuando sea posible, con /var/www/cifra/secret.key.
  - Los datos del usuario (emisor) viven en data/ dentro de la instancia: aplica permisos 750/640 y propietario www-data.
  
  ## Solución de problemas
  
  - “El XML Facturae se generó sin firma. Revisa el P12 y su contraseña.”
      - Revisa que el P12 del usuario y su contraseña (en Mis Datos) sean correctos.
      - Si acabas de cambiar SecureConfig, asegúrate de que data/secret.key existe y tiene lectura para www-data.
  - Envío a FACeB2B fallido
      - Confirma que /var/www/cifra/max.p12 existe y es legible por www-data.
      - Si la pass va cifrada, asegurarte de que /var/www/cifra/secret.key es legible y que faceb2b.json contiene p12_pass con enc:v1:....
      - Revisa data/logs/faceb2b.log para más detalle.
  - Permisos
      - sudo -u www-data test -r /var/www/cifra/max.p12 && echo OK || echo FAIL
      - Ajusta permisos como se recomienda en la sección de instalación.
  
  ## Multiusuario
  
  - Crea una carpeta por usuario bajo la raíz de tu web y repite:
      - Clonar repo, composer install.
      - Añadir config_plataforma.json propio (apunta a la misma “cifra” de plataforma).
      - Cada instancia tiene su data/ propia, y su panel en su URL.
  - Ventaja: cada usuario se registra y sube su P12 de emisor sin interferir con los demás, compartiendo la “plataforma” FACeB2B.
  
  ## Despliegue/Actualizaciones
  
  - Para actualizar una instancia:
    cd /var/www/html/users/<usuario>
    sudo -u www-data git pull
    sudo -u www-data composer install --no-dev --prefer-dist
  - Asegúrate de no sobrescribir data/ ni config_plataforma.json.
  
  - Repositorio: https://github.com/ciamaximalista/facturaflow
  - Incidencias y PRs son bienvenidos.
